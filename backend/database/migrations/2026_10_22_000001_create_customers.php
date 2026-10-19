<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos maestros del cliente. El código es único por compañía (no en todo el grupo).
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('source', 10)->default('import'); // import (archivo maestro) | sales (creado al ver el código en una venta)
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('branch_id');
        });

        // Clientes que ya aparecen en ventas importadas antes de existir esta tabla.
        $now = now();
        DB::table('sales_documents')
            ->selectRaw('company_id, min(branch_id) as branch_id, customer_code as code, max(customer_name) as name')
            ->groupBy('company_id', 'customer_code')
            ->orderBy('company_id')->orderBy('customer_code')
            ->chunk(1000, function ($rows) use ($now) {
                DB::table('customers')->insert($rows->map(fn ($r) => [
                    'company_id' => $r->company_id, 'branch_id' => $r->branch_id, 'code' => $r->code, 'name' => $r->name,
                    'source' => 'sales', 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
