<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        try {
            $user = $request->user();
            if (! $user) {
                return $next($request);
            }
            $selected = $request->session()->get('tenant_id');
            $tenant = $selected
                ? $user->tenants()->where('tenants.id', $selected)->first()
                : $user->tenants()->orderBy('tenants.id')->first();
            if ($selected && ! $tenant) {
                $request->session()->forget('tenant_id');
                abort(403, 'Business membership is no longer valid.');
            }
            if ($tenant) {
                abort_if($tenant->suspended, 403, 'This business is suspended. Contact support.');
                $context->tenant = $tenant;
                $context->role = Role::from($tenant->pivot->role);
                $request->session()->put('tenant_id', $tenant->id);
            }

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
