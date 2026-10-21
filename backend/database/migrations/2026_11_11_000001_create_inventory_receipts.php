<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documento de entrada de inventario (compra a un proveedor). Compañía + proveedor + documento lo identifican; es inmutable:
        // una corrección es una reversa de sus movimientos, no una edición.
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('supplier');
            $table->string('supplier_key');
            $table->string('document', 100);
            $table->date('received_on');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_total', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('total', 18, 4);
            $table->unsignedInteger('lines_count');
            $table->string('content_hash', 64);
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['company_id', 'supplier_key', 'document']);
            $table->index(['company_id', 'branch_id', 'received_on']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('receipt_id')->nullable()->after('import_batch_id')->constrained('inventory_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipt_id');
        });
        Schema::dropIfExists('inventory_receipts');
    }
};
