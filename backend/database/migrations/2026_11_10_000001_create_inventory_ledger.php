<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de unidades de medida (del grupo). Las fracciones del galón (octavo, 1/16…) son unidades con equivalencia.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('units')->insert(array_map(fn (array $u) => $u + ['active' => true, 'created_at' => $now, 'updated_at' => $now], [
            ['code' => 'galon', 'name' => 'Galón'], ['code' => 'barril', 'name' => 'Barril'], ['code' => 'cubeta', 'name' => 'Cubeta'],
            ['code' => 'litro', 'name' => 'Litro'], ['code' => 'octavo', 'name' => 'Octavo (1/8)'], ['code' => '1_16', 'name' => '1/16'],
            ['code' => '1_32', 'name' => '1/32'], ['code' => '1_64', 'name' => '1/64'], ['code' => 'unidad', 'name' => 'Unidad'],
        ]));

        // Unidad base de cada producto (en la que se lleva el inventario). Sin ella el producto no admite movimientos.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('base_unit_id')->nullable()->after('level3')->constrained('units')->restrictOnDelete();
        });

        // Equivalencia específica de un producto: 1 unidad = factor_num / factor_den unidades base (exacto, sin decimales binarios).
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('factor_num');
            $table->unsignedBigInteger('factor_den');
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        // Libro de movimientos de inventario (inmutable). Cantidad y valor con signo, en la unidad base del producto.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('quantity', 24, 8);
            $table->decimal('value', 18, 4);
            $table->date('occurred_on');
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('entered_quantity', 24, 8);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $table->string('source_key', 150)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['company_id', 'source_key']);
            $table->index(['company_id', 'branch_id', 'product_id', 'occurred_on']);
        });

        // Saldo actual por compañía, sucursal y producto. Solo lo escribe el libro, en la misma transacción que el movimiento.
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('value', 18, 4)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_units');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_unit_id');
        });
        Schema::dropIfExists('units');
    }
};
