<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una conversación por compañía y número de WhatsApp del cliente. Sin cliente (customer_id nulo) = número desconocido
        // en la bandeja «sin asignar», hasta que supervisión la asigne o cree el cliente.
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_number', 30);                       // solo dígitos, con código de país
            $table->string('profile_name')->nullable();            // nombre que trae Meta del contacto
            $table->string('status', 10)->default('open');         // open | archived (nunca se borra)
            $table->string('last_message_preview', 200)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();      // inicia la ventana de 24 horas
            $table->timestamp('created_at');

            $table->unique(['company_id', 'wa_number']);
            $table->index(['customer_id']);
            $table->index(['status', 'last_message_at']);
        });

        // Los mensajes de la conversación (entrantes y salientes) son el mismo registro de WhatsApp que ya existía para las plantillas.
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->restrictOnDelete();
            $table->string('direction', 3)->default('out');          // in | out
            $table->string('message_type', 12)->default('template'); // text | template | image | video | audio | document | location | reaction | unsupported
            $table->foreignId('reply_to_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->index(['conversation_id', 'id']);
            // Un mensaje de Meta se guarda una sola vez aunque el webhook se repita.
            $table->dropIndex(['wa_message_id']);
            $table->unique('wa_message_id');
        });

        Schema::create('whatsapp_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('whatsapp_messages')->restrictOnDelete();
            $table->string('kind', 10);                              // image | video | audio | document
            $table->string('path');                                  // relativa al disco privado «conversations»; nombre generado, nunca el del cliente
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->nullable();
            $table->string('media_id')->nullable();                  // identificador del archivo en Meta
            $table->timestamp('created_at');

            $table->index('message_id');
        });

        // Notas internas del equipo: nunca se envían al cliente.
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at');

            $table->index(['conversation_id', 'id']);
        });

        // Hasta qué mensaje leyó cada persona (para el contador de no leídos).
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('conversation_notes');
        Schema::dropIfExists('whatsapp_attachments');
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique(['wa_message_id']);
            $table->index('wa_message_id');
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn(['direction', 'message_type']);
        });
        Schema::dropIfExists('whatsapp_conversations');
    }
};
