<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Audit;
use App\Services\ContactScoring;
use App\Services\Entitlements;
use App\Services\WorkflowEngine;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApiContactController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return Contact::with('company')->latest('id')->paginate(50);
    }

    public function show(Contact $contact)
    {
        Gate::authorize('view', $contact);

        return $contact->load('company');
    }

    public function store(ContactRequest $request)
    {
        return response()->json($this->save($request, new Contact), 201);
    }

    public function update(ContactRequest $request, Contact $contact)
    {
        return $this->save($request, $contact);
    }

    private function save(ContactRequest $request, Contact $contact): Contact
    {
        return DB::transaction(function () use ($request, $contact) {
            $creating = ! $contact->exists;
            Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            abort_if($creating && Contact::count() >= app(Entitlements::class)->limits()['contacts'], 422, 'Contact allowance reached.');
            $data = $request->validated();
            $contact->fill(array_merge($data, ContactScoring::evaluate($data)))->save();
            Audit::record($creating ? 'api.contact_created' : 'api.contact_updated', $contact);
            if ($creating) {
                app(WorkflowEngine::class)->trigger('contact.created', $contact, 'contact:'.$contact->id);
            }

            return $contact;
        });
    }

    public function destroy(Contact $contact)
    {
        Gate::authorize('delete', $contact);
        DB::transaction(function () use ($contact) {
            Audit::record('api.contact_deleted', $contact);
            $contact->delete();
        });

        return response()->noContent();
    }
}
