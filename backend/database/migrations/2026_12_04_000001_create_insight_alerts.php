<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alertas de clientes (riesgo de inactividad, cambios anormales de venta y líneas abandonadas). Son recomendaciones: cada una
        // guarda los datos que la generaron. Mientras la condición sigue vigente la alerta sigue abierta; si deja de cumplirse se marca
        // como «resuelta» sola; una alerta cerrada por una persona (con motivo) no se vuelve a abrir por el mismo hecho.
        Schema::create('insight_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('insight_run_id')->constrained('insight_runs')->cascadeOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');
            $table->string('type', 20);
            $table->string('severity', 10);
            $table->string('dedupe_key', 150);
            $table->json('facts');
            $table->string('explanation', 1000);
            $table->string('status', 10)->default('open');      // open | resolved | closed
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 30)->nullable();
            $table->string('close_note', 500)->nullable();

            $table->unique(['company_id', 'dedupe_key']);
            $table->index(['company_id', 'status', 'type']);
            $table->index(['company_id', 'customer_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_alerts');
    }
};
