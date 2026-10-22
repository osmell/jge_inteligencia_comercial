<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Respuestas rápidas: personales, o compartidas por supervisión con todo el equipo. No se borran: se desactivan.
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('shared')->default(false);
            $table->string('title', 60);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['is_active', 'shared']);
        });

        // Etiquetas de una conversación, de una lista fija (config/conversations.php).
        Schema::create('conversation_labels', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->restrictOnDelete();
            $table->string('label', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->primary(['conversation_id', 'label']);
        });

        // Mensaje de ausencia fuera del horario de atención.
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->boolean('away_enabled')->default(false);
            $table->text('away_message')->nullable();
            $table->unsignedTinyInteger('away_start_hour')->default(8);
            $table->unsignedTinyInteger('away_end_hour')->default(18);
            $table->string('away_days', 20)->default('1,2,3,4,5,6');   // días de atención (1 lunes … 7 domingo)
        });
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->timestamp('last_away_at')->nullable();
        });

        // Un contacto de Seguimiento creado por una conversación (para no registrar dos veces el mismo día).
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_interactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('conversation_id'));
        Schema::table('whatsapp_conversations', fn (Blueprint $t) => $t->dropColumn('last_away_at'));
        Schema::table('whatsapp_settings', fn (Blueprint $t) => $t->dropColumn(['away_enabled', 'away_message', 'away_start_hour', 'away_end_hour', 'away_days']));
        Schema::dropIfExists('conversation_labels');
        Schema::dropIfExists('quick_replies');
    }
};
