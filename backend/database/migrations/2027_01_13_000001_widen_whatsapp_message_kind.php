<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El tipo «conversation» (mensaje escrito desde la pantalla de Conversaciones) mide 12 caracteres y la columna admitía 10: en
        // PostgreSQL —a diferencia de SQLite, que no impone el largo— enviar cualquier mensaje desde ahí fallaba con un error de base de datos.
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('kind', 20)->default('template')->change();
        });
    }

    public function down(): void
    {
        // No se vuelve a acortar: podría truncar mensajes ya guardados.
    }
};
