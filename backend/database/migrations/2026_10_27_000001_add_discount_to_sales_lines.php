<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Sub Total» (venta antes de descuento) y «Descuento» de la línea; Total Neto = Sub Total − Descuento. Nulos si el archivo no los trae.
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->decimal('subtotal', 18, 4)->nullable()->after('normal_price');
            $table->decimal('discount_total', 18, 4)->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_total']);
        });
    }
};
