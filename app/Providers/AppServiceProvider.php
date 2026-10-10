<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Policies\CrmPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Contact::class, Company::class, Deal::class, Task::class] as $model) {
            Gate::policy($model, CrmPolicy::class);
        }
        Gate::define('export-crm', function (User $user) {
            return Gate::forUser($user)->allows('viewAny', Contact::class)
                && app(TenantContext::class)->role->canExport();
        });
        Gate::define('super-admin', fn (User $user) => $user->is_super_admin);
        foreach (['settings', 'team', 'billing', 'marketing', 'inbox'] as $capability) {
            Gate::define('manage-'.$capability, function (User $user) use ($capability) {
                if (! Gate::forUser($user)->allows('viewAny', Contact::class)) {
                    return false;
                }
                $role = app(TenantContext::class)->role;
                $owners = [Role::AgencyOwner, Role::BusinessOwner];

                return match ($capability) {
                    'team', 'billing' => in_array($role, $owners, true),
                    'settings' => in_array($role, [...$owners, Role::BusinessManager], true),
                    'marketing' => in_array($role, [...$owners, Role::BusinessManager, Role::MarketingManager], true),
                    'inbox' => $role->canWriteCrm(),
                };
            });
        }
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
