<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('suspended')->default(false));
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->json('flags');
            $t->timestamps();
        });
        Schema::create('report_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('period_key', 80);
            $t->string('file_path');
            $t->timestamps();
            $t->unique(['tenant_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('suspended'));
    }
};
