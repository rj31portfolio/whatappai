<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index()
    {
        if (! app(TenantContext::class)->tenant && auth()->user()->is_super_admin) {
            return redirect()->route('admin');
        }
        Gate::authorize('viewAny', Contact::class);

        return view('dashboard', [
            'contactsCount' => Contact::count(), 'hotCount' => Contact::where('score', 'hot')->count(),
            'pipelineValue' => Deal::whereNotIn('stage', ['won', 'lost'])->sum('amount'),
            'wonValue' => Deal::where('stage', 'won')->sum('amount'),
            'stages' => collect(Deal::STAGES)->mapWithKeys(fn ($stage) => [$stage => Deal::where('stage', $stage)->count()]),
            'recentContacts' => Contact::with('company')->latest()->limit(5)->get(),
            'tasks' => Task::with('contact')->whereNull('completed_at')->orderBy('due_at')->limit(5)->get(),
            'sources' => Contact::selectRaw('source, COUNT(*) as total')->groupBy('source')->get(),
        ]);
    }

    public function switch(Request $request)
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer']]);
        abort_unless($request->user()->tenants()->where('tenants.id', $data['tenant_id'])->exists(), 403);
        $request->session()->put('tenant_id', (int) $data['tenant_id']);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function integrations()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('integrations');
    }

    public function activity()
    {
        Gate::authorize('viewAny', Contact::class);

        return view('activity', ['logs' => AuditLog::orderByDesc('id')->paginate(30)]);
    }

    public function admin()
    {
        Gate::authorize('super-admin');

        return view('admin', ['businesses' => Tenant::count(), 'users' => User::count()]);
    }
}
