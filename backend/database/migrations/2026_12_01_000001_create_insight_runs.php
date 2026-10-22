<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de cada cálculo de inteligencia comercial: qué análisis, de qué compañía y periodo, con qué parámetros y cuándo.
        // Sirve para rastrear cada recomendación hasta las reglas y los datos que la generaron.
        Schema::create('insight_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('analysis', 30);
            $table->string('status', 15);               // ok | insufficient
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('parameters');
            $table->json('summary')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('computed_at');

            $table->index(['company_id', 'analysis', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_runs');
    }
};
