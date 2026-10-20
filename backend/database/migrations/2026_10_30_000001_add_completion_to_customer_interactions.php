<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La próxima acción de un contacto queda pendiente hasta que alguien la marca como cumplida (alimenta la agenda).
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->timestamp('next_action_done_at')->nullable();
            $table->foreignId('next_action_done_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_action_done_by');
            $table->dropColumn('next_action_done_at');
        });
    }
};
