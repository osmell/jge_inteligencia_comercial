<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costo vigente de las líneas que cambian por la reasignación de componentes: 0 en el componente y su costo más el de sus componentes
        // en la línea principal. Se une por llave única (como las correcciones del neto), sin recorrer sales_line_component_costs en cada consulta.
        Schema::create('sales_line_effective_costs', function (Blueprint $table) {
            $table->foreignId('sales_line_id')->primary()->constrained('sales_lines')->cascadeOnDelete();
            $table->decimal('cost', 18, 4);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_line_effective_costs');
    }
};
