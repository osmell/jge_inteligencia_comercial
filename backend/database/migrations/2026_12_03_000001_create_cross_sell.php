<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reglas de asociación entre categorías («quien compra A también compra B») del último cálculo de cada compañía, con los
        // conteos que las respaldan. Los cálculos anteriores quedan solo en insight_runs.
        Schema::create('cross_sell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_run_id')->constrained('insight_runs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('antecedent');
            $table->string('consequent');
            $table->unsignedInteger('antecedent_customers');
            $table->unsignedInteger('consequent_customers');
            $table->unsignedInteger('both_customers');
            $table->unsignedInteger('total_customers');
            $table->decimal('confidence', 7, 4);
            $table->decimal('lift', 8, 4);

            $table->index(['company_id', 'confidence']);
        });

        // Oportunidad por cliente: compra el antecedente y todavía no el consecuente.
        Schema::create('cross_sell_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_run_id')->constrained('insight_runs')->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('cross_sell_rules')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('customer_code');
            $table->string('customer_name');

            $table->index(['company_id', 'customer_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_sell_opportunities');
        Schema::dropIfExists('cross_sell_rules');
    }
};
