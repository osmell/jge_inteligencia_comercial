<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opciones del menú que la administración le ocultó a esta persona (llaves de MenuCatalog). Vacío o nulo: ve todo lo que su rol permite.
        Schema::table('users', function (Blueprint $table) {
            $table->json('hidden_menu')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hidden_menu');
        });
    }
};
