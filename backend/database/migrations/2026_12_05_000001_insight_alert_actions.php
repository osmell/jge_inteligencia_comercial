<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una recomendación puede generar (a petición de una persona) una acción en la agenda de seguimiento. La acción es una
        // entrada de seguimiento «planeada» que apunta a la alerta, y la alerta recuerda cuál es para no crear dos.
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->unsignedBigInteger('insight_alert_id')->nullable()->after('next_action_done_by');
        });
        Schema::table('insight_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('follow_up_interaction_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('insight_alerts', function (Blueprint $table) {
            $table->dropColumn('follow_up_interaction_id');
        });
        Schema::table('customer_interactions', function (Blueprint $table) {
            $table->dropColumn('insight_alert_id');
        });
    }
};
