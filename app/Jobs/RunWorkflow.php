<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PlatformSetting;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\WorkflowRun;
use App\Services\Integrations\ProviderException;
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $tenantId, public int $runId) {}

    public function handle(): void
    {
        if (! PlatformSetting::enabled('automations')) {
            return;
        }
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->suspended) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () {
            DB::transaction(function () {
                $run = WorkflowRun::whereKey($this->runId)->lockForUpdate()->firstOrFail();
                if ($run->status !== 'queued' || $run->next_at?->isFuture()) {
                    return;
                }
                $contact = Contact::find($run->contact_id);
                if (! $contact) {
                    $run->update(['status' => 'cancelled']);

                    return;
                }
                $step = $run->steps[$run->position] ?? null;
                if (! $step) {
                    $run->update(['status' => 'completed']);

                    return;
                }
                try {
                    if (empty($step['if_score']) || $step['if_score'] === $contact->score) {
                        match ($step['type']) {
                            'task' => Task::create(['contact_id' => $contact->id, 'title' => $step['value'], 'due_at' => now()->addDay()]),
                            'score' => $contact->update(['score' => $step['value'], 'score_reason' => 'Workflow #'.$run->workflow_id.' rule.']),
                            'email', 'whatsapp' => app(Messaging::class)->queue(Conversation::firstOrCreate(['contact_id' => $contact->id, 'channel' => $step['type']]), $step['value'], ['marketing' => true, 'subject' => 'Following up']),
                            'delay' => null,
                            default => throw new \RuntimeException('Unsupported workflow action.'),
                        };
                    }
                    $run->update(['position' => $run->position + 1, 'next_at' => now()->addMinutes($step['type'] === 'delay' ? (int) $step['value'] : 0), 'error' => null]);
                } catch (ProviderException $e) {
                    $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
                }
            });
        });
    }
}
