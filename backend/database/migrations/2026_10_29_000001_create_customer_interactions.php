<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contactos comerciales con un cliente (llamada, visita, WhatsApp manual…). No se borran: se corrigen y queda auditoría.
        Schema::create('customer_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('direction', 10)->default('outbound');
            $table->string('outcome', 30);
            $table->timestamp('occurred_at');
            $table->text('response')->nullable();
            $table->string('next_action', 500)->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'occurred_at']);
            $table->index(['company_id', 'next_action_at']);
        });

        // Solicitud del cliente de no recibir comunicaciones: bloquea contactos salientes y próximas acciones.
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('do_not_contact')->default(false);
            $table->string('do_not_contact_reason', 500)->nullable();
            $table->timestamp('do_not_contact_at')->nullable();
            $table->foreignId('do_not_contact_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('do_not_contact_by');
            $table->dropColumn(['do_not_contact', 'do_not_contact_reason', 'do_not_contact_at']);
        });
        Schema::dropIfExists('customer_interactions');
    }
};
