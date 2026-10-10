<?php

namespace App\Http\Controllers;

use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Message;
use App\Models\ReportExport;
use App\Services\Reports;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('modules.analytics', [
            'exports' => ReportExport::latest()->limit(20)->get(),
            'sources' => Contact::selectRaw('source, count(*) as total')->groupBy('source')->get(),
            'scores' => Contact::selectRaw('score, count(*) as total')->groupBy('score')->get(),
            'deals' => Deal::selectRaw('stage, count(*) as total, sum(amount) as amount')->groupBy('stage')->get(),
            'messages' => Message::selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'costs' => AiUsage::selectRaw('provider, sum(input_tokens) as inputs, sum(output_tokens) as outputs, sum(estimated_usd) as cost')->groupBy('provider')->get(),
            'campaigns' => Campaign::latest()->limit(20)->get()->map(fn ($c) => ['name' => $c->name, 'status' => $c->status, 'messages' => Message::where('metadata->campaign_id', $c->id)->selectRaw('status, count(*) as total')->groupBy('status')->get()]),
        ]);
    }

    public function export()
    {
        Gate::authorize('export-crm');
        $export = app(Reports::class)->create('manual:'.(string) Str::uuid());

        return Storage::disk('local')->download($export->file_path, 'business-report.csv');
    }

    public function download(ReportExport $export)
    {
        Gate::authorize('export-crm');

        return Storage::disk('local')->download($export->file_path, 'business-report.csv');
    }
}
