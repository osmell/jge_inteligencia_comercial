<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costo de las líneas de componente (catalizador, reductor, thinner: precio simbólico menor a 1) reasignado a la línea principal de su
        // factura. Las líneas de venta no se tocan: aquí queda a qué línea se le suma cada costo.
        Schema::create('sales_line_component_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_line_id')->unique()->constrained('sales_lines')->cascadeOnDelete();
            $table->foreignId('main_line_id')->constrained('sales_lines')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->decimal('cost', 18, 4);
            $table->unsignedSmallInteger('rule_version');
            $table->timestamps();

            $table->index('main_line_id');
            $table->index(['company_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_line_component_costs');
    }
};
