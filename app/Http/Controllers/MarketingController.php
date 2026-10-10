<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Services\Audit;
use App\Services\ContactScoring;
use App\Services\Entitlements;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MarketingController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('modules.campaigns', ['contacts' => Contact::orderBy('name')->get(), 'campaigns' => Campaign::latest()->paginate(20), 'templates' => MessageTemplate::where('channel', 'email')->get(), 'suppressions' => Suppression::latest()->limit(50)->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['name' => 'required|string|max:160', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:20000', 'segment' => 'required|in:all,hot,warm,cold']);
        $campaign = Campaign::create($data);
        Audit::record('campaign.created', $campaign);

        return back()->with('status', 'Campaign draft saved.');
    }

    public function update(Request $request, Campaign $campaign)
    {
        Gate::authorize('manage-marketing');
        abort_unless($campaign->status === 'draft', 422, 'Only drafts can be edited.');
        $campaign->update($request->validate(['name' => 'required|string|max:160', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:20000', 'segment' => 'required|in:all,hot,warm,cold']));

        return back()->with('status', 'Draft updated.');
    }

    public function schedule(Request $request, Campaign $campaign)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['scheduled_at' => 'required|date_format:Y-m-d\TH:i', 'confirm' => 'accepted']);
        $when = Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], app(TenantContext::class)->tenant->timezone)->utc();
        DB::transaction(function () use ($campaign, $when) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 422, 'This campaign is already scheduled or processed.');
            abort_unless(Integration::where('provider', 'resend')->where('status', 'connected')->exists(), 422, 'Connect and verify Resend before scheduling.');
            app(Entitlements::class)->assertSendAllowed();
            $locked->update(['status' => 'scheduled', 'scheduled_at' => $when]);
            Audit::record('campaign.scheduled', $locked);
        });

        return back()->with('status', 'Campaign scheduled. Keep the scheduler and queue worker running.');
    }

    public function cancel(Campaign $campaign)
    {
        Gate::authorize('manage-marketing');
        DB::transaction(function () use ($campaign) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => 'cancelled']);
            Message::where('status', 'queued')->where('metadata->campaign_id', $locked->id)->update(['status' => 'cancelled']);
            Audit::record('campaign.cancelled', $locked);
        });

        return back()->with('status', 'Campaign cancelled. Already accepted provider sends cannot be recalled.');
    }

    public function template(Request $request)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('message_templates')->where('tenant_id', app(TenantContext::class)->id())->where('channel', 'email')], 'body' => 'required|string|max:20000']);
        MessageTemplate::create($data + ['channel' => 'email']);

        return back()->with('status', 'Reusable email template saved.');
    }

    public function suppress(Request $request)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['channel' => 'required|in:email,whatsapp', 'address' => 'required|string|max:255', 'reason' => 'required|string|max:160']);
        if ($data['channel'] === 'email') {
            $data['address'] = strtolower($data['address']);
        }
        Suppression::updateOrCreate(['channel' => $data['channel'], 'address' => $data['address']], ['reason' => $data['reason']]);

        return back()->with('status', 'Address suppressed. Removing consent on the contact is also recommended.');
    }

    public function consent(Request $request, Contact $contact)
    {
        Gate::authorize('manage-marketing');
        $data = $request->validate(['channel' => 'required|in:email,whatsapp', 'granted' => 'required|boolean', 'evidence' => 'required|string|max:500']);
        DB::transaction(function () use ($data, $contact) {
            ConsentEvent::create($data + ['contact_id' => $contact->id, 'actor_id' => auth()->id()]);
            $contact->update([$data['channel'].'_consent' => (bool) $data['granted']]);
            if (! $data['granted']) {
                $address = $data['channel'] === 'email' ? $contact->email : $contact->phone;
                if ($address) {
                    Suppression::updateOrCreate(['channel' => $data['channel'], 'address' => $address], ['reason' => 'Permission revoked']);
                }
            }
            Audit::record('consent.recorded', $contact);
        });

        return back()->with('status', 'Consent evidence recorded. Existing suppression is retained; re-subscription requires a separate reviewed process.');
    }

    public function import(Request $request)
    {
        Gate::authorize('manage-marketing');
        $request->validate(['csv' => 'required|file|mimes:csv,txt|max:2048']);
        $handle = fopen($request->file('csv')->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        if ($headers !== ['name', 'email', 'phone', 'source']) {
            fclose($handle);

            return back()->withErrors(['csv' => 'CSV header must be exactly: name,email,phone,source']);
        }
        $rows = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($line > 1001 || count($row) !== 4) {
                fclose($handle);

                return back()->withErrors(['csv' => 'Maximum 1,000 rows; each row must have four columns.']);
            }
            $data = array_combine($headers, $row);
            $data['email'] = $data['email'] !== '' ? strtolower(trim($data['email'])) : null;
            $data['phone'] = $data['phone'] !== '' ? trim($data['phone']) : null;
            $validator = validator($data, ['name' => 'required|string|max:160', 'email' => 'nullable|email|max:255', 'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'source' => ['required', Rule::in(Contact::SOURCES)]]);
            if ($validator->fails()) {
                fclose($handle);

                return back()->withErrors(['csv' => 'Row '.$line.': '.$validator->errors()->first()]);
            }
            $rows[] = $data;
        }
        fclose($handle);
        $created = DB::transaction(function () use ($rows) {
            Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            abort_if(Contact::count() + count($rows) > app(Entitlements::class)->limits()['contacts'], 422, 'Import exceeds your contact allowance.');
            $count = 0;
            foreach ($rows as $data) {
                if (($data['email'] && Contact::where('email', $data['email'])->exists()) || ($data['phone'] && Contact::where('phone', $data['phone'])->exists())) {
                    continue;
                }
                $contact = Contact::create(array_merge($data, ContactScoring::evaluate($data)));
                Audit::record('contact.imported', $contact);
                $count++;
            }

            return $count;
        });

        return back()->with('status', $created.' contacts imported; duplicates skipped. Permission defaults to off.');
    }

    public function unsubscribeForm(Request $request, int $tenant, int $contact)
    {
        abort_unless($request->hasValidSignature(), 403);

        return response()->view('auth.unsubscribe', ['url' => $request->fullUrl()]);
    }

    public function unsubscribe(Request $request, int $tenant, int $contact)
    {
        abort_unless($request->hasValidSignature(), 403);
        $business = Tenant::findOrFail($tenant);
        app(TenantContext::class)->run($business, function () use ($contact) {
            $record = Contact::findOrFail($contact);
            DB::transaction(function () use ($record) {
                $record->update(['email_consent' => false]);
                ConsentEvent::create(['contact_id' => $record->id, 'channel' => 'email', 'granted' => false, 'evidence' => 'Signed unsubscribe link']);
                Suppression::updateOrCreate(['channel' => 'email', 'address' => $record->email], ['reason' => 'Unsubscribed']);
            });
        });

        return response('You have been unsubscribed.');
    }
}
