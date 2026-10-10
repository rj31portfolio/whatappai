<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\TeamInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function index()
    {
        Gate::authorize('super-admin');

        return view('modules.admin', ['businesses' => Tenant::withCount('users')->paginate(15, ['*'], 'business_page'), 'users' => User::orderBy('name')->paginate(15, ['*'], 'user_page'), 'plans' => Plan::get(), 'flags' => PlatformSetting::first()?->flags ?? [], 'payments' => Payment::withoutGlobalScope('tenant')->selectRaw('currency, status, count(*) as total, sum(amount_paise) as amount')->groupBy('currency', 'status')->get(), 'subscriptions' => Subscription::withoutGlobalScope('tenant')->selectRaw('status, count(*) as total')->groupBy('status')->get(), 'failedJobs' => DB::table('failed_jobs')->count(), 'webhookErrors' => WebhookEvent::whereNotNull('error')->select('id', 'provider', 'error', 'created_at')->latest()->limit(20)->get()]);
    }

    public function business(Request $request)
    {
        Gate::authorize('super-admin');
        $data = $request->validate(['name' => 'required|string|max:160', 'industry' => 'required|string|max:120', 'owner_email' => 'required|email|max:255']);
        $token = Str::random(64);
        DB::transaction(function () use ($data, $token) {
            $tenant = Tenant::create(['name' => $data['name'], 'industry' => $data['industry'], 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(8))]);
            $tenant->trial_ends_at = now()->addDays(14);
            $tenant->save();
            app(TenantContext::class)->run($tenant, function () use ($data, $token, $tenant) {
                TeamInvitation::create(['email' => strtolower($data['owner_email']), 'role' => Role::BusinessOwner->value, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
                Audit::record('admin.business_created', $tenant);
            });
        });

        return back()->with('invite_url', route('invite.show', $token))->with('status', 'Business provisioned with a 14-day trial. Share the private owner setup link.');
    }

    public function suspend(Request $request, Tenant $tenant)
    {
        Gate::authorize('super-admin');
        $data = $request->validate(['suspended' => 'required|boolean', 'confirm' => 'accepted']);
        $tenant->suspended = $data['suspended'];
        $tenant->save();
        app(TenantContext::class)->run($tenant, fn () => Audit::record('admin.business_suspension_changed', $tenant));

        return back()->with('status', 'Business suspension updated.');
    }

    public function user(Request $request, User $user)
    {
        Gate::authorize('super-admin');
        abort_if($user->id === auth()->id(), 422, 'You cannot suspend your current account.');
        $data = $request->validate(['suspended' => 'required|boolean', 'confirm' => 'accepted']);
        abort_if($user->is_super_admin, 422, 'Super Admin suspension requires a separate controlled recovery procedure.');
        $user->suspended = $data['suspended'];
        $user->save();

        return back()->with('status', 'User suspension updated.');
    }

    public function plan(Request $request)
    {
        Gate::authorize('super-admin');
        $data = $request->validate(['name' => 'required|string|max:100', 'interval' => 'required|in:monthly,yearly', 'price_paise' => 'required|integer|min:0|max:100000000', 'monthly_messages' => 'required|integer|min:1|max:1000000', 'contact_limit' => 'required|integer|min:1|max:1000000', 'razorpay_plan_id' => ['nullable', 'regex:/^plan_[A-Za-z0-9]+$/']]);
        Plan::create($data);

        return back()->with('status', 'Plan created. Match its price and interval to the configured Razorpay test plan.');
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        Gate::authorize('super-admin');
        $plan->update($request->validate(['active' => 'required|boolean']));

        return back()->with('status', 'Plan availability updated. Existing paid entitlements remain unchanged.');
    }

    public function flags(Request $request)
    {
        Gate::authorize('super-admin');
        $request->validate(['maintenance' => 'required|boolean', 'ai' => 'required|boolean', 'messaging' => 'required|boolean', 'automations' => 'required|boolean']);
        PlatformSetting::updateOrCreate(['id' => 1], ['flags' => ['maintenance' => $request->boolean('maintenance'), 'ai' => $request->boolean('ai'), 'messaging' => $request->boolean('messaging'), 'automations' => $request->boolean('automations')]]);

        return back()->with('status', 'Platform feature flags saved.');
    }
}
