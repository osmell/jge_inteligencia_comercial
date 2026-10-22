<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ImportBatchResource;
use App\Imports\ImportFileStore;
use App\Imports\ImportRegistry;
use App\Imports\TemplateBuilder;
use App\Jobs\ConfirmImportJob;
use App\Jobs\ValidateImportJob;
use App\Models\ImportBatch;
use App\Services\AuditLogger;
use App\Services\Reports\CsvSafe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importaciones de archivos. Flujo: subir (se valida en cola, sin escribir datos) → revisar vista previa y errores →
 * confirmar (se importa en cola) o descartar. Quien no es Administrador solo ve sus propios lotes.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportFileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    public function types(): JsonResponse
    {
        return response()->json(['data' => collect($this->registry->keys())->map(fn (string $key) => [
            'key' => $key,
            'label' => config("imports.labels.{$key}", $key),
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->visible($request)->with('user')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(20);

        return response()->json($page->through(fn (ImportBatch $b) => (new ImportBatchResource($b))->resolve()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($this->registry->keys())],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.config('imports.max_file_kb')],
        ]);

        $stored = $this->files->store($request->file('file'), $data['type']);

        $batch = ImportBatch::create([
            'type' => $data['type'],
            'status' => ImportStatus::Pending,
            'original_name' => mb_substr(basename($request->file('file')->getClientOriginalName()), 0, 255),
            'stored_path' => $stored['path'],
            'file_hash' => $stored['hash'],
            'file_size' => $stored['size'],
            'user_id' => $request->user()->id,
        ]);

        $this->audit->record('import.uploaded', $request->user(), auditable: $batch, new: [
            'type' => $batch->type, 'file' => $batch->original_name, 'hash' => $batch->file_hash,
        ]);

        ValidateImportJob::dispatch($batch->id);

        return (new ImportBatchResource($batch->fresh('user'), detail: true))->response()->setStatusCode(201);
    }

    public function show(Request $request, ImportBatch $batch): ImportBatchResource
    {
        $this->authorizeBatch($request, $batch);

        return new ImportBatchResource($batch->load('user'), detail: true);
    }

    public function errors(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        $page = $batch->errors()
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->query('code'), fn ($q, $c) => $q->where('code', $c))
            ->orderBy('row_number')->orderBy('id')->paginate(50);

        return response()->json($page);
    }

    public function errorsCsv(Request $request, ImportBatch $batch): StreamedResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('No se pudo abrir la salida de la descarga.');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea UTF-8
            fputcsv($out, ['Fila', 'Columna', 'Severidad', 'Código', 'Mensaje', 'Valor']);
            foreach ($batch->errors()->orderBy('row_number')->orderBy('id')->cursor() as $e) {
                fputcsv($out, [$e->row_number, CsvSafe::value($e->column), $e->severity === 'error' ? 'Error' : 'Advertencia', $e->code, CsvSafe::value($e->message), CsvSafe::value($e->value)]);
            }
            fclose($out);
        }, "errores-importacion-{$batch->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function confirm(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        // Cambio de estado atómico: dos confirmaciones simultáneas no pueden importar dos veces.
        $claimed = ImportBatch::whereKey($batch->id)->where('status', ImportStatus::Validated)->update(['status' => ImportStatus::Importing]);
        abort_if($claimed === 0, 409, 'Solo se puede confirmar un lote validado que no se haya confirmado antes.');

        $this->audit->record('import.confirmed', $request->user(), auditable: $batch, new: ['type' => $batch->type, 'file' => $batch->original_name]);

        ConfirmImportJob::dispatch($batch->id);

        return (new ImportBatchResource($batch->fresh('user'), detail: true))->response()->setStatusCode(202);
    }

    public function discard(Request $request, ImportBatch $batch): ImportBatchResource
    {
        $this->authorizeBatch($request, $batch);
        abort_unless($batch->status->isDiscardable(), 409, 'Este lote no se puede descartar en su estado actual.');

        $this->files->delete($batch);
        $batch->update(['status' => ImportStatus::Discarded, 'finished_at' => now()]);
        $this->audit->record('import.discarded', $request->user(), auditable: $batch, new: ['type' => $batch->type, 'file' => $batch->original_name]);

        return new ImportBatchResource($batch->load('user'), detail: true);
    }

    public function template(string $type, TemplateBuilder $builder): BinaryFileResponse
    {
        abort_unless($this->registry->has($type), 404);

        $label = config("imports.labels.{$type}", $type);
        $path = $builder->build($this->registry->make($type), $label);

        return response()->download($path, 'plantilla-'.str($label)->slug().'.xlsx')->deleteFileAfterSend();
    }

    /** @return Builder<ImportBatch> */
    private function visible(Request $request): Builder
    {
        $query = ImportBatch::query();

        return $request->user()->seesAllOrganizations() ? $query : $query->where('user_id', $request->user()->id);
    }

    private function authorizeBatch(Request $request, ImportBatch $batch): void
    {
        abort_unless($this->visible($request)->whereKey($batch->id)->exists(), 404);
    }
}
