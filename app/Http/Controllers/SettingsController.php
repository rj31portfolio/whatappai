<?php
namespace App\Http\Controllers;

use App\Models\{Contact, Tenant};
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Hash};
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-settings');
        return view('modules.settings', ['tenant' => app(TenantContext::class)->tenant]);
    }

    public function update(Request $request)
    {
        Gate::authorize('manage-settings');
        $data = $request->validate([
            'name' => 'required|string|max:160', 'industry' => 'required|string|max:120', 'timezone' => 'required|timezone',
            'language' => 'required|string|max:40', 'tone' => 'required|in:professional,friendly,concise',
            'monthly_ai_budget_usd' => 'required|numeric|min:0|max:10000', 'ai_provider' => 'nullable|in:openai,deepseek,claude', 'ai_fallback' => 'nullable|in:openai,deepseek,claude',
            'send_hour_start' => 'required|integer|min:0|max:23', 'send_hour_end' => 'required|integer|min:1|max:24|gt:send_hour_start', 'daily_frequency_cap' => 'required|integer|min:1|max:10',
            'business_profile' => 'nullable|string|max:5000', 'escalation_rules' => 'nullable|string|max:3000',
        ]);
        $tenant = app(TenantContext::class)->tenant;
        $tenant->name = $data['name']; $tenant->industry = $data['industry']; $tenant->timezone = $data['timezone'];
        unset($data['name'], $data['industry'], $data['timezone']);
        $tenant->settings = array_merge($tenant->settings ?? [], $data);
        $tenant->save();
        \App\Services\Audit::record('business.settings_updated', $tenant);
        return back()->with('status', 'Business settings saved.');
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $request->user()->update(['password' => $data['password']]);
        $request->session()->regenerate();
        return back()->with('status', 'Password changed.');
    }
}
