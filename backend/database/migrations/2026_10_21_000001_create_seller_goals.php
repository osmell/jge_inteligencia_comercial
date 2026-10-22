<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodo en que el vendedor cuenta para las metas (alta y baja). Nulo = sin límite.
        Schema::table('sellers', function (Blueprint $table) {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });

        Schema::create('seller_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // siempre el día 1
            $table->decimal('amount', 14, 2); // meta de venta neta del mes completo
            $table->unsignedSmallInteger('days_off')->default(0); // vacaciones, incapacidad o permiso
            $table->string('source', 12)->default('manual'); // manual | suggested
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seller_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_goals');
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
