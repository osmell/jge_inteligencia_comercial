<?php

namespace App\Imports;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Valida un lote sin escribir datos de negocio: deja el estado, los conteos, la vista previa y los errores. */
final class ImportRunner
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportProcessor $processor,
        private readonly ImportFileStore $files,
    ) {}

    public function validate(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportStatus::Validating, 'failure_message' => null]);
        $batch->errors()->delete(); // una nueva validación reemplaza los resultados anteriores

        try {
            $type = $this->registry->make($batch->type);
            $errors = new ErrorCollector((int) config('imports.max_stored_errors'));

            $this->checkAlreadyImported($batch, $errors);

            $result = $this->processor->process($this->files->absolutePath($batch), $type, $errors);
            $context = new ImportContext($batch, $result['groups'], $errors);

            if ($result['header_ok'] && $result['rows_total'] > 0) {
                $type->finalize($context);
            }

            $this->save($batch, $result, $context, $errors);
        } catch (Throwable $e) {
            report($e);
            $batch->update([
                'status' => ImportStatus::Failed,
                'failure_message' => 'No se pudo procesar el archivo por un error interno. Inténtalo de nuevo o avisa al administrador.',
                'finished_at' => now(),
            ]);
        }
    }

    private function checkAlreadyImported(ImportBatch $batch, ErrorCollector $errors): void
    {
        $exists = ImportBatch::where('type', $batch->type)->where('file_hash', $batch->file_hash)
            ->where('status', ImportStatus::Imported)->whereKeyNot($batch->id)->exists();

        if ($exists) {
            $errors->error('already_imported', 'Este mismo archivo ya fue importado anteriormente. No se importará de nuevo.');
        }
    }

    /** @param  array{rows_total: int, rows_invalid: int, preview: list<array<string, mixed>>, groups: array<string, ImportGroup>}  $result */
    private function save(ImportBatch $batch, array $result, ImportContext $context, ErrorCollector $errors): void
    {
        DB::transaction(function () use ($batch, $result, $context, $errors) {
            foreach (array_chunk($errors->stored(), 500) as $chunk) {
                $batch->errors()->createMany($chunk);
            }

            $batch->update([
                'status' => $errors->hasErrors() ? ImportStatus::Rejected : ImportStatus::Validated,
                'rows_total' => $result['rows_total'],
                'documents_total' => count($result['groups']),
                'documents_new' => $context->documentsNew,
                'documents_duplicate' => $context->documentsDuplicate,
                'errors_total' => $errors->errors,
                'warnings_total' => $errors->warnings,
                'summary' => [
                    ...$context->summary,
                    'rows_invalid' => $result['rows_invalid'],
                    'preview' => $result['preview'],
                ],
                'validated_at' => now(),
            ]);
        });
    }
}
