<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Vendedor del cliente al crear la cotización (para estadísticas aunque luego se reasigne el cliente).
            $table->foreignId('seller_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('number', 30);
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('amount', 18, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('loss_reason', 20)->nullable();
            $table->string('loss_note', 500)->nullable();
            // Vínculo explícito con la factura: una cotización aceptada no es una venta hasta que exista el documento.
            $table->foreignId('sales_document_id')->nullable()->unique()->constrained('sales_documents')->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sequence']);
            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('quotation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['quotation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_events');
        Schema::dropIfExists('quotations');
    }
};
