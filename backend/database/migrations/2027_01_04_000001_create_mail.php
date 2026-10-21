<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración del servidor de correo (una sola fila). La contraseña se guarda cifrada y no se devuelve nunca.
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(587);
            $table->string('encryption', 10)->default('starttls');     // starttls | ssl | none
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // Registro de cada correo: lo que se envió (o por qué no), a quién, con qué resultado. La clave de deduplicación evita
        // que un reintento o una repetición mande el mismo correo dos veces al mismo destinatario.
        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_address')->nullable();
            $table->string('kind', 12);                  // notice | test
            $table->string('subject');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->string('dedupe_key', 150)->nullable();
            $table->string('status', 10);                // pending | sending | sent | failed | not_sent
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('email')->nullable();       // null = valor por omisión del tipo
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('email');
        });
        Schema::dropIfExists('mail_log');
        Schema::dropIfExists('mail_settings');
    }
};
