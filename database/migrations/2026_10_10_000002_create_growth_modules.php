<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('suspended')->default(false);
            $t->timestamp('trial_ends_at')->nullable();
            $t->json('settings')->nullable();
        });
        DB::table('tenants')->update(['trial_ends_at' => now()->addDays(14)]);
        Schema::table('contacts', function (Blueprint $t) {
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->json('tags')->nullable();
            $t->json('custom_fields')->nullable();
        });
        Schema::create('integrations', function (Blueprint $t) {
            $this->owned($t);
            $t->uuid('public_id')->unique();
            $t->string('provider', 30);
            $t->text('credentials');
            $t->json('config')->nullable();
            $t->string('status', 30)->default('not_connected');
            $t->string('last_error')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->unique(['tenant_id', 'provider']);
        });
        Schema::create('knowledge_entries', function (Blueprint $t) {
            $this->owned($t);
            $t->string('title', 160);
            $t->string('kind', 30)->default('faq');
            $t->longText('content');
            $t->boolean('approved')->default(false);
            $t->string('file_path')->nullable();
            $t->index(['tenant_id', 'approved']);
        });
        Schema::create('ai_usages', function (Blueprint $t) {
            $this->owned($t);
            $t->string('provider', 30);
            $t->string('model', 120);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->decimal('estimated_usd', 12, 6)->default(0);
            $t->string('status', 20)->default('reserved');
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('consent_events', function (Blueprint $t) {
            $this->owned($t);
            $this->contact($t);
            $t->string('channel', 20);
            $t->boolean('granted');
            $t->string('evidence', 500);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('suppressions', function (Blueprint $t) {
            $this->owned($t);
            $t->string('channel', 20);
            $t->string('address');
            $t->string('reason', 160);
            $t->unique(['tenant_id', 'channel', 'address']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $this->owned($t);
            $this->contact($t);
            $t->string('channel', 20);
            $t->string('subject', 200)->nullable();
            $t->string('status', 30)->default('open');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_inbound_at')->nullable();
            $t->text('summary')->nullable();
            $t->unique(['tenant_id', 'contact_id', 'channel']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('conversation_id');
            $t->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $t->uuid('idempotency_key')->unique();
            $t->string('provider_id')->nullable();
            $t->string('direction', 20);
            $t->text('body');
            $t->json('metadata')->nullable();
            $t->string('status', 30)->default('queued');
            $t->string('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['tenant_id', 'provider_id']);
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('message_templates', function (Blueprint $t) {
            $this->owned($t);
            $t->string('channel', 20);
            $t->string('name', 120);
            $t->string('language', 20)->default('en_US');
            $t->text('body');
            $t->string('approval_status', 30)->default('local_draft');
            $t->unique(['tenant_id', 'channel', 'name']);
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 160);
            $t->string('subject', 200);
            $t->text('body');
            $t->string('segment', 30)->default('all');
            $t->string('status', 30)->default('draft');
            $t->timestamp('scheduled_at')->nullable();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('campaign_recipients', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('campaign_id');
            $t->foreign(['tenant_id', 'campaign_id'])->references(['tenant_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $this->contact($t);
            $t->unsignedBigInteger('message_id')->nullable();
            $t->unique(['tenant_id', 'campaign_id', 'contact_id']);
        });
        Schema::create('workflows', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name', 160);
            $t->string('trigger', 30);
            $t->string('score_condition', 20)->nullable();
            $t->json('steps');
            $t->boolean('active')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('workflow_runs', function (Blueprint $t) {
            $this->owned($t);
            $t->unsignedBigInteger('workflow_id');
            $t->foreign(['tenant_id', 'workflow_id'])->references(['tenant_id', 'id'])->on('workflows')->cascadeOnDelete();
            $this->contact($t);
            $t->string('event_key', 120);
            $t->json('steps');
            $t->unsignedInteger('position')->default(0);
            $t->string('status', 30)->default('queued');
            $t->string('error')->nullable();
            $t->timestamp('next_at')->nullable();
            $t->unique(['tenant_id', 'workflow_id', 'event_key']);
            $t->index(['status', 'next_at']);
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('interval', 20);
            $t->unsignedInteger('price_paise');
            $t->string('razorpay_plan_id')->nullable();
            $t->unsignedInteger('monthly_messages')->default(1000);
            $t->unsignedInteger('contact_limit')->default(10000);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $this->owned($t);
            $t->foreignId('plan_id')->constrained()->restrictOnDelete();
            $t->string('provider_id')->unique();
            $t->string('status', 30)->default('created');
            $t->timestamp('current_end')->nullable();
            $t->string('checkout_url')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $this->owned($t);
            $t->string('provider_id')->unique();
            $t->string('subscription_provider_id');
            $t->unsignedBigInteger('amount_paise');
            $t->string('currency', 3);
            $t->string('status', 30);
            $t->string('invoice_id')->nullable();
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->string('event_id', 160);
            $t->longText('payload');
            $t->timestamp('processed_at')->nullable();
            $t->string('error')->nullable();
            $t->unique(['provider', 'event_id']);
            $t->timestamps();
        });
        Schema::create('team_invitations', function (Blueprint $t) {
            $this->owned($t);
            $t->string('email');
            $t->string('role', 30);
            $t->string('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->timestamp('accepted_at')->nullable();
        });
    }

    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $t->timestamps();
    }

    private function contact(Blueprint $t): void
    {
        $t->unsignedBigInteger('contact_id');
        $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
    }

    public function down(): void
    {
        foreach (['team_invitations', 'webhook_events', 'payments', 'subscriptions', 'plans', 'workflow_runs', 'workflows', 'campaign_recipients', 'campaigns', 'message_templates', 'messages', 'conversations', 'suppressions', 'consent_events', 'ai_usages', 'knowledge_entries', 'integrations'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropColumn(['tags', 'custom_fields']);
        });
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['suspended', 'trial_ends_at', 'settings']));
    }
};
