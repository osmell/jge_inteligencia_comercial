<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resúmenes para gerencia (diario y semanal) de cada compañía. Cada uno se genera una sola vez por periodo y guarda las cifras
        // tal como estaban al generarse: el historial permite comparar y no cambia si los datos se corrigen después.
        Schema::create('digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);                 // daily | weekly
            $table->string('period_key', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('title');
            $table->json('data');
            $table->timestamp('generated_at');

            $table->unique(['company_id', 'kind', 'period_key']);
            $table->index(['kind', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digests');
    }
};
