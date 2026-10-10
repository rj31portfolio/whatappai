<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\PlatformSetting;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ResolveApiTenant
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->clear();
        try {
            $token = $request->user()->currentAccessToken();
            abort_unless($token instanceof PersonalAccessToken && $token->tenant_id, 401, 'Use a tenant-scoped bearer token.');
            $tenant = $request->user()->tenants()->where('tenants.id', $token->tenant_id)->first();
            abort_unless($tenant && ! $tenant->suspended && ! $request->user()->suspended, 403);
            abort_if(PlatformSetting::enabled('maintenance'), 503);
            $context->tenant = $tenant;
            $context->role = Role::from($tenant->pivot->role);
            abort_unless($token->can($request->isMethod('GET') ? 'crm:read' : 'crm:write'), 403, 'Token does not permit this operation.');

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
