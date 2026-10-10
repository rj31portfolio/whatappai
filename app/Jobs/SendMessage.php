<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Tenant;
use App\Services\Integrations\ProviderException;
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $messageId) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->suspended) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () {
            Cache::lock('send-conversation:'.$this->tenantId.':'.Message::findOrFail($this->messageId)->conversation_id, 90)->block(5, function () {
                $message = DB::transaction(function () {
                    $message = Message::whereKey($this->messageId)->lockForUpdate()->firstOrFail();
                    if ($message->status !== 'queued') {
                        return null;
                    }
                    try {
                        app(Messaging::class)->check($message->conversation, $message->metadata ?? [], $message->id);
                    } catch (ProviderException $e) {
                        $message->update(['status' => 'blocked', 'error' => $e->getMessage()]);

                        return null;
                    }
                    $message->update(['status' => 'sending']);

                    return $message;
                });
                if (! $message) {
                    return;
                }
                try {
                    app(Messaging::class)->deliver($message);
                } catch (ProviderException $e) {
                    $message->update(['status' => 'unknown', 'error' => $e->getMessage()]);
                }
            });
        });
    }
}
