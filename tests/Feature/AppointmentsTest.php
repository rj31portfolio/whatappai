<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointments_create_one_reminder_and_can_be_rescheduled(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['name' => 'Appointments', 'slug' => 'appointments', 'industry' => 'Services', 'timezone' => 'Asia/Kolkata']);
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'business_owner']);
        $contact = app(TenantContext::class)->run($tenant, fn () => Contact::create(['name' => 'Appointment customer', 'source' => 'website', 'score_reason' => 'Test']));
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
        $local = now('Asia/Kolkata')->addMinutes(10)->format('Y-m-d\TH:i');
        $this->post('/tasks', ['contact_id' => $contact->id, 'title' => 'Consultation', 'kind' => 'appointment', 'due_at' => $local])->assertSessionHasNoErrors();
        $task = Task::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('appointment', $task->kind);
        $this->assertSame($local, $task->due_at->timezone('Asia/Kolkata')->format('Y-m-d\TH:i'));
        $this->artisan('growth:tick')->assertSuccessful();
        $this->artisan('growth:tick')->assertSuccessful();
        $this->assertSame(1, Reminder::withoutGlobalScopes()->count());
        $reminder = Reminder::withoutGlobalScopes()->firstOrFail();
        $this->get('/reminders')->assertOk()->assertSee('Consultation');
        $this->patch('/reminders/'.$reminder->id)->assertSessionHasNoErrors();
        $this->assertNotNull($reminder->fresh()->read_at);
        $this->put('/tasks/'.$task->id, ['title' => 'Rescheduled consultation', 'kind' => 'appointment', 'due_at' => $local])->assertSessionHasNoErrors();
        $this->artisan('growth:tick')->assertSuccessful();
        $this->assertSame(1, Reminder::withoutGlobalScopes()->count());
        $this->assertNull($reminder->fresh()->read_at);
        $this->delete('/tasks/'.$task->id)->assertSessionHasNoErrors();
        $this->assertSame(0, Reminder::withoutGlobalScopes()->count());
    }

    public function test_company_deletion_preserves_contacts_and_foreign_records_are_hidden(): void
    {
        $tenant = Tenant::create(['name' => 'Companies', 'slug' => 'companies', 'industry' => 'Services']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'industry' => 'Services']);
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'business_owner']);
        [$company, $contact] = app(TenantContext::class)->run($tenant, function () {
            $company = Company::create(['name' => 'Original company']);

            return [$company, Contact::create(['name' => 'Retained', 'company_id' => $company->id, 'source' => 'website', 'score_reason' => 'Test'])];
        });
        $foreign = app(TenantContext::class)->run($other, fn () => Company::create(['name' => 'Private company']));
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
        $this->put('/companies/'.$company->id, ['name' => 'Renamed company'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed company', $company->fresh()->name);
        $this->delete('/companies/'.$foreign->id)->assertNotFound();
        $this->delete('/companies/'.$company->id)->assertSessionHasNoErrors();
        $this->assertNotNull($contact->fresh());
        $this->assertNull($contact->fresh()->company_id);
    }
}
