<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Escribe en la base de datos un lote ya validado y confirmado por el usuario. */
class ConfirmImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(ImportRunner $runner): void
    {
        $batch = ImportBatch::find($this->batchId);

        // El estado «importando» lo fija quien confirma, para bloquear confirmaciones dobles.
        if (! $batch || $batch->status !== ImportStatus::Importing) {
            return;
        }

        $runner->import($batch);
    }

    public function failed(Throwable $e): void
    {
        ImportBatch::whereKey($this->batchId)->update([
            'status' => ImportStatus::Failed,
            'failure_message' => 'La importación se interrumpió. No se escribió ningún dato; vuelve a subir el archivo.',
            'finished_at' => now(),
        ]);
    }
}
