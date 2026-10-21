<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promesa de pago de un cliente: monto y fecha prometidos. Su resultado (cumplida/incumplida) no se guarda:
        // se deriva de los abonos registrados entre la fecha de la promesa y la fecha prometida. Solo se puede cancelar.
        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');
            $table->date('made_on');
            $table->date('promised_on');
            $table->decimal('amount', 18, 4);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_code']);
            $table->index(['promised_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promises');
    }
};
