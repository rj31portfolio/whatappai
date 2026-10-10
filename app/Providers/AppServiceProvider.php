<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Tenancy\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Contact::class, \App\Models\Company::class, \App\Models\Deal::class, \App\Models\Task::class] as $model) {
            \Illuminate\Support\Facades\Gate::policy($model, \App\Policies\CrmPolicy::class);
        }
        \Illuminate\Support\Facades\Gate::define('export-crm', function (\App\Models\User $user) {
            return \Illuminate\Support\Facades\Gate::forUser($user)->allows('viewAny', \App\Models\Contact::class)
                && app(\App\Tenancy\TenantContext::class)->role->canExport();
        });
        \Illuminate\Support\Facades\Gate::define('super-admin', fn (\App\Models\User $user) => $user->is_super_admin);
        \Illuminate\Support\Facades\RateLimiter::for('login', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
