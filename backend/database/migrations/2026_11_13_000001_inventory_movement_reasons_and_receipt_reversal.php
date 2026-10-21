<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Motivo de los movimientos manuales (ajustes y devoluciones), de una lista fija.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reason', 30)->nullable()->after('type');
        });

        // Un documento de entrada se puede revertir completo. Mientras está vigente, active_key (compañía|proveedor|documento) es único;
        // al revertirlo queda en null y el mismo documento ya corregido se puede volver a cargar.
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->string('active_key', 400)->nullable()->unique();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('inventory_receipts')->orderBy('id')->get(['id', 'company_id', 'supplier_key', 'document'])->each(
            fn ($r) => DB::table('inventory_receipts')->where('id', $r->id)->update(['active_key' => "{$r->company_id}|{$r->supplier_key}|{$r->document}"]),
        );
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'supplier_key', 'document']);
            $table->index(['company_id', 'supplier_key', 'document']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'supplier_key', 'document']);
            $table->unique(['company_id', 'supplier_key', 'document']);
            $table->dropUnique(['active_key']);
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['active_key', 'reversed_at']);
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
