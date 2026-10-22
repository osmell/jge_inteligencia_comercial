<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ventas ya facturadas que no pudieron descontar toda su cantidad de las existencias (no hay existencias en negativo):
        // lo que faltó queda aquí para conciliarse registrando la entrada o el ajuste que falta. No se toca la venta.
        Schema::create('inventory_shortfalls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sales_line_id')->unique();
            $table->string('product_code');
            $table->date('issued_on');
            $table->decimal('requested', 24, 8);
            $table->decimal('applied', 24, 8);
            $table->decimal('missing', 24, 8);
            $table->string('reason', 30);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_shortfalls');
    }
};
