<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\ContactTable;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

class CrmSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $business;

    private Tenant $other;

    private User $owner;

    private User $analyst;

    private Contact $contact;

    private Contact $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Tenant::create(['name' => 'Business A', 'slug' => 'a', 'industry' => 'Services']);
        $this->other = Tenant::create(['name' => 'Business B', 'slug' => 'b', 'industry' => 'Education']);
        $this->owner = User::factory()->create();
        $this->analyst = User::factory()->create();
        $this->business->users()->attach($this->owner, ['role' => Role::BusinessOwner->value]);
        $this->business->users()->attach($this->analyst, ['role' => Role::Analyst->value]);
        $this->contact = app(TenantContext::class)->run($this->business, fn () => Contact::create(['name' => 'Alice A', 'email' => 'alice@example.test', 'source' => 'website', 'score_reason' => 'Test record']));
        $this->foreign = app(TenantContext::class)->run($this->other, fn () => Contact::create(['name' => 'Private B', 'email' => 'private@example.test', 'source' => 'website', 'score_reason' => 'Test record']));
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $this->business->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'New contact', 'email' => 'new@example.test', 'phone' => '+919876543210', 'source' => 'referral'], $overrides);
    }

    public function test_guest_is_redirected_and_login_logout_work(): void
    {
        $this->get('/contacts')->assertRedirect('/login');
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect('/contacts');
        $this->assertAuthenticatedAs($this->owner);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'no@example.test', 'password' => 'wrong'])->assertSessionHasErrors();
        }
        $this->post('/login', ['email' => 'no@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_pages_are_functional_and_dashboard_is_isolated(): void
    {
        $this->loginAs($this->owner);
        foreach (['dashboard', 'contacts', 'contacts/create', 'contacts/'.$this->contact->id.'/edit', 'companies', 'pipeline', 'tasks', 'activity', 'integrations'] as $page) {
            $response = $this->get('/'.$page)->assertOk();
            $response->assertDontSee('Private B');
        }
        $this->get('/dashboard')->assertViewHas('contactsCount', 1);
        $this->get('/integrations')->assertSee('Not connected')->assertSee('Configuration available');
    }

    public function test_cross_tenant_record_access_and_switch_are_denied(): void
    {
        $this->loginAs($this->owner);
        $this->get('/contacts/'.$this->foreign->id.'/edit')->assertNotFound();
        $this->put('/contacts/'.$this->foreign->id, $this->payload())->assertNotFound();
        $this->delete('/contacts/'.$this->foreign->id)->assertNotFound();
        $this->post('/business/switch', ['tenant_id' => $this->other->id])->assertForbidden();
        $this->withSession(['tenant_id' => $this->other->id])->get('/dashboard')->assertForbidden();
    }

    public function test_explicit_multi_business_membership_allows_switch_and_revocation_takes_effect(): void
    {
        $this->other->users()->attach($this->owner, ['role' => Role::AgencyOwner->value]);
        $this->loginAs($this->owner)->post('/business/switch', ['tenant_id' => $this->other->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertSee('Private B')->assertDontSee('Alice A');
        $this->other->users()->detach($this->owner);
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_read_only_role_cannot_mutate_or_export(): void
    {
        $this->loginAs($this->analyst);
        $this->get('/contacts')->assertOk()->assertDontSee('Add contact');
        $this->post('/contacts', $this->payload())->assertForbidden();
        $this->put('/contacts/'.$this->contact->id, $this->payload())->assertForbidden();
        $this->delete('/contacts/'.$this->contact->id)->assertForbidden();
        $this->post('/companies', ['name' => 'Forbidden'])->assertForbidden();
        $this->post('/deals', [])->assertForbidden();
        $this->post('/tasks', [])->assertForbidden();
        $this->get('/contacts/export')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_role_matrix_is_enforced(): void
    {
        foreach (Role::cases() as $role) {
            $this->business->users()->updateExistingPivot($this->owner->id, ['role' => $role->value]);
            $this->loginAs($this->owner)->get('/contacts/create')->assertStatus($role->canWriteCrm() ? 200 : 403);
            $this->get('/contacts/export')->assertStatus($role->canExport() ? 200 : 403);
        }
    }

    public function test_contact_crud_scoring_consent_and_audit_are_persisted(): void
    {
        $this->loginAs($this->owner)->post('/contacts', $this->payload(['email_consent' => 1]))->assertRedirect('/contacts');
        $row = DB::table('contacts')->where('email', 'new@example.test')->first();
        $this->assertSame($this->business->id, (int) $row->tenant_id);
        $this->assertSame('hot', $row->score);
        $this->assertSame(1, (int) $row->email_consent);
        $this->assertSame(0, (int) $row->whatsapp_consent);
        $this->put('/contacts/'.$row->id, $this->payload(['source' => 'event']))->assertRedirect('/contacts');
        $this->assertDatabaseHas('contacts', ['id' => $row->id, 'score' => 'warm', 'email_consent' => false]);
        $this->delete('/contacts/'.$row->id)->assertRedirect('/contacts');
        $this->assertDatabaseMissing('contacts', ['id' => $row->id]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->business->id, 'action' => 'contact.deleted', 'actor_id' => $this->owner->id]);
    }

    public function test_tenant_forgery_duplicate_email_and_foreign_company_fail_validation(): void
    {
        $company = app(TenantContext::class)->run($this->other, fn () => Company::create(['name' => 'Private company']));
        $this->loginAs($this->owner)->post('/contacts', $this->payload(['tenant_id' => $this->other->id]))->assertSessionHasErrors('tenant_id');
        $this->post('/contacts', $this->payload(['company_id' => $company->id]))->assertSessionHasErrors('company_id');
        $this->post('/contacts', $this->payload(['email' => 'ALICE@EXAMPLE.TEST']))->assertSessionHasErrors('email');
        $this->post('/contacts', $this->payload(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->post('/contacts', $this->payload(['email' => $this->foreign->email]))->assertRedirect('/contacts');
    }

    public function test_deals_and_tasks_validate_foreign_contact_and_persist_changes(): void
    {
        $this->loginAs($this->owner);
        $deal = ['title' => 'Proposal', 'contact_id' => $this->foreign->id, 'amount' => 12500, 'stage' => 'proposal'];
        $this->post('/deals', $deal)->assertSessionHasErrors('contact_id');
        $deal['contact_id'] = $this->contact->id;
        $this->post('/deals', $deal)->assertSessionHasNoErrors();
        $id = DB::table('deals')->where('title', 'Proposal')->value('id');
        $this->patch('/deals/'.$id, ['stage' => 'won'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertViewHas('wonValue', fn ($value) => (float) $value === 12500.0);
        $task = ['title' => 'Call back', 'contact_id' => $this->foreign->id, 'due_at' => '2026-10-11T10:00'];
        $this->post('/tasks', $task)->assertSessionHasErrors('contact_id');
        $task['contact_id'] = $this->contact->id;
        $this->post('/tasks', $task)->assertSessionHasNoErrors();
        $id = DB::table('tasks')->where('title', 'Call back')->value('id');
        $this->assertDatabaseHas('tasks', ['id' => $id, 'due_at' => '2026-10-11 04:30:00']);
        $this->patch('/tasks/'.$id)->assertSessionHasNoErrors();
        $this->patch('/tasks/'.$id)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'task.completed')->count());
    }

    public function test_export_is_isolated_and_csv_formula_safe(): void
    {
        app(TenantContext::class)->run($this->business, fn () => Contact::create(['name' => '=HYPERLINK("bad")', 'source' => 'other', 'score_reason' => 'Test']));
        $csv = $this->loginAs($this->owner)->get('/contacts/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Alice A', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Private B', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contacts.exported']);
    }

    public function test_tenant_queries_fail_without_context_and_context_is_cleared_after_request(): void
    {
        $this->loginAs($this->owner)->get('/dashboard')->assertOk();
        $this->assertNull(app(TenantContext::class)->tenant);
        $this->expectException(\LogicException::class);
        Contact::count();
    }

    public function test_database_rejects_cross_tenant_relationship_even_without_http_validation(): void
    {
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->business, fn () => Deal::create(['contact_id' => $this->foreign->id, 'title' => 'Invalid', 'amount' => 1, 'stage' => 'new']));
    }

    public function test_super_admin_does_not_bypass_business_membership(): void
    {
        $admin = User::factory()->create();
        $admin->is_super_admin = true;
        $admin->save();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Private B');
        $this->get('/contacts')->assertForbidden();
    }

    public function test_search_is_tenant_scoped_and_escapes_rendered_content(): void
    {
        $context = app(TenantContext::class);
        $context->tenant = $this->business;
        $context->role = Role::BusinessOwner;
        Contact::create(['name' => '<script>alert(1)</script>', 'source' => 'other', 'score_reason' => 'Test']);
        Livewire::actingAs($this->owner)->test(ContactTable::class)->assertDontSee('Private B')
            ->set('search', 'Alice')->assertSee('Alice A')->assertDontSee('Private B')
            ->set('search', '<script>')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $context->clear();
    }

    public function test_demo_seeding_is_repeatable(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = [DB::table('contacts')->count(), DB::table('deals')->count(), DB::table('tasks')->count(), User::count()];
        $this->seed(DemoSeeder::class);
        $this->assertSame($counts, [DB::table('contacts')->count(), DB::table('deals')->count(), DB::table('tasks')->count(), User::count()]);
    }

    public function test_livewire_http_updates_recheck_membership_and_do_not_reuse_old_business_context(): void
    {
        $html = $this->loginAs($this->owner)->get('/contacts')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
        $endpoint = app(HandleRequests::class)->getUpdateUri();
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['search' => 'Alice'], 'calls' => []]]];
        $this->postJson($endpoint, $payload, ['X-Livewire' => 'true'])->assertOk();

        $this->other->users()->attach($this->owner, ['role' => Role::BusinessOwner->value]);
        $this->withSession(['tenant_id' => $this->other->id]);
        $payload['components'][0]['updates']['search'] = 'Private';
        $response = $this->postJson($endpoint, $payload, ['X-Livewire' => 'true'])->assertOk();
        $this->assertStringContainsString('Private B', $response->json('components.0.effects.html'));
        $this->assertStringNotContainsString('Alice A', $response->json('components.0.effects.html'));

        $this->other->users()->detach($this->owner);
        $this->postJson($endpoint, $payload, ['X-Livewire' => 'true'])->assertForbidden();
    }
}
