<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            // Vendedor «Tienda» por defecto de una sucursal (a él se atribuyen los clientes sin vendedor) y supervisor responsable.
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->restrictOnDelete();
        });

        // Un vendedor «Tienda <sucursal>» por cada sucursal existente (si ya hay uno con ese nombre, se reutiliza).
        $now = now();
        foreach (DB::table('branches')->orderBy('id')->get() as $branch) {
            $name = 'Tienda '.$branch->name;
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
            $existing = DB::table('sellers')->where('company_id', $branch->company_id)->where('normalized_name', $normalized)->first();
            if ($existing) {
                DB::table('sellers')->where('id', $existing->id)->update(['branch_id' => $branch->id, 'is_default' => true]);
            } else {
                DB::table('sellers')->insert([
                    'company_id' => $branch->company_id, 'name' => $name, 'normalized_name' => $normalized, 'is_active' => true,
                    'branch_id' => $branch->id, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropConstrainedForeignId('operator_id');
        });
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn('is_default');
        });
    }
};
