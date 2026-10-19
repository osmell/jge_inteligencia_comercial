<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Si el worker se cae a mitad de un lote, este queda «en cola/validando/importando» para siempre y la
 * pantalla seguiría esperando. Un lote importando que se interrumpe no deja datos (transacción revertida).
 */
#[Signature('imports:fail-stale {--minutes=120 : Minutos sin actividad para considerar atascado un lote}')]
#[Description('Marca como fallidos los lotes de importación atascados')]
class FailStaleImports extends Command
{
    public function handle(): int
    {
        $count = ImportBatch::whereIn('status', [ImportStatus::Pending, ImportStatus::Validating, ImportStatus::Importing])
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->update([
                'status' => ImportStatus::Failed,
                'failure_message' => 'El procesamiento se interrumpió antes de terminar. No se escribió ningún dato; sube el archivo de nuevo.',
                'finished_at' => now(),
            ]);

        $this->info("Lotes marcados como fallidos: {$count}");

        return self::SUCCESS;
    }
}
