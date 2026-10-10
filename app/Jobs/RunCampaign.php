<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Integrations\ProviderException;
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $tenantId, public int $campaignId) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->suspended) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $hour = now($tenant->timezone)->hour;
            if ($hour < ($tenant->settings['send_hour_start'] ?? 9) || $hour >= ($tenant->settings['send_hour_end'] ?? 18)) {
                $this->release(1800);

                return;
            }
            DB::transaction(function () {
                $campaign = Campaign::whereKey($this->campaignId)->lockForUpdate()->firstOrFail();
                if ($campaign->status !== 'scheduled' || $campaign->scheduled_at->isFuture()) {
                    return;
                }
                $contacts = Contact::where('email_consent', true)->whereNotNull('email');
                if ($campaign->segment !== 'all') {
                    $contacts->where('score', $campaign->segment);
                }
                foreach ($contacts->limit(10000)->get() as $contact) {
                    $recipient = CampaignRecipient::firstOrCreate(['campaign_id' => $campaign->id, 'contact_id' => $contact->id]);
                    if (! $recipient->wasRecentlyCreated) {
                        continue;
                    }
                    $conversation = Conversation::firstOrCreate(['contact_id' => $contact->id, 'channel' => 'email']);
                    try {
                        $message = app(Messaging::class)->queue($conversation, str_replace('{{name}}', $contact->name, $campaign->body), ['subject' => $campaign->subject, 'marketing' => true, 'campaign_id' => $campaign->id]);
                        $recipient->update(['message_id' => $message->id]);
                    } catch (ProviderException $e) {
                        $message = Message::create(['conversation_id' => $conversation->id, 'idempotency_key' => (string) Str::uuid(), 'direction' => 'outbound', 'body' => $campaign->body, 'status' => 'blocked', 'error' => $e->getMessage(), 'metadata' => ['campaign_id' => $campaign->id]]);
                        $recipient->update(['message_id' => $message->id]);
                    }
                }
                $campaign->update(['status' => 'queued']);
            });
        });
    }
}
