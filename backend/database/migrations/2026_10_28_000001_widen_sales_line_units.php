<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las unidades pueden ser fraccionarias con hasta 16 decimales (antes 4).
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->decimal('units', 34, 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->decimal('units', 18, 4)->change();
        });
    }
};
