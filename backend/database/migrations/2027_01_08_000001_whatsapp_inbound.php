<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El archivo de un mensaje entrante se descarga después de guardar el mensaje: queda «pendiente» hasta tenerlo en disco.
        Schema::table('whatsapp_attachments', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('mime', 100)->nullable()->change();
            $table->unsignedBigInteger('size')->nullable()->change();
            $table->string('status', 10)->default('stored');          // pending | stored | failed
            $table->string('error', 500)->nullable();
        });

        // Compañía donde caen los mensajes de números que no son de ningún cliente.
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->foreignId('unassigned_company_id')->nullable()->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unassigned_company_id');
        });
        Schema::table('whatsapp_attachments', function (Blueprint $table) {
            $table->dropColumn(['status', 'error']);
        });
    }
};
