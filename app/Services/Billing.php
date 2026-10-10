<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Integrations\ProviderException;
use App\Services\Integrations\ProviderHttp;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Billing
{
    public function request(string $method, string $path, array $data = []): array
    {
        $key = config('growth.razorpay.key');
        $secret = config('growth.razorpay.secret');
        if (! $key || ! $secret || ! str_starts_with($key, 'rzp_test_')) {
            throw new ProviderException('Configure Razorpay test-mode credentials on the server. Live billing is disabled.');
        }

        return app(ProviderHttp::class)->request($method, 'https://api.razorpay.com/v1/'.$path, [], $data, [$key, $secret]);
    }

    public function subscribe(Plan $plan): Subscription
    {
        $tenantId = app(TenantContext::class)->id();

        return Cache::lock('billing:'.$tenantId, 60)->block(5, function () use ($plan, $tenantId) {
            if (! $plan->active || ! $plan->razorpay_plan_id) {
                throw new ProviderException('The selected plan is not configured with Razorpay.');
            }
            $remotePlan = $this->request('GET', 'plans/'.$plan->razorpay_plan_id);
            if (($remotePlan['period'] ?? '') !== ($plan->interval === 'yearly' ? 'yearly' : 'monthly') || ($remotePlan['interval'] ?? 0) !== 1 || (int) ($remotePlan['item']['amount'] ?? -1) !== (int) $plan->price_paise || ($remotePlan['item']['currency'] ?? '') !== 'INR') {
                throw new ProviderException('Razorpay plan price, currency or interval does not match the configured plan.');
            }
            if (Subscription::whereIn('status', ['creating', 'unknown', 'created', 'authenticated', 'active', 'pending', 'halted'])->exists()) {
                throw new ProviderException('An existing subscription needs cancellation or reconciliation before starting another.');
            }
            $reference = (string) Str::uuid();
            $subscription = Subscription::create(['plan_id' => $plan->id, 'provider_id' => 'pending_'.$reference, 'status' => 'creating']);
            try {
                $remote = $this->request('POST', 'subscriptions', ['plan_id' => $plan->razorpay_plan_id, 'total_count' => $plan->interval === 'yearly' ? 10 : 120, 'quantity' => 1, 'customer_notify' => 1, 'notes' => ['tenant_id' => (string) $tenantId, 'reference' => $reference]]);
                if (empty($remote['id']) || ! preg_match('/^https:\/\/(rzp\.io|razorpay\.com)\//', $remote['short_url'] ?? '')) {
                    throw new ProviderException('Provider did not return a valid subscription and checkout URL.');
                }
                $subscription->update(['provider_id' => $remote['id'], 'status' => $remote['status'], 'checkout_url' => $remote['short_url']]);
                Audit::record('billing.subscription_created', $subscription);

                return $subscription;
            } catch (ProviderException $e) {
                $subscription->update(['status' => 'unknown']);
                throw $e;
            }
        });
    }

    public function sync(Subscription $subscription): void
    {
        if (str_starts_with($subscription->provider_id, 'pending_')) {
            $reference = substr($subscription->provider_id, 8);
            $items = $this->request('GET', 'subscriptions', ['count' => 100]);
            $remote = collect($items['items'] ?? [])->first(fn ($item) => ($item['notes']['reference'] ?? '') === $reference && (int) ($item['notes']['tenant_id'] ?? 0) === app(TenantContext::class)->id());
            if (! $remote) {
                throw new ProviderException('Pending subscription not found among the latest 100 records. Review in Razorpay support before creating another.');
            }
        } else {
            $remote = $this->request('GET', 'subscriptions/'.$subscription->provider_id);
        }
        if (($remote['plan_id'] ?? null) !== $subscription->plan->razorpay_plan_id) {
            throw new ProviderException('Provider plan does not match local subscription.');
        }
        $subscription->update(['provider_id' => $remote['id'], 'status' => $remote['status'], 'current_end' => ! empty($remote['current_end']) && ($remote['paid_count'] ?? 0) > 0 ? Carbon::createFromTimestampUTC($remote['current_end']) : null]);
    }
}
