<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facturas anuladas según el detalle de facturación (el reporte repite su original con serie «FA»). El número identifica la factura
        // en la compañía; la venta puede no existir (el archivo de ventas deja en cero las anuladas) o cargarse después.
        Schema::create('sales_document_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document');
            $table->foreignId('sales_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete(); // lote del detalle que la detectó
            $table->timestamps();

            $table->unique(['company_id', 'document']);
            $table->index('sales_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_voids');
    }
};
