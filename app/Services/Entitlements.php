<?php
namespace App\Services;

use App\Models\{Message, Subscription};
use App\Tenancy\TenantContext;
use App\Services\Integrations\ProviderException;

class Entitlements
{
    public function limits(): array
    {
        $tenant = app(TenantContext::class)->tenant;
        $subscription = Subscription::with('plan')->whereIn('status', ['active', 'authenticated', 'halted', 'cancelled', 'completed'])->whereNotNull('current_end')->where('current_end', '>', now()->subDays(config('growth.grace_days')))->latest('id')->first();
        // authenticated is not paid; only active or an already paid remaining period qualifies.
        if ($subscription && in_array($subscription->status, ['active', 'halted', 'cancelled', 'completed'], true)) {
            return ['enabled' => ! $tenant->suspended, 'messages' => $subscription->plan->monthly_messages, 'contacts' => $subscription->plan->contact_limit, 'expires' => $subscription->current_end];
        }
        return ['enabled' => ! $tenant->suspended && $tenant->trial_ends_at?->isFuture(), 'messages' => 100, 'contacts' => 1000, 'expires' => $tenant->trial_ends_at];
    }

    public function assertSendAllowed(): void
    {
        $limits = $this->limits();
        if (! $limits['enabled']) { throw new ProviderException('Subscription or trial expired. Renew billing to send messages.'); }
        $count = Message::where('direction', 'outbound')->where('created_at', '>=', now()->startOfMonth())->whereNotIn('status', ['failed', 'blocked', 'cancelled'])->count();
        if ($count > $limits['messages']) { throw new ProviderException('Monthly message allowance reached.'); }
    }
}
