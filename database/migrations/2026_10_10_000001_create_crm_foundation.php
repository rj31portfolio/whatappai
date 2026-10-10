<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_super_admin')->default(false));
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('industry');
            $table->char('currency', 3)->default('INR');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', array_column(\App\Enums\Role::cases(), 'value'));
            $table->unique(['tenant_id', 'user_id']);
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('website')->nullable();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->enum('source', \App\Models\Contact::SOURCES)->default('other');
            $table->enum('score', \App\Models\Contact::SCORES)->default('cold');
            $table->string('score_reason');
            $table->text('notes')->nullable();
            $table->boolean('email_consent')->default(false);
            $table->boolean('whatsapp_consent')->default(false);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'score']);
            $table->index(['tenant_id', 'source']);
            $table->timestamps();
        });
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
            $table->string('title', 160);
            $table->decimal('amount', 14, 2);
            $table->enum('stage', \App\Models\Deal::STAGES)->default('new');
            $table->date('expected_close_at')->nullable();
            $table->index(['tenant_id', 'stage']);
            $table->timestamps();
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts')->cascadeOnDelete();
            $table->string('title', 160);
            $table->dateTime('due_at');
            $table->dateTime('completed_at')->nullable();
            $table->index(['tenant_id', 'due_at']);
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'tasks', 'deals', 'contacts', 'companies', 'memberships', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_super_admin'));
    }
};
