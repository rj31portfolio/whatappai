<?php

namespace App\Services;

use App\Jobs\SendMessage;
use App\Models\Campaign;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PlatformSetting;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Services\Integrations\ProviderException;
use App\Services\Integrations\ProviderHttp;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Messaging
{
    public function approveDraft(Message $draft): void
    {
        DB::transaction(function () use ($draft) {
            Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $message = Message::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($message->status !== 'draft') {
                return;
            }
            $this->check($message->conversation, $message->metadata ?? []);
            $message->update(['status' => 'queued', 'provider_id' => null]);
            app(Entitlements::class)->assertSendAllowed();
            SendMessage::dispatch(app(TenantContext::class)->id(), $message->id)->afterCommit();
        });
    }

    public function queue(Conversation $conversation, string $body, array $metadata = [], ?string $key = null): Message
    {
        return DB::transaction(function () use ($conversation, $body, $metadata, $key) {
            Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $key ??= (string) Str::uuid();
            $existing = Message::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $this->check($conversation, $metadata);
            $message = Message::create(['conversation_id' => $conversation->id, 'idempotency_key' => $key, 'direction' => 'outbound', 'body' => $body, 'metadata' => $metadata]);
            app(Entitlements::class)->assertSendAllowed();
            SendMessage::dispatch(app(TenantContext::class)->id(), $message->id)->afterCommit();

            return $message;
        });
    }

    public function check(Conversation $conversation, array $metadata, ?int $excludeMessageId = null): Integration
    {
        if (! PlatformSetting::enabled('messaging')) {
            throw new ProviderException('Messaging is disabled by the platform administrator.');
        }
        if (! empty($metadata['campaign_id']) && Campaign::find($metadata['campaign_id'])?->status === 'cancelled') {
            throw new ProviderException('Campaign cancelled.');
        }
        $contact = $conversation->contact()->firstOrFail();
        $channel = $conversation->channel;
        $address = $channel === 'email' ? $contact->email : $contact->phone;
        $flag = $channel === 'email' ? $contact->email_consent : $contact->whatsapp_consent;
        $consent = ConsentEvent::where('contact_id', $contact->id)->where('channel', $channel)->latest('id')->first();
        if (! $address || ! $flag || ! $consent?->granted || Suppression::where('channel', $channel)->where('address', $address)->exists()) {
            throw new ProviderException('This contact has no current evidenced permission or is suppressed.');
        }
        if ($channel === 'whatsapp' && empty($metadata['template_id']) && (! $conversation->last_inbound_at || $conversation->last_inbound_at->lt(now()->subHours(24)))) {
            throw new ProviderException('The 24-hour WhatsApp reply window is closed. Use an approved template.');
        }
        if (! empty($metadata['template_id'])) {
            $template = MessageTemplate::whereKey($metadata['template_id'])->where('channel', $channel)->where('approval_status', 'APPROVED')->first();
            if (! $template) {
                throw new ProviderException('WhatsApp template must be approved by Meta.');
            }
        }
        $settings = app(TenantContext::class)->tenant->settings ?? [];
        $localHour = now(app(TenantContext::class)->tenant->timezone)->hour;
        if (! empty($metadata['marketing']) && ($localHour < ($settings['send_hour_start'] ?? 9) || $localHour >= ($settings['send_hour_end'] ?? 18))) {
            throw new ProviderException('Marketing sends are restricted to business sending hours.');
        }
        $recent = Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->whereIn('status', ['queued', 'sending', 'accepted', 'sent', 'delivered', 'read', 'unknown'])->when($excludeMessageId, fn ($q) => $q->where('id', '!=', $excludeMessageId))->where('created_at', '>', now()->subDay())->count();
        if ($recent >= ($settings['daily_frequency_cap'] ?? 3)) {
            throw new ProviderException('Daily contact frequency cap reached.');
        }
        $provider = $channel === 'email' ? 'resend' : 'whatsapp';

        return Integration::where('provider', $provider)->where('status', 'connected')->first() ?? throw new ProviderException('The '.$provider.' integration is not connected.');
    }

    public function deliver(Message $message): void
    {
        $integration = $this->check($message->conversation, $message->metadata ?? [], $message->id);
        app(Entitlements::class)->assertSendAllowed();
        $contact = $message->conversation->contact;
        $http = app(ProviderHttp::class);
        if ($integration->provider === 'resend') {
            $text = $message->body;
            if ($message->metadata['marketing'] ?? false) {
                $url = URL::signedRoute('unsubscribe', ['tenant' => app(TenantContext::class)->id(), 'contact' => $contact->id]);
                $text .= "\n\nUnsubscribe: ".$url;
            }
            $result = $http->request('POST', 'https://api.resend.com/emails', ['Authorization' => 'Bearer '.$integration->credentials['api_key'], 'Idempotency-Key' => $message->idempotency_key], [
                'from' => $integration->config['from_email'], 'to' => [$contact->email], 'subject' => $message->metadata['subject'] ?? 'Following up', 'text' => $text,
            ]);
            $id = $result['id'] ?? null;
        } else {
            $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($contact->phone, '+'), 'type' => 'text', 'text' => ['body' => $message->body]];
            if ($message->metadata['template_id'] ?? null) {
                $template = MessageTemplate::findOrFail($message->metadata['template_id']);
                $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($contact->phone, '+'), 'type' => 'template', 'template' => ['name' => $template->name, 'language' => ['code' => $template->language]]];
            }
            $result = $http->request('POST', 'https://graph.facebook.com/'.config('growth.graph_version').'/'.$integration->config['phone_number_id'].'/messages', ['Authorization' => 'Bearer '.$integration->credentials['api_key']], $payload);
            $id = $result['messages'][0]['id'] ?? null;
        }
        if (! $id) {
            throw new ProviderException('Provider did not acknowledge a message ID.');
        }
        $message->update(['provider_id' => $id, 'status' => 'accepted', 'sent_at' => now(), 'error' => null]);
    }
}
