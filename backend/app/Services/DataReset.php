<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deja la base lista para empezar con información real: conserva el maestro (compañías, sucursales, vendedores, facturadores, productos),
 * las cuentas con sus roles y la configuración, y borra todo lo transaccional (ventas, clientes, cotizaciones, conversaciones, avisos…).
 *
 * Cada tabla está clasificada a propósito: una tabla nueva sin clasificar hace fallar la prueba y el comando se niega a ejecutarse,
 * para que nunca se borre —ni se conserve— algo por omisión.
 */
class DataReset
{
    /** Se conservan tal cual. */
    public const KEEP = [
        // Maestro
        'companies', 'branches', 'sellers', 'operators', 'products', 'product_units', 'units',
        // Cuentas, roles y a qué compañías/sucursales llega cada cuenta
        'users', 'roles', 'permissions', 'permission_role', 'company_user', 'branch_user',
        // Configuración (incluye credenciales cifradas de correo y WhatsApp) y catálogos que administración mantiene a mano
        'app_settings', 'mail_settings', 'whatsapp_settings', 'webhook_settings', 'notification_settings', 'automation_settings',
        'notification_preferences', 'whatsapp_templates', 'quick_replies', 'customer_types',
        // Del framework
        'migrations', 'sessions',
    ];

    /** Se borran siempre: todo dato que nace de operar el sistema. */
    public const WIPE = [
        'sales_line_corrections', 'sales_document_voids', 'sales_documents', 'sales_lines', 'billing_detail_lines', 'seller_sales_summaries', 'import_batches', 'import_errors', 'seller_goals',
        'customers', 'customer_interactions',
        'quotations', 'quotation_events',
        'credit_invoices', 'credit_movements', 'credit_notes', 'payment_promises',
        'inventory_balances', 'inventory_movements', 'inventory_receipts', 'inventory_shortfalls',
        'insight_runs', 'insight_alerts', 'rfm_scores', 'cross_sell_rules', 'cross_sell_opportunities',
        'user_notifications', 'digests', 'automation_runs', 'mail_log',
        'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_attachments', 'whatsapp_consents',
        'conversation_labels', 'conversation_notes', 'conversation_reads', 'webhook_deliveries',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /** Historial de acciones: se borra, salvo que se pida conservarlo. */
    public const AUDIT = 'audit_logs';

    /** Carpetas de archivos privados que cuelgan de lo que se borra (adjuntos de conversaciones y PDF de cotizaciones). */
    private const FILE_DISKS = ['conversations', 'quotations'];

    /** @return list<string> tablas de la base que no están clasificadas ni como conservadas ni como borradas */
    public function unclassified(): array
    {
        $known = [...self::KEEP, ...self::WIPE, self::AUDIT];

        return array_values(array_diff(array_column(Schema::getTables(), 'name'), $known));
    }

    /**
     * @return array<string, int> tabla → filas que se borrarían
     */
    public function plan(bool $keepAudit): array
    {
        $plan = [];
        foreach ($this->toWipe($keepAudit) as $table) {
            $plan[$table] = DB::table($table)->count();
        }

        return $plan;
    }

    /** @return array<string, int> tabla → filas que se conservan */
    public function kept(bool $keepAudit): array
    {
        $kept = [];
        foreach ([...self::KEEP, ...($keepAudit ? [self::AUDIT] : [])] as $table) {
            if (Schema::hasTable($table)) {
                $kept[$table] = DB::table($table)->count();
            }
        }

        return $kept;
    }

    /** Borra en una sola transacción: o queda todo limpio o no se toca nada. */
    public function run(bool $keepAudit): void
    {
        $tables = $this->toWipe($keepAudit);

        DB::transaction(function () use ($tables): void {
            if (DB::getDriverName() === 'pgsql') {
                // Sin CASCADE: si alguna tabla conservada dependiera de una que se borra, falla y revierte en vez de arrastrarla.
                DB::statement('TRUNCATE TABLE '.implode(', ', array_map(fn (string $t): string => '"'.$t.'"', $tables)).' RESTART IDENTITY');

                return;
            }
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        });

        foreach (self::FILE_DISKS as $disk) {
            $storage = Storage::disk($disk);
            foreach ($storage->directories() as $directory) {
                $storage->deleteDirectory($directory);
            }
            foreach ($storage->files() as $file) {
                $storage->delete($file);
            }
        }
    }

    /** @return list<string> */
    private function toWipe(bool $keepAudit): array
    {
        return array_values(array_filter(
            [...self::WIPE, ...($keepAudit ? [] : [self::AUDIT])],
            fn (string $table): bool => Schema::hasTable($table),
        ));
    }
}
