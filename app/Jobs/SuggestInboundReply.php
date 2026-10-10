<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\KnowledgeEntry;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\AI\AiGateway;
use App\Services\Integrations\ProviderException;
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

class SuggestInboundReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $tenantId, public int $conversationId, public int $inboundId) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->suspended) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            Cache::lock('ai-reply:'.$this->inboundId, 120)->block(5, function () use ($tenant) {
                $mode = $tenant->settings['reply_mode'] ?? 'off';
                $conversation = Conversation::find($this->conversationId);
                $incoming = Message::find($this->inboundId);
                $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'reply:'.$this->tenantId.':'.$this->inboundId);
                if (! $conversation || ! $incoming || $mode === 'off' || $conversation->status === 'human_handover' || Message::where('idempotency_key', $key)->exists()) {
                    return;
                }
                try {
                    $faq = $mode === 'approved_faq' ? KnowledgeEntry::where('kind', 'faq')->where('approved', true)->get()->first(fn ($k) => mb_strtolower(trim($k->title)) === mb_strtolower(trim($incoming->body))) : null;
                    if ($faq && ! preg_match('/refund|guarantee|commit|price change/i', $incoming->body) && mb_strlen($faq->content) <= 4000) {
                        $draft = Message::create(['conversation_id' => $conversation->id, 'provider_id' => 'draft:'.$incoming->id, 'idempotency_key' => $key, 'direction' => 'outbound', 'body' => $faq->content, 'status' => 'draft', 'metadata' => ['source_entry_id' => $faq->id]]);
                        app(Messaging::class)->approveDraft($draft);

                        return;
                    }
                    $answer = app(AiGateway::class)->suggest($incoming->body);
                    if ($answer['handover']) {
                        $conversation->update(['status' => 'human_handover']);

                        return;
                    }
                    Message::create(['conversation_id' => $conversation->id, 'provider_id' => 'draft:'.$incoming->id, 'idempotency_key' => $key, 'direction' => 'outbound', 'body' => $answer['text'], 'status' => 'draft', 'metadata' => ['sources' => $answer['sources']]]);
                } catch (ProviderException) {
                    $conversation->update(['status' => 'human_handover']);
                }
            });
        });
    }
}
