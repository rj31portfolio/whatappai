<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_tokens_are_scoped_revocable_and_recheck_roles(): void
    {
        $tenant = Tenant::create(['name' => 'API Business', 'slug' => 'api', 'industry' => 'Services']);
        $other = Tenant::create(['name' => 'Private', 'slug' => 'private', 'industry' => 'Services']);
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'business_owner']);
        $foreign = app(TenantContext::class)->run($other, fn () => Contact::create(['name' => 'Other private contact', 'source' => 'other', 'score_reason' => 'Test']));
        $token = $user->createToken('Test read', ['crm:read'], now()->addDay());
        $token->accessToken->tenant_id = $tenant->id;
        $token->accessToken->save();
        $this->withToken($token->plainTextToken)->getJson('/api/contacts')->assertOk()->assertJsonPath('total', 0);
        Auth::forgetGuards();
        $this->getJson('/api/contacts/'.$foreign->id)->assertNotFound();
        Auth::forgetGuards();
        $this->postJson('/api/contacts', ['name' => 'Write attempt', 'source' => 'website'])->assertForbidden();
        $write = $user->createToken('Test write', ['crm:read', 'crm:write'], now()->addDay());
        $write->accessToken->tenant_id = $tenant->id;
        $write->accessToken->save();
        Auth::forgetGuards();
        $response = $this->withToken($write->plainTextToken)->postJson('/api/contacts', ['name' => 'API-created', 'source' => 'website'])->assertCreated()->assertJsonPath('tenant_id', $tenant->id);
        Auth::forgetGuards();
        $this->getJson('/api/contacts/'.$response->json('id'))->assertOk();
        $tenant->users()->updateExistingPivot($user->id, ['role' => 'analyst']);
        Auth::forgetGuards();
        $this->postJson('/api/contacts', ['name' => 'Role bypass', 'source' => 'website'])->assertForbidden();
        $write->accessToken->delete();
        Auth::forgetGuards();
        $this->getJson('/api/contacts')->assertUnauthorized();
        $tenant->users()->detach($user->id);
        Auth::forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/contacts')->assertForbidden();
    }

    public function test_token_screen_creates_only_hashed_tenant_tokens(): void
    {
        $tenant = Tenant::create(['name' => 'API Business', 'slug' => 'api', 'industry' => 'Services']);
        $user = User::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'business_owner']);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/api-access')->assertOk();
        $this->post('/api-access', ['name' => 'Website sync', 'write' => 1])->assertSessionHas('api_token');
        $secret = session('api_token');
        $token = $user->tokens()->first();
        $this->assertNotSame($secret, $token->token);
        $this->assertSame($tenant->id, (int) $token->tenant_id);
        $this->delete('/api-access/'.$token->id)->assertSessionHasNoErrors();
        $this->assertSame(0, $user->tokens()->count());
    }
}
