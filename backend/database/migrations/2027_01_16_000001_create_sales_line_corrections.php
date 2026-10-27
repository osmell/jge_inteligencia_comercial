<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Neto corregido de las líneas vendidas por equivalencia (unidades menores a 1). Los importes de sales_lines no se tocan:
        // aquí queda lo derivado, con la causa de la corrección.
        Schema::create('sales_line_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_line_id')->unique()->constrained('sales_lines')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->string('cause');                   // portion: el reporte multiplicó otra vez por la fracción
            $table->decimal('corrected_net', 18, 4);   // neto real de la línea
            $table->decimal('corrected_tax', 18, 4);   // impuesto con la misma tasa de la línea
            $table->unsignedSmallInteger('rule_version');
            $table->timestamps();

            $table->index(['company_id', 'issued_on']);
            $table->index(['branch_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_line_corrections');
    }
};
