<?php
namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\{TeamInvitation, Tenant, User};
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Gate, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-team');
        return view('modules.team', ['members' => app(TenantContext::class)->tenant->users, 'invitations' => TeamInvitation::whereNull('accepted_at')->latest()->get()]);
    }

    public function invite(Request $request)
    {
        Gate::authorize('manage-team');
        $data = $request->validate(['email' => 'required|email|max:255', 'role' => ['required', Rule::enum(Role::class)]]);
        $token = Str::random(64);
        TeamInvitation::create(['email' => strtolower($data['email']), 'role' => $data['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
        return back()->with('invite_url', route('invite.show', $token))->with('status', 'Invitation created. Share this private link with the intended teammate. It expires in 7 days.');
    }

    public function change(Request $request, User $user)
    {
        Gate::authorize('manage-team');
        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        DB::transaction(function () use ($data, $user) {
            $tenant = Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $member = $tenant->users()->where('users.id', $user->id)->firstOrFail();
            $this->guardLastOwner($tenant, $member, $data['role']);
            $tenant->users()->updateExistingPivot($member->id, $data);
            \App\Services\Audit::record('team.role_changed', $member);
        });
        return back()->with('status', 'Team role updated.');
    }

    public function remove(User $user)
    {
        Gate::authorize('manage-team');
        abort_if($user->id === auth()->id(), 422, 'Use another owner to remove your own membership.');
        DB::transaction(function () use ($user) {
            $tenant = Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $member = $tenant->users()->where('users.id', $user->id)->firstOrFail();
            $this->guardLastOwner($tenant, $member, null);
            $tenant->users()->detach($member->id);
            \App\Services\Audit::record('team.member_removed', $member);
        });
        return back()->with('status', 'Membership revoked.');
    }

    private function guardLastOwner(Tenant $tenant, User $member, ?string $newRole): void
    {
        $owners = ['business_owner', 'agency_owner'];
        if (in_array($member->pivot->role, $owners, true) && ! in_array($newRole, $owners, true)) {
            abort_if($tenant->users()->wherePivotIn('role', $owners)->count() <= 1, 422, 'A business must retain at least one owner.');
        }
    }

    public function show(string $token)
    {
        $invite = TeamInvitation::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();
        return view('auth.invite', ['invite' => $invite, 'token' => $token]);
    }

    public function accept(Request $request, string $token)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $user = DB::transaction(function () use ($token, $data) {
            $invite = TeamInvitation::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $tenant = Tenant::whereKey($invite->tenant_id)->firstOrFail();
            abort_if($tenant->suspended, 403);
            $user = User::where('email', $invite->email)->first();
            if ($user) { abort_unless(Hash::check($data['password'], $user->password), 422, 'Enter your current account password to accept this invitation.'); }
            else { $user = User::create(['name' => $data['name'], 'email' => $invite->email, 'password' => $data['password']]); }
            // An invitation never overwrites an existing membership or elevates an existing teammate.
            if (! $tenant->users()->whereKey($user->id)->exists()) { $tenant->users()->attach($user->id, ['role' => $invite->role]); }
            app(TenantContext::class)->run($tenant, fn () => $invite->update(['accepted_at' => now()]));
            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('tenant_id');
        return redirect()->route('dashboard');
    }
}
