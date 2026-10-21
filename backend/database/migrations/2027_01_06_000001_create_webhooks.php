<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webhook de salida (una sola fila): a dónde avisar los eventos del sistema. El secreto de firma se guarda cifrado.
        Schema::create('webhook_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('url', 500)->nullable();
            $table->text('secret')->nullable();
            $table->json('events')->nullable();          // tipos de aviso que se envían
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('dedupe_key', 190)->unique();
            $table->text('payload');                      // JSON exacto que se firma y se envía
            $table->string('status', 10);                 // pending | sending | sent | failed
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_settings');
    }
};
