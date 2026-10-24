<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Época de sesión»: cada sesión guarda la época con la que se abrió; subirla invalida de golpe todas las sesiones anteriores de la persona.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('session_epoch')->default(0)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_epoch');
        });
    }
};
