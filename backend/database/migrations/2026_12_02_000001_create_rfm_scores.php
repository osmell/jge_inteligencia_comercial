<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resultado de la segmentación RFM de cada cliente en el último cálculo de su compañía (se reemplaza en cada cálculo; el
        // registro del cálculo, con sus parámetros, queda en insight_runs).
        Schema::create('rfm_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_run_id')->constrained('insight_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');
            $table->date('first_purchase');
            $table->date('last_purchase');
            $table->unsignedInteger('recency_days');
            $table->unsignedInteger('frequency');
            $table->decimal('gross', 18, 4);
            $table->decimal('returns', 18, 4);
            $table->decimal('monetary', 18, 4);
            $table->unsignedTinyInteger('r');
            $table->unsignedTinyInteger('f');
            $table->unsignedTinyInteger('m');
            $table->string('segment', 20);

            $table->index(['company_id', 'segment']);
            $table->index(['company_id', 'customer_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfm_scores');
    }
};
