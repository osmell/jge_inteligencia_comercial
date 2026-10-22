<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Valida un archivo de importación en segundo plano. No escribe datos de negocio. */
class ValidateImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(ImportRunner $runner): void
    {
        $batch = ImportBatch::find($this->batchId);

        if (! $batch || ! in_array($batch->status, [ImportStatus::Pending, ImportStatus::Validating], true)) {
            return;
        }

        $runner->validate($batch);
    }

    /** Si el worker muere o se agota el tiempo, el lote no debe quedar «en validación» para siempre. */
    public function failed(Throwable $e): void
    {
        ImportBatch::whereKey($this->batchId)->update([
            'status' => ImportStatus::Failed,
            'failure_message' => 'El procesamiento del archivo se interrumpió. Súbelo de nuevo.',
            'finished_at' => now(),
        ]);
    }
}
