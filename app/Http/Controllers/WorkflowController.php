<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('modules.workflows', ['workflows' => Workflow::latest()->get(), 'runs' => WorkflowRun::latest()->paginate(20), 'contacts' => Contact::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['name' => 'required|string|max:160', 'trigger' => 'required|in:contact.created,inbound.message,manual', 'score_condition' => 'nullable|in:hot,warm,cold', 'steps' => 'required|array|min:1|max:12', 'steps.*.type' => 'required|in:task,score,email,whatsapp,delay', 'steps.*.value' => 'required|string|max:2000', 'steps.*.if_score' => 'nullable|in:hot,warm,cold']);
        foreach ($data['steps'] as $step) {
            if ($step['type'] === 'score' && ! in_array($step['value'], Contact::SCORES, true)) {
                return back()->withErrors(['steps' => 'Score actions must use hot, warm or cold.']);
            }
            if ($step['type'] === 'delay' && (! ctype_digit($step['value']) || (int) $step['value'] > 43200)) {
                return back()->withErrors(['steps' => 'Delay must be 0–43200 minutes.']);
            }
            if ($step['type'] === 'task' && mb_strlen($step['value']) > 160) {
                return back()->withErrors(['steps' => 'Task title maximum length is 160.']);
            }
        }
        $workflow = Workflow::create($data);
        Audit::record('workflow.created', $workflow);

        return back()->with('status', 'Workflow saved paused. Enable it when reviewed.');
    }

    public function toggle(Request $request, Workflow $workflow)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['active' => 'required|boolean']);
        $workflow->update($data);

        return back()->with('status', 'Workflow status updated. Pausing prevents new runs; cancel queued runs separately.');
    }

    public function run(Request $request, Workflow $workflow)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['contact_id' => ['required', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->id())], 'request_key' => 'required|uuid']);
        abort_unless($workflow->active, 422, 'Enable this workflow first.');
        $contact = Contact::findOrFail($data['contact_id']);
        abort_if($workflow->score_condition && $workflow->score_condition !== $contact->score, 422, 'Contact does not match this workflow condition.');
        WorkflowRun::firstOrCreate(['workflow_id' => $workflow->id, 'event_key' => $data['request_key']], ['contact_id' => $contact->id, 'steps' => $workflow->steps, 'next_at' => now()]);

        return back()->with('status', 'Run queued. Scheduler executes the bounded steps.');
    }

    public function control(Request $request, WorkflowRun $run)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['action' => 'required|in:retry,cancel']);
        abort_unless(in_array($run->status, ['queued', 'failed'], true), 422);
        $run->update(['status' => $data['action'] === 'retry' ? 'queued' : 'cancelled', 'next_at' => now(), 'error' => null]);

        return back()->with('status', 'Run updated.');
    }
}
