<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->string('kind', 20)->default('task');
            $t->timestamp('reminded_at')->nullable();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('task_id');
            $t->foreign(['tenant_id', 'task_id'])->references(['tenant_id', 'id'])->on('tasks')->cascadeOnDelete();
            $t->string('title', 160);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'id']);
            $t->dropColumn(['kind', 'reminded_at']);
        });
    }
};
