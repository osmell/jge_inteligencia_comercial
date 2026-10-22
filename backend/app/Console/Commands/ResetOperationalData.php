<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\DataReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetOperationalData extends Command
{
    protected $signature = 'data:reset
        {--dry-run : Solo muestra qué se borraría y qué se conserva; no toca nada}
        {--keep-audit : Conserva también el historial de Auditoría}
        {--confirm= : Nombre de la base de datos, para ejecutar sin preguntar (scripts)}';

    protected $description = 'IRREVERSIBLE. Borra ventas, clientes, cotizaciones, conversaciones, etc. y conserva compañías, sucursales, vendedores, facturadores, productos, cuentas y configuración.';

    public function handle(DataReset $reset, AuditLogger $audit): int
    {
        $pending = $reset->unclassified();
        if ($pending !== []) {
            $this->error('Hay tablas sin clasificar en App\Services\DataReset (¿conservar o borrar?): '.implode(', ', $pending));

            return self::FAILURE;
        }

        $keepAudit = (bool) $this->option('keep-audit');
        $database = (string) DB::connection()->getDatabaseName();

        $this->line("Base de datos: <options=bold>$database</> ({$this->laravel->environment()})");
        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>SE BORRARÁ</>', '<options=bold>filas</>');
        $total = 0;
        foreach ($reset->plan($keepAudit) as $table => $rows) {
            $this->components->twoColumnDetail($table, number_format($rows));
            $total += $rows;
        }
        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>SE CONSERVA</>', '<options=bold>filas</>');
        foreach ($reset->kept($keepAudit) as $table => $rows) {
            $this->components->twoColumnDetail($table, number_format($rows));
        }
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Simulación: no se cambió nada.');

            return self::SUCCESS;
        }

        $this->warn('Esto NO se puede deshacer. Haga antes una copia de la base (docs/26_OPERACION.md).');
        $typed = (string) ($this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask("Para continuar escriba el nombre de la base de datos ($database)") : ''));
        if ($typed !== $database) {
            $this->error('No coincide con el nombre de la base de datos: no se borró nada.');

            return self::FAILURE;
        }

        $reset->run($keepAudit);
        $audit->record('system.data_reset', null, 'consola', null, null, ['rows_deleted' => $total, 'audit_kept' => $keepAudit]);
        $this->info('Listo: '.number_format($total).' filas borradas. Ya puede cargar información real.');

        return self::SUCCESS;
    }
}
