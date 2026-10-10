<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Message;
use App\Models\ReportExport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Reports
{
    public function create(string $periodKey): ReportExport
    {
        return DB::transaction(function () use ($periodKey) {
            $existing = ReportExport::where('period_key', $periodKey)->first();
            if ($existing) {
                return $existing;
            }
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, ['Metric', 'Value', 'Basis']);
            fputcsv($stream, ['Contacts', Contact::count(), 'Measured, all time']);
            foreach (Deal::STAGES as $stage) {
                fputcsv($stream, ['Deal value: '.$stage, Deal::where('stage', $stage)->sum('amount'), 'Recorded deal value, '.app(TenantContext::class)->tenant->currency]);
            }
            foreach (Message::selectRaw('status, count(*) as total')->groupBy('status')->get() as $status) {
                fputcsv($stream, ['Messages: '.$status->status, $status->total, 'Provider state / local queue']);
            }
            fputcsv($stream, ['AI estimated USD', AiUsage::sum('estimated_usd'), 'Estimate using configured rates; includes failed reservations']);
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            $path = 'reports/'.app(TenantContext::class)->id().'/'.hash('sha256', $periodKey).'.csv';
            Storage::disk('local')->put($path, $csv);

            return ReportExport::create(['period_key' => $periodKey, 'file_path' => $path]);
        });
    }
}
