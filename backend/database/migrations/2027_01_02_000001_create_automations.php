<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado y parámetros vigentes de cada automatización (lo que no está aquí usa los valores por omisión del catálogo).
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->string('automation', 40)->primary();
            $table->boolean('enabled');
            $table->json('params')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // Registro de cada ejecución. Una ejecución programada se identifica por su periodo (p. ej. el día), de modo que no se
        // repite aunque el programador corra varias veces; los reintentos de una ejecución fallida reutilizan la misma fila.
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('automation', 40);
            $table->string('trigger', 10);                  // schedule | manual
            $table->string('period_key', 30)->nullable();
            $table->string('status', 10);                   // running | ok | failed
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unique(['automation', 'period_key']);
            $table->index(['automation', 'started_at']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_settings');
    }
};
