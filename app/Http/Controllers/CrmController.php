<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Services\Audit;
use App\Services\ContactScoring;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CrmController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('contacts.index');
    }

    public function create()
    {
        Gate::authorize('create', Contact::class);

        return view('contacts.form', ['contact' => new Contact, 'companies' => Company::orderBy('name')->get()]);
    }

    public function edit(Contact $contact)
    {
        Gate::authorize('update', $contact);

        return view('contacts.form', ['contact' => $contact, 'companies' => Company::orderBy('name')->get()]);
    }

    public function store(ContactRequest $request)
    {
        $this->saveContact($request);

        return redirect()->route('contacts.index')->with('status', 'Contact created.');
    }

    public function update(ContactRequest $request, Contact $contact)
    {
        $this->saveContact($request, $contact);

        return redirect()->route('contacts.index')->with('status', 'Contact updated.');
    }

    private function saveContact(ContactRequest $request, ?Contact $contact = null): void
    {
        $data = $request->validated();
        $data['email_consent'] = $request->boolean('email_consent');
        $data['whatsapp_consent'] = $request->boolean('whatsapp_consent');
        $data = array_merge($data, ContactScoring::evaluate($data));
        DB::transaction(function () use ($data, $contact) {
            $existing = $contact !== null;
            $contact ??= new Contact;
            $contact->fill($data)->save();
            Audit::record($existing ? 'contact.updated' : 'contact.created', $contact);
        });
    }

    public function destroy(Contact $contact)
    {
        Gate::authorize('delete', $contact);
        DB::transaction(function () use ($contact) {
            Audit::record('contact.deleted', $contact);
            $contact->delete();
        });

        return redirect()->route('contacts.index')->with('status', 'Contact and associated deals/tasks deleted.');
    }

    public function companies()
    {
        Gate::authorize('viewAny', Company::class);

        return view('companies', ['companies' => Company::orderBy('name')->paginate(20)]);
    }

    public function storeCompany(Request $request)
    {
        Gate::authorize('create', Company::class);
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:160', Rule::unique('companies')->where('tenant_id', app(TenantContext::class)->id())],
            'website' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        DB::transaction(fn () => Audit::record('company.created', Company::create($data)));

        return back()->with('status', 'Company created.');
    }

    public function pipeline()
    {
        Gate::authorize('viewAny', Deal::class);

        return view('pipeline', ['deals' => Deal::with('contact')->latest()->paginate(30), 'contacts' => Contact::orderBy('name')->get()]);
    }

    public function storeDeal(Request $request)
    {
        Gate::authorize('create', Deal::class);
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'contact_id' => $this->contactRules(),
            'title' => ['required', 'string', 'max:160'], 'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'stage' => ['required', Rule::in(Deal::STAGES)], 'expected_close_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        DB::transaction(fn () => Audit::record('deal.created', Deal::create($data)));

        return back()->with('status', 'Deal created.');
    }

    public function updateDeal(Request $request, Deal $deal)
    {
        Gate::authorize('update', $deal);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'stage' => ['required', Rule::in(Deal::STAGES)]]);
        DB::transaction(function () use ($deal, $data) {
            $deal->update($data);
            Audit::record('deal.stage_changed', $deal);
        });

        return back()->with('status', 'Deal stage updated.');
    }

    public function tasks()
    {
        Gate::authorize('viewAny', Task::class);

        return view('tasks', ['tasks' => Task::with('contact')->orderByRaw('completed_at IS NOT NULL')->orderBy('due_at')->paginate(20), 'contacts' => Contact::orderBy('name')->get()]);
    }

    public function storeTask(Request $request)
    {
        Gate::authorize('create', Task::class);
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'contact_id' => $this->contactRules(),
            'title' => ['required', 'string', 'max:160'], 'due_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $data['due_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], app(TenantContext::class)->tenant->timezone)->utc();
        DB::transaction(fn () => Audit::record('task.created', Task::create($data)));

        return back()->with('status', 'Follow-up scheduled.');
    }

    public function completeTask(Task $task)
    {
        Gate::authorize('update', $task);
        DB::transaction(function () use ($task) {
            $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! $locked->completed_at) {
                $locked->update(['completed_at' => now()]);
                Audit::record('task.completed', $locked);
            }
        });

        return back()->with('status', 'Task completed.');
    }

    private function contactRules(): array
    {
        return ['required', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->id())];
    }

    public function export()
    {
        Gate::authorize('export-crm');
        // Resolve before streaming: request middleware clears tenant context on return.
        $contacts = Contact::orderBy('id')->limit(10001)->get();
        abort_if($contacts->count() > 10000, 422, 'Export limited to 10,000 contacts.');
        DB::transaction(fn () => Audit::record('contacts.exported', app(TenantContext::class)->tenant));

        return response()->streamDownload(function () use ($contacts) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Name', 'Email', 'Phone', 'Source', 'Score', 'Email consent', 'WhatsApp consent']);
            foreach ($contacts as $contact) {
                $values = [$contact->name, $contact->email, $contact->phone, $contact->source, $contact->score, $contact->email_consent ? 'Yes' : 'No', $contact->whatsapp_consent ? 'Yes' : 'No'];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/u', $value ?? '') ? "'".$value : ($value ?? ''), $values));
            }
            fclose($file);
        }, 'contacts.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
