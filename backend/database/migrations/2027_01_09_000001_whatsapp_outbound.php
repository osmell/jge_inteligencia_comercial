<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clave que genera la pantalla por cada envío: si la petición se repite (doble clic, red lenta) no se manda dos veces.
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('client_key', 64)->nullable();
            $table->unique(['conversation_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
