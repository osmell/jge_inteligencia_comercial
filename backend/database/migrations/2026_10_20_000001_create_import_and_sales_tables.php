<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operadores (facturadores) y vendedores del archivo de ventas, por compañía.
        // El nombre normalizado (minúsculas, sin espacios sobrantes) evita duplicados por mayúsculas.
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'normalized_name']);
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            // Vínculo opcional con un usuario del sistema (se define más adelante).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'normalized_name']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->string('original_name');
            $table->string('stored_path');
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('documents_total')->default(0);
            $table->unsignedInteger('documents_new')->default(0);
            $table->unsignedInteger('documents_duplicate')->default(0); // ya importados con contenido idéntico
            $table->unsignedInteger('errors_total')->default(0);
            $table->unsignedInteger('warnings_total')->default(0);
            $table->jsonb('summary')->nullable();        // vista previa y conteos por compañía
            $table->text('failure_message')->nullable();

            $table->timestamp('validated_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'file_hash']);
        });

        // Un mismo archivo (hash) solo puede estar importado una vez por tipo.
        DB::statement("create unique index import_batches_imported_hash_unique on import_batches (type, file_hash) where status = 'imported'");

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('column')->nullable();
            $table->string('code');
            $table->string('severity')->default('error'); // error | warning
            $table->text('message');
            $table->string('value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_batch_id', 'severity']);
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // Texto: se conservan ceros iniciales. Identificador único: compañía + serie + documento.
            $table->string('series');
            $table->string('number');
            $table->date('issued_on');
            $table->timestamp('issued_at')->nullable(); // solo si el archivo trae hora
            $table->string('customer_code');
            $table->string('customer_name');
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('lines_count');
            $table->char('content_hash', 64); // distingue reimportación idéntica de un conflicto
            $table->timestamps();

            $table->unique(['company_id', 'series', 'number']);
            $table->index(['company_id', 'issued_on']);
            $table->index(['branch_id', 'issued_on']);
            $table->index('customer_code');
            $table->index('seller_id');
            $table->index('operator_id');
            $table->index('import_batch_id');
        });

        Schema::create('sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->restrictOnDelete();
            // Copiados del documento para consultas por compañía, sucursal y fecha sin unir tablas.
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('issued_on');

            $table->unsignedInteger('line_number');
            $table->string('product_code');
            $table->string('product_name');
            $table->string('category_name'); // «Nombre Nivel 2 Inv» = categoría

            // Importes tal como vienen en el archivo (sin recalcular), con 4 decimales exactos.
            $table->decimal('units', 18, 4);
            $table->decimal('unit_cost', 18, 4);       // costo unitario ACTUAL, no histórico
            $table->decimal('line_cost_total', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('normal_price', 18, 4)->nullable();
            $table->decimal('net_total', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('sale_plus_tax', 18, 4);
            $table->decimal('grand_total', 18, 4);

            $table->timestamps();

            $table->unique(['sales_document_id', 'line_number']);
            $table->index(['company_id', 'issued_on']);
            $table->index(['branch_id', 'issued_on']);
            $table->index('product_code');
            $table->index('category_name');
        });
    }

    public function down(): void
    {
        foreach (['sales_lines', 'sales_documents', 'import_errors', 'import_batches', 'sellers', 'operators'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
