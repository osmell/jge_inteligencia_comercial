<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facturas de crédito: la misma factura de venta (compañía + serie + número), con sus importes de documento.
        // Moneda única (NIO). Inmutables: una corrección es un movimiento, no una edición.
        Schema::create('credit_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('series', 50);
            $table->string('number', 50);
            $table->date('issued_on');
            $table->string('customer_code');
            $table->string('customer_name');
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->constrained()->restrictOnDelete();
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('total', 18, 4);
            // Se enlaza con la factura de venta cuando esta ya está cargada.
            $table->foreignId('sales_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['company_id', 'series', 'number']);
            $table->index(['company_id', 'customer_code']);
            $table->index(['company_id', 'issued_on']);
        });

        // Libro de movimientos: el saldo de una factura es la suma de sus movimientos, nunca se edita.
        // Importe con signo: la factura suma; abonos, notas de crédito y anulaciones de cargos restan.
        Schema::create('credit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_invoice_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 18, 4);
            $table->date('occurred_on');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('credit_movements')->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['credit_invoice_id', 'occurred_on']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_movements');
        Schema::dropIfExists('credit_invoices');
    }
};
