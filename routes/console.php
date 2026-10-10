<?php

use App\Jobs\RunCampaign;
use App\Jobs\RunWorkflow;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Models\WorkflowRun;
use App\Services\Reports;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('growth:tick', function () {
    foreach (Tenant::where('suspended', false)->get() as $tenant) {
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            foreach (\App\Models\Task::whereNull('completed_at')->whereNull('reminded_at')->where('due_at', '<=', now()->addMinutes(15))->get() as $task) {
                \App\Models\Reminder::updateOrCreate(['task_id' => $task->id], ['title' => $task->title, 'read_at' => null]);
                $task->update(['reminded_at' => now()]);
            }
            foreach (Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get() as $campaign) {
                RunCampaign::dispatch($tenant->id, $campaign->id);
            }
            foreach (WorkflowRun::where('status', 'queued')->where('next_at', '<=', now())->get() as $run) {
                RunWorkflow::dispatch($tenant->id, $run->id);
            }
            $cadence = $tenant->settings['report_cadence'] ?? 'off';
            $local = now($tenant->timezone);
            if ($local->hour >= 9 && ($cadence === 'daily' || ($cadence === 'weekly' && $local->isMonday()) || ($cadence === 'monthly' && $local->day === 1))) {
                app(Reports::class)->create($cadence.':'.$local->format('Y-m-d'));
            }
        });
    }
    $this->info('Due campaigns, workflows and reports processed.');
});
Schedule::command('growth:tick')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => WebhookEvent::whereNotNull('processed_at')->where('created_at', '<', now()->subDays(30))->delete())->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
