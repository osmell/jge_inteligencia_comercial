<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos internos (la campana de la aplicación). La clave de deduplicación identifica el hecho que se avisa, de modo que
        // reintentos y recálculos no repiten el aviso; uno descartado no vuelve a aparecer por el mismo hecho.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('severity', 10)->default('info');       // info | warning | danger
            $table->string('title');
            $table->string('body', 1000)->nullable();
            $table->string('url')->nullable();
            $table->string('dedupe_key', 150);
            $table->string('status', 10)->default('new');          // new | seen | dismissed
            $table->timestamp('created_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->boolean('enabled');
            $table->primary(['user_id', 'type']);
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('paused')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_notifications');
    }
};
