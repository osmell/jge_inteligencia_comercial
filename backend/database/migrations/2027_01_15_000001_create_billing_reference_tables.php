<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Detalle de facturación» del sistema de origen: una fila por línea de factura con el precio establecido en la factura.
        // Es una referencia para corregir las equivalencias; no reemplaza a las ventas importadas.
        Schema::create('billing_detail_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->string('document');          // «Docu. No.»: único en la compañía sin importar la serie
            $table->unsignedInteger('line_number');
            $table->string('series');            // tal como lo emite el reporte (lo distorsiona; no identifica la factura)
            $table->date('issued_on');
            $table->string('customer_name');
            $table->string('product_code');
            $table->string('product_name');
            $table->decimal('units', 34, 16);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('price', 18, 4);     // precio establecido en la factura
            $table->decimal('sale_total', 18, 4);
            $table->decimal('cost_total', 18, 4);
            $table->decimal('profit_total', 18, 4);
            $table->char('content_hash', 64);    // huella del documento, igual en todas sus líneas
            $table->timestamps();

            $table->unique(['company_id', 'document', 'line_number']);
            $table->index(['company_id', 'issued_on']);
            $table->index('import_batch_id');
        });

        // «Ventas por vendedor (resumen)» del sistema de origen: totales oficiales acumulados desde el inicio del período hasta la fecha de corte.
        Schema::create('seller_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('cutoff_on');
            $table->string('seller_name');
            $table->string('normalized_name');
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->decimal('gross_sale', 18, 4);
            $table->decimal('discount', 18, 4);
            $table->decimal('sale', 18, 4);
            $table->decimal('returns', 18, 4);
            $table->decimal('net_sale', 18, 4);
            $table->decimal('tax', 18, 4);
            $table->decimal('withholding', 18, 4);
            $table->decimal('total', 18, 4);
            $table->unsignedInteger('invoices_count');
            $table->timestamps();

            $table->unique(['company_id', 'cutoff_on', 'normalized_name']);
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_sales_summaries');
        Schema::dropIfExists('billing_detail_lines');
    }
};
