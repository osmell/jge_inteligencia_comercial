<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notas de crédito capturadas en pantalla: documento del cliente (total = suma de lo aplicado a facturas).
        // Inmutables: una nota mal capturada se anula completa con movimientos de anulación.
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('series', 50);
            $table->string('number', 50);
            $table->date('issued_on');
            $table->string('customer_code');
            $table->string('customer_name');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('total', 18, 4);
            $table->string('observations', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['company_id', 'series', 'number']);
            $table->index(['company_id', 'customer_code']);
        });

        Schema::table('credit_movements', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->after('reverses_id')->constrained('credit_notes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_note_id');
        });
        Schema::dropIfExists('credit_notes');
    }
};
