<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de productos. El código es único por compañía; un mismo producto se vende en varias sucursales.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('level1')->nullable(); // grupo general
            $table->string('level2')->nullable(); // línea
            $table->string('level3')->nullable(); // categoría
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
