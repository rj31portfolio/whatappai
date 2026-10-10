<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWebhook;
use App\Jobs\RunCampaign;
use App\Jobs\RunWorkflow;
use App\Jobs\SendMessage;
use App\Jobs\SuggestInboundReply;
use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\KnowledgeEntry;
use App\Models\Message;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Suppression;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AI\AiGateway;
use App\Services\Billing;
use App\Services\Entitlements;
use App\Services\Integrations\ProviderException;
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class GrowthModulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => 'Growth test', 'slug' => 'growth-test', 'industry' => 'Services']);
        $this->tenant->trial_ends_at = now()->addDays(14);
        $this->tenant->settings = ['ai_provider' => 'openai', 'monthly_ai_budget_usd' => 10, 'send_hour_start' => 0, 'send_hour_end' => 24, 'daily_frequency_cap' => 3];
        $this->tenant->save();
        $this->other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'industry' => 'Education']);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner->id, ['role' => Role::BusinessOwner->value]);
        $this->contact = $this->within(fn () => Contact::create(['name' => 'Customer', 'email' => 'customer@example.test', 'phone' => '+919876543210', 'source' => 'website', 'score' => 'warm', 'score_reason' => 'Test']));
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function within(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->tenant, $callback);
    }

    private function integration(string $provider = 'resend'): Integration
    {
        return Integration::create(['provider' => $provider, 'public_id' => (string) Str::uuid(), 'credentials' => ['api_key' => 'secret-test-key', 'webhook_secret' => 'test-signing-secret', 'verify_token' => 'verify-me'], 'config' => ['from_email' => 'hello@example.test', 'model' => 'test-model', 'input_usd_per_million' => 1, 'output_usd_per_million' => 2, 'phone_number_id' => '123456789', 'business_account_id' => '987654321'], 'status' => 'connected']);
    }

    private function permit(string $channel = 'email'): void
    {
        $this->contact->update([$channel.'_consent' => true]);
        ConsentEvent::create(['contact_id' => $this->contact->id, 'channel' => $channel, 'granted' => true, 'evidence' => 'Test permission recorded']);
    }

    public function test_all_business_panels_render(): void
    {
        foreach (['inbox', 'campaigns', 'knowledge', 'ai', 'automations', 'analytics', 'team', 'billing', 'settings', 'account', 'integrations'] as $panel) {
            $this->get('/'.$panel)->assertOk();
        }
    }

    public function test_integrations_encrypt_secrets_and_connection_test_uses_real_adapter_contract(): void
    {
        $this->post('/integrations/provider/openai', ['api_key' => 'super-secret-api-key', 'model' => 'test-model', 'input_usd_per_million' => 1, 'output_usd_per_million' => 2])->assertSessionHasNoErrors();
        $row = DB::table('integrations')->first();
        $this->assertStringNotContainsString('super-secret-api-key', $row->credentials);
        $this->get('/integrations')->assertDontSee('super-secret-api-key');
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'test-model']]])]);
        $this->post('/integrations/'.$row->id.'/test')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('integrations', ['id' => $row->id, 'status' => 'connected']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer super-secret-api-key'));
    }

    public function test_marketing_role_cannot_change_provider_credentials_and_foreign_urls_are_hidden(): void
    {
        $id = app(TenantContext::class)->run($this->other, fn () => $this->integration()->id);
        $this->post('/integrations/'.$id.'/test')->assertNotFound();
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => Role::MarketingManager->value]);
        $this->post('/integrations/provider/openai', [])->assertForbidden();
        $this->get('/team')->assertForbidden();
        $this->get('/billing')->assertForbidden();
        $this->post('/campaigns', ['name' => 'Nurture', 'subject' => 'Welcome', 'body' => 'Hello', 'segment' => 'warm'])->assertSessionHasNoErrors();
    }

    public function test_knowledge_approval_grounding_unknown_questions_and_budget_are_enforced(): void
    {
        $this->within(function () {
            $this->integration('openai');
            KnowledgeEntry::create(['title' => 'Opening hours', 'content' => 'Our opening hours are Monday to Friday, 9am to 6pm.', 'approved' => true]);
            KnowledgeEntry::create(['title' => 'Secret draft', 'content' => 'Opening hours are midnight.', 'approved' => false]);
            Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Monday to Friday, 9am–6pm [1].']]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20]])]);
            $answer = app(AiGateway::class)->suggest('What are your opening hours?');
            $this->assertFalse($answer['handover']);
            $this->assertCount(1, $answer['sources']);
            $this->assertSame('completed', AiUsage::first()->status);
            Http::assertSent(fn ($r) => ! str_contains($r['messages'][0]['content'], 'midnight'));
            $unknown = app(AiGateway::class)->suggest('Tell me about volcano astronomy');
            $this->assertTrue($unknown['handover']);
            $this->assertTrue(app(AiGateway::class)->suggest('Approve a refund')['handover']);
            $this->tenant->settings = ['ai_provider' => 'openai', 'monthly_ai_budget_usd' => 0];
            $this->tenant->save();
            $this->expectException(ProviderException::class);
            app(AiGateway::class)->suggest('What are your opening hours?');
        });
    }

    public function test_ai_fallback_only_uses_explicit_configuration(): void
    {
        $this->within(function () {
            $this->integration('openai');
            $this->integration('deepseek');
            KnowledgeEntry::create(['title' => 'Opening hours', 'content' => 'Opening hours 9am to 6pm.', 'approved' => true]);
            $this->tenant->settings = array_merge($this->tenant->settings, ['ai_fallback' => 'deepseek']);
            $this->tenant->save();
            Http::fake(['api.openai.com/*' => Http::response([], 503), 'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => '9am to 6pm [1]']]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]])]);
            $this->assertFalse(app(AiGateway::class)->suggest('Opening hours?')['handover']);
            $this->assertSame(2, AiUsage::count());
            $this->assertSame('failed_reserved', AiUsage::where('provider', 'openai')->first()->status);
        });
    }

    public function test_messaging_requires_evidence_suppression_and_plan_checks_then_sends_once(): void
    {
        Queue::fake();
        $this->within(function () {
            $this->integration();
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'email']);
            try {
                app(Messaging::class)->queue($conversation, 'Hello');
                $this->fail('Missing consent accepted');
            } catch (ProviderException) {
            }
            $this->permit();
            $key = (string) Str::uuid();
            $message = app(Messaging::class)->queue($conversation, 'Hello', ['marketing' => true, 'subject' => 'Welcome'], $key);
            $duplicate = app(Messaging::class)->queue($conversation, 'Hello', [], $key);
            $this->assertSame($message->id, $duplicate->id);
            Http::fake(['api.resend.com/emails' => Http::response(['id' => 'email_verified_id'])]);
            (new SendMessage($this->tenant->id, $message->id))->handle();
            (new SendMessage($this->tenant->id, $message->id))->handle();
            Http::assertSentCount(1);
            Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $key) && str_contains($r['text'], 'Unsubscribe:'));
            $this->assertSame('accepted', $message->fresh()->status);
            Suppression::create(['channel' => 'email', 'address' => $this->contact->email, 'reason' => 'Opt-out']);
            $this->expectException(ProviderException::class);
            app(Messaging::class)->queue($conversation, 'Blocked');
        });
    }

    public function test_worker_rechecks_revoked_consent_before_network_call(): void
    {
        Queue::fake();
        $this->within(function () {
            $this->integration();
            $this->permit();
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'email']);
            $message = app(Messaging::class)->queue($conversation, 'Hello');
            $this->contact->update(['email_consent' => false]);
            (new SendMessage($this->tenant->id, $message->id))->handle();
            $this->assertSame('blocked', $message->fresh()->status);
            Http::assertNothingSent();
        });
    }

    public function test_whatsapp_free_text_window_is_checked(): void
    {
        $this->within(function () {
            $this->integration('whatsapp');
            $this->permit('whatsapp');
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'whatsapp', 'last_inbound_at' => now()->subHours(25)]);
            $this->expectException(ProviderException::class);
            app(Messaging::class)->queue($conversation, 'No window');
        });
    }

    public function test_signed_whatsapp_events_are_deduplicated_and_stop_suppresses(): void
    {
        Queue::fake();
        $integration = $this->within(fn () => $this->integration('whatsapp'));
        $payload = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '123456789'], 'messages' => [['from' => '919876543210', 'id' => 'wamid.inbound1', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'STOP']]]]]]]]];
        $raw = json_encode($payload);
        $url = '/webhooks/integration/'.$integration->public_id;
        $this->postJson($url, $payload)->assertForbidden();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'test-signing-secret')];
        $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::first();
        (new ProcessWebhook($event->id))->handle();
        (new ProcessWebhook($event->id))->handle();
        $this->assertSame(1, DB::table('messages')->count());
        $this->assertDatabaseHas('suppressions', ['tenant_id' => $this->tenant->id, 'address' => '+919876543210']);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_unsubscribe_requires_signature_and_persists_suppression(): void
    {
        $url = URL::signedRoute('unsubscribe', ['tenant' => $this->tenant->id, 'contact' => $this->contact->id]);
        $this->get('/unsubscribe/'.$this->tenant->id.'/'.$this->contact->id)->assertForbidden();
        $this->get($url)->assertOk();
        $this->post($url)->assertOk();
        $this->assertDatabaseHas('suppressions', ['tenant_id' => $this->tenant->id, 'channel' => 'email', 'address' => $this->contact->email]);
    }

    public function test_campaign_execution_is_idempotent_and_never_sends_to_opt_out(): void
    {
        Queue::fake();
        $this->within(function () {
            $this->integration();
            $this->permit();
            Contact::create(['name' => 'Opted out', 'email' => 'out@example.test', 'source' => 'other', 'score_reason' => 'Test', 'email_consent' => false]);
            $campaign = Campaign::create(['name' => 'Welcome', 'subject' => 'Hello', 'body' => 'Hello {{name}}', 'segment' => 'all', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
            (new RunCampaign($this->tenant->id, $campaign->id))->handle();
            (new RunCampaign($this->tenant->id, $campaign->id))->handle();
            $this->assertSame(1, CampaignRecipient::count());
            $this->assertSame(1, Message::count());
            $this->assertSame('queued', $campaign->fresh()->status);
        });
    }

    public function test_workflows_use_snapshots_conditions_delays_and_atomic_progress(): void
    {
        $this->within(function () {
            $steps = [['type' => 'task', 'value' => 'Call the lead', 'if_score' => 'warm'], ['type' => 'delay', 'value' => '60'], ['type' => 'score', 'value' => 'hot']];
            $workflow = Workflow::create(['name' => 'Nurture', 'trigger' => 'manual', 'steps' => $steps, 'active' => true]);
            $run = WorkflowRun::create(['workflow_id' => $workflow->id, 'contact_id' => $this->contact->id, 'event_key' => 'test', 'steps' => $steps, 'next_at' => now()]);
            (new RunWorkflow($this->tenant->id, $run->id))->handle();
            (new RunWorkflow($this->tenant->id, $run->id))->handle();
            (new RunWorkflow($this->tenant->id, $run->id))->handle();
            $this->assertSame(1, Task::count());
            $this->assertSame(2, $run->fresh()->position);
            $this->assertSame('warm', $this->contact->fresh()->score);
            $this->travel(61)->minutes();
            (new RunWorkflow($this->tenant->id, $run->id))->handle();
            (new RunWorkflow($this->tenant->id, $run->id))->handle();
            $this->assertSame('hot', $this->contact->fresh()->score);
            $this->assertSame('completed', $run->fresh()->status);
        });
    }

    public function test_team_invitation_is_one_time_and_last_owner_cannot_be_demoted(): void
    {
        $this->post('/team/invite', ['email' => 'newteam@example.test', 'role' => 'sales_agent'])->assertSessionHas('invite_url');
        $url = session('invite_url');
        $this->get($url)->assertOk();
        $this->post($url, ['name' => 'Teammate', 'password' => 'SecurePassword!2026', 'password_confirmation' => 'SecurePassword!2026'])->assertRedirect('/dashboard');
        $user = User::where('email', 'newteam@example.test')->firstOrFail();
        $this->assertTrue($this->tenant->users()->whereKey($user->id)->exists());
        $this->get($url)->assertNotFound();
        $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id])->patch('/team/'.$this->owner->id, ['role' => 'analyst'])->assertStatus(422);
    }

    public function test_razorpay_frontend_creation_does_not_activate_entitlements(): void
    {
        config(['growth.razorpay.key' => 'rzp_test_example', 'growth.razorpay.secret' => 'test-secret']);
        $this->within(function () {
            $plan = Plan::create(['name' => 'Pro', 'interval' => 'monthly', 'price_paise' => 10000, 'razorpay_plan_id' => 'plan_test', 'monthly_messages' => 1000, 'contact_limit' => 10000]);
            Http::fake(['api.razorpay.com/v1/plans/plan_test' => Http::response(['period' => 'monthly', 'interval' => 1, 'item' => ['amount' => 10000, 'currency' => 'INR']]), 'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_test', 'status' => 'created', 'short_url' => 'https://rzp.io/testcheckout']), 'api.razorpay.com/v1/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'plan_id' => 'plan_test', 'status' => 'active', 'paid_count' => 1, 'current_end' => now()->addMonth()->timestamp])]);
            $subscription = app(Billing::class)->subscribe($plan);
            $this->assertNull($subscription->current_end);
            $this->tenant->trial_ends_at = now()->subDay();
            $this->tenant->save();
            $this->assertFalse((bool) app(Entitlements::class)->limits()['enabled']);
            app(Billing::class)->sync($subscription);
            $this->assertTrue(app(Entitlements::class)->limits()['enabled']);
        });
    }

    public function test_foreign_module_records_are_not_accessible_and_read_only_cannot_write(): void
    {
        [$entry, $workflow, $conversation] = app(TenantContext::class)->run($this->other, function () {
            $contact = Contact::create(['name' => 'Private', 'source' => 'other', 'score_reason' => 'Test']);

            return [KnowledgeEntry::create(['title' => 'Private', 'content' => 'Private data']), Workflow::create(['name' => 'Private', 'trigger' => 'manual', 'steps' => []]), Conversation::create(['contact_id' => $contact->id, 'channel' => 'email'])];
        });
        $this->delete('/knowledge/'.$entry->id)->assertNotFound();
        $this->patch('/automations/'.$workflow->id, ['active' => 1])->assertNotFound();
        $this->get('/inbox?conversation='.$conversation->id)->assertNotFound();
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'analyst']);
        foreach (['campaigns', 'automations', 'knowledge', 'inbox', 'team/invite', 'admin/businesses'] as $path) {
            $this->post('/'.$path, [])->assertForbidden();
        }
    }

    public function test_business_suspension_and_feature_controls_are_enforced(): void
    {
        $this->tenant->suspended = true;
        $this->tenant->save();
        $this->get('/dashboard')->assertForbidden();
        $this->tenant->suspended = false;
        $this->tenant->save();
        PlatformSetting::create(['flags' => ['maintenance' => true]]);
        $this->get('/dashboard')->assertStatus(503);
    }

    public function test_resend_signed_bounce_suppresses_and_old_signature_is_rejected(): void
    {
        Queue::fake();
        $integration = $this->within(function () {
            $integration = $this->integration();
            $integration->credentials = ['api_key' => 'test-key', 'webhook_secret' => 'whsec_'.base64_encode('svix-test-secret')];
            $integration->save();
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'email']);
            Message::create(['conversation_id' => $conversation->id, 'idempotency_key' => (string) Str::uuid(), 'provider_id' => 'email_bounce_id', 'body' => 'Test', 'direction' => 'outbound', 'status' => 'accepted']);

            return $integration;
        });
        $raw = json_encode(['type' => 'email.bounced', 'data' => ['email_id' => 'email_bounce_id']]);
        $timestamp = (string) time();
        $eventId = 'event_bounce_1';
        $signature = base64_encode(hash_hmac('sha256', $eventId.'.'.$timestamp.'.'.$raw, 'svix-test-secret', true));
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $eventId, 'HTTP_SVIX_TIMESTAMP' => $timestamp, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature];
        $this->call('POST', '/webhooks/integration/'.$integration->public_id, [], [], [], $headers, $raw)->assertOk();
        (new ProcessWebhook(WebhookEvent::first()->id))->handle();
        $this->assertDatabaseHas('suppressions', ['address' => 'customer@example.test', 'channel' => 'email']);
        $this->assertDatabaseHas('messages', ['provider_id' => 'email_bounce_id', 'status' => 'bounced']);
        $headers['HTTP_SVIX_TIMESTAMP'] = (string) (time() - 600);
        $this->call('POST', '/webhooks/integration/'.$integration->public_id, [], [], [], $headers, $raw)->assertForbidden();
    }

    public function test_razorpay_webhook_is_signed_idempotent_and_reconciles_payment(): void
    {
        Queue::fake();
        config(['growth.razorpay.key' => 'rzp_test_example', 'growth.razorpay.secret' => 'api-secret', 'growth.razorpay.webhook_secret' => 'signing-secret']);
        $this->within(function () {
            $plan = Plan::create(['name' => 'Pro', 'interval' => 'monthly', 'price_paise' => 10000, 'razorpay_plan_id' => 'plan_test']);
            Subscription::create(['provider_id' => 'sub_test', 'plan_id' => $plan->id, 'status' => 'created']);
        });
        Http::fake(['api.razorpay.com/v1/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'plan_id' => 'plan_test', 'status' => 'active', 'paid_count' => 1, 'current_end' => now()->addMonth()->timestamp]), 'api.razorpay.com/v1/payments/pay_test' => Http::response(['id' => 'pay_test', 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured', 'invoice_id' => 'inv_test'])]);
        $raw = json_encode(['event' => 'subscription.charged', 'payload' => ['subscription' => ['entity' => ['id' => 'sub_test']], 'payment' => ['entity' => ['id' => 'pay_test']]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_EVENT_ID' => 'razorpay_event1', 'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, 'signing-secret')];
        $this->call('POST', '/webhooks/razorpay', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/webhooks/razorpay', [], [], [], $headers, $raw)->assertOk();
        $this->assertSame(1, WebhookEvent::count());
        $event = WebhookEvent::first();
        (new ProcessWebhook($event->id))->handle();
        (new ProcessWebhook($event->id))->handle();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('payments', ['provider_id' => 'pay_test', 'tenant_id' => $this->tenant->id, 'status' => 'captured']);
        $headers['HTTP_X_RAZORPAY_SIGNATURE'] = 'forged';
        $this->call('POST', '/webhooks/razorpay', [], [], [], $headers, $raw)->assertForbidden();
    }

    public function test_auto_faq_reply_is_evidenced_and_idempotent_even_after_approval(): void
    {
        Queue::fake();
        $this->within(function () {
            $this->integration();
            $this->permit();
            $this->tenant->settings = array_merge($this->tenant->settings, ['reply_mode' => 'approved_faq']);
            $this->tenant->save();
            KnowledgeEntry::create(['title' => 'What are your opening hours?', 'kind' => 'faq', 'content' => 'Monday to Friday 9am to 6pm.', 'approved' => true]);
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'email']);
            $incoming = Message::create(['conversation_id' => $conversation->id, 'idempotency_key' => (string) Str::uuid(), 'direction' => 'inbound', 'body' => 'What are your opening hours?', 'status' => 'received']);
            $job = new SuggestInboundReply($this->tenant->id, $conversation->id, $incoming->id);
            $job->handle();
            $job->handle();
            $this->assertSame(1, Message::where('direction', 'outbound')->count());
            $this->assertSame('queued', Message::where('direction', 'outbound')->first()->status);
            Http::assertNothingSent();
        });
    }

    public function test_frequency_cap_one_allows_first_delivery_and_blocks_second(): void
    {
        Queue::fake();
        $this->within(function () {
            $this->integration();
            $this->permit();
            $this->tenant->settings = array_merge($this->tenant->settings, ['daily_frequency_cap' => 1]);
            $this->tenant->save();
            $conversation = Conversation::create(['contact_id' => $this->contact->id, 'channel' => 'email']);
            $message = app(Messaging::class)->queue($conversation, 'First');
            Http::fake(['api.resend.com/emails' => Http::response(['id' => 'email_first'])]);
            (new SendMessage($this->tenant->id, $message->id))->handle();
            $this->assertSame('accepted', $message->fresh()->status);
            $this->expectException(ProviderException::class);
            app(Messaging::class)->queue($conversation, 'Second');
        });
    }

    public function test_password_recovery_uses_broker_tokens_and_invalidates_sessions(): void
    {
        Notification::fake();
        $this->post('/logout');
        $this->post('/forgot-password', ['email' => $this->owner->email])->assertSessionHas('status');
        Notification::assertSentTo($this->owner, ResetPassword::class);
        $token = Password::createToken($this->owner);
        $this->post('/reset-password', ['email' => $this->owner->email, 'token' => $token, 'password' => 'NewSecurePass!2026', 'password_confirmation' => 'NewSecurePass!2026'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewSecurePass!2026', $this->owner->fresh()->password));
    }
}
