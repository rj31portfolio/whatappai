<?php

namespace App\Jobs;

use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Billing;
use App\Services\Integrations\ProviderHttp;
use App\Services\WorkflowEngine;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        Cache::lock('webhook:'.$this->eventId, 120)->block(5, function () {
            $event = WebhookEvent::findOrFail($this->eventId);
            if ($event->processed_at) {
                return;
            }
            $payload = $event->payload['event'];
            if ($event->provider === 'razorpay') {
                $id = $payload['payload']['subscription']['entity']['id'] ?? $payload['payload']['invoice']['entity']['subscription_id'] ?? null;
                $subscription = $id ? Subscription::withoutGlobalScope('tenant')->where('provider_id', $id)->first() : null;
                if ($subscription) {
                    $tenant = Tenant::findOrFail($subscription->tenant_id);
                    app(TenantContext::class)->run($tenant, function () use ($subscription, $payload) {
                        app(Billing::class)->sync($subscription);
                        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;
                        if ($paymentId) {
                            $payment = app(Billing::class)->request('GET', 'payments/'.$paymentId);
                            if (in_array($payment['status'] ?? '', ['captured', 'failed', 'refunded'], true)) {
                                Payment::updateOrCreate(['provider_id' => $payment['id']], ['subscription_provider_id' => $subscription->provider_id, 'amount_paise' => $payment['amount'], 'currency' => $payment['currency'], 'status' => $payment['status'], 'invoice_id' => $payment['invoice_id'] ?? null]);
                            }
                        }
                    });
                    $event->tenant_id = $tenant->id;
                }
            } else {
                $tenant = Tenant::find($event->tenant_id);
                if ($tenant) {
                    app(TenantContext::class)->run($tenant, fn () => $event->provider === 'whatsapp' ? $this->whatsapp($payload, $event) : $this->email($payload, $event));
                }
            }
            $event->processed_at = now();
            $event->error = null;
            $event->save();
        });
    }

    private function whatsapp(array $payload, WebhookEvent $event): void
    {
        $integration = Integration::findOrFail($event->payload['integration_id']);
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($value['metadata']['phone_number_id'] ?? '') !== ($integration->config['phone_number_id'] ?? '')) {
                    continue;
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (in_array($status['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true)) {
                        $message = Message::where('provider_id', $status['id'])->first();
                        $rank = ['queued' => 0, 'sending' => 1, 'unknown' => 1, 'accepted' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];
                        if ($message && ($rank[$status['status']] ?? 0) >= ($rank[$message->status] ?? 0)) {
                            $message->update(['status' => $status['status']]);
                        }
                    }
                }
                foreach ($value['messages'] ?? [] as $incoming) {
                    $phone = '+'.ltrim((string) ($incoming['from'] ?? ''), '+');
                    if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) || empty($incoming['id'])) {
                        continue;
                    }
                    DB::transaction(function () use ($incoming, $phone, $value) {
                        $contact = Contact::firstOrCreate(['phone' => $phone], ['name' => mb_substr($value['contacts'][0]['profile']['name'] ?? $phone, 0, 160), 'source' => 'whatsapp', 'score' => 'warm', 'score_reason' => 'Inbound WhatsApp contact.']);
                        $text = $incoming['text']['body'] ?? '[Non-text WhatsApp message: '.($incoming['type'] ?? 'unknown').']';
                        $at = Carbon::createFromTimestampUTC((int) ($incoming['timestamp'] ?? time()));
                        $conversation = Conversation::firstOrCreate(['contact_id' => $contact->id, 'channel' => 'whatsapp']);
                        $message = Message::firstOrCreate(['provider_id' => $incoming['id']], ['conversation_id' => $conversation->id, 'idempotency_key' => (string) Str::uuid(), 'direction' => 'inbound', 'body' => $text, 'status' => 'received', 'sent_at' => $at]);
                        if ($message->wasRecentlyCreated) {
                            if (! $conversation->last_inbound_at || $at->gt($conversation->last_inbound_at)) {
                                $conversation->update(['last_inbound_at' => $at, 'status' => 'open']);
                            }
                            if (in_array(mb_strtolower(trim($text)), ['stop', 'unsubscribe', 'cancel'], true)) {
                                $contact->update(['whatsapp_consent' => false]);
                                Suppression::updateOrCreate(['channel' => 'whatsapp', 'address' => $phone], ['reason' => 'Inbound opt-out']);
                                ConsentEvent::create(['contact_id' => $contact->id, 'channel' => 'whatsapp', 'granted' => false, 'evidence' => 'Inbound STOP message']);
                            }
                            app(WorkflowEngine::class)->trigger('inbound.message', $contact, $incoming['id']);
                            SuggestInboundReply::dispatch(app(TenantContext::class)->id(), $conversation->id, $message->id)->afterCommit();
                        }
                    });
                }
            }
        }
    }

    private function email(array $payload, WebhookEvent $event): void
    {
        $data = $payload['data'] ?? [];
        $type = $payload['type'] ?? '';
        if ($type === 'email.received') {
            $integration = Integration::findOrFail($event->payload['integration_id']);
            $email = app(ProviderHttp::class)->request('GET', 'https://api.resend.com/emails/receiving/'.$data['email_id'], ['Authorization' => 'Bearer '.$integration->credentials['api_key']]);
            $from = $email['from'] ?? '';
            preg_match('/<([^>]+)>/', $from, $matches);
            $address = strtolower($matches[1] ?? $from);
            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            DB::transaction(function () use ($email, $address, $data) {
                $contact = Contact::firstOrCreate(['email' => $address], ['name' => mb_substr($address, 0, 160), 'source' => 'email', 'score' => 'warm', 'score_reason' => 'Inbound email contact.']);
                $conversation = Conversation::firstOrCreate(['contact_id' => $contact->id, 'channel' => 'email'], ['subject' => mb_substr($email['subject'] ?? 'Email', 0, 200)]);
                $message = Message::firstOrCreate(['provider_id' => 'inbound:'.$data['email_id']], ['conversation_id' => $conversation->id, 'idempotency_key' => (string) Str::uuid(), 'direction' => 'inbound', 'body' => mb_substr($email['text'] ?? strip_tags($email['html'] ?? ''), 0, 50000), 'status' => 'received', 'sent_at' => now()]);
                if ($message->wasRecentlyCreated) {
                    $conversation->update(['last_inbound_at' => now(), 'status' => 'open']);
                    app(WorkflowEngine::class)->trigger('inbound.message', $contact, 'email:'.$data['email_id']);
                    SuggestInboundReply::dispatch(app(TenantContext::class)->id(), $conversation->id, $message->id)->afterCommit();
                }
            });

            return;
        }
        $message = Message::where('provider_id', $data['email_id'] ?? '')->first();
        if (! $message) {
            return;
        }
        if (in_array($type, ['email.bounced', 'email.complained'], true)) {
            $contact = $message->conversation->contact;
            Suppression::updateOrCreate(['channel' => 'email', 'address' => $contact->email], ['reason' => $type]);
            $contact->update(['email_consent' => false]);
            $message->update(['status' => $type === 'email.bounced' ? 'bounced' : 'complained']);
        } elseif ($type === 'email.delivered' && ! in_array($message->status, ['bounced', 'complained'], true)) {
            $message->update(['status' => 'delivered']);
        } elseif (in_array($type, ['email.opened', 'email.clicked'], true)) {
            $metadata = $message->metadata ?? [];
            $metadata[$type] = ($metadata[$type] ?? 0) + 1;
            $message->update(['metadata' => $metadata]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::whereKey($this->eventId)->update(['error' => 'Processing failed. Check provider configuration and retry this job.']);
    }
}
