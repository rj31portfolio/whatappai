<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing;
use App\Services\Entitlements;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-billing');

        return view('modules.billing', ['plans' => Plan::where('active', true)->get(), 'subscriptions' => Subscription::with('plan')->latest()->get(), 'payments' => Payment::latest()->paginate(20), 'limits' => app(Entitlements::class)->limits(), 'configured' => str_starts_with(config('growth.razorpay.key') ?? '', 'rzp_test_')]);
    }

    public function subscribe(Request $request, Plan $plan)
    {
        Gate::authorize('manage-billing');
        $request->validate(['confirm' => 'accepted']);
        $subscription = app(Billing::class)->subscribe($plan);

        return back()->with('status', 'Test subscription created. Open its secure checkout link below. Access activates only after server verification.');
    }

    public function sync(Subscription $subscription)
    {
        Gate::authorize('manage-billing');
        app(Billing::class)->sync($subscription);

        return back()->with('status', 'Subscription reconciled with Razorpay.');
    }

    public function cancel(Request $request, Subscription $subscription)
    {
        Gate::authorize('manage-billing');
        $request->validate(['confirm' => 'accepted']);
        abort_if(str_starts_with($subscription->provider_id, 'pending_'), 422, 'Reconcile the unknown subscription first.');
        app(Billing::class)->request('POST', 'subscriptions/'.$subscription->provider_id.'/cancel', ['cancel_at_cycle_end' => 1]);
        app(Billing::class)->sync($subscription);

        return back()->with('status', 'Cancellation requested at the end of the paid cycle.');
    }

    public function invoice(Payment $payment)
    {
        Gate::authorize('manage-billing');

        return response()->view('modules.invoice', ['payment' => $payment, 'tenant' => app(TenantContext::class)->tenant])->header('Cache-Control', 'private, no-store');
    }
}
