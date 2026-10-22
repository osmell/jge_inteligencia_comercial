<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuenta de WhatsApp Business Platform (Cloud API). Las credenciales se guardan cifradas y no se devuelven nunca.
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('api_version', 10)->default('v21.0');
            $table->text('access_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->text('verify_token')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('language', 10)->default('es');
            $table->string('category', 20);                 // utility | marketing | authentication
            $table->text('body');                           // con {{1}}, {{2}}… como en Meta
            $table->string('status', 10)->default('draft'); // draft | pending | approved | rejected
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });

        // Consentimiento por cliente: cada cambio es una fila (la vigente es la última). Nunca se edita.
        Schema::create('whatsapp_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);                   // granted | revoked
            $table->string('source', 300);                  // cómo se obtuvo o por qué se retiró
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['customer_id', 'id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_number', 30)->nullable();
            $table->text('body');
            $table->string('kind', 10)->default('template');  // template | test
            $table->string('status', 10);                     // sent | delivered | read | failed | not_sent
            $table->string('wa_message_id')->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->string('dedupe_key', 100)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->unique(['customer_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_consents');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_settings');
    }
};
