<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Guarda los archivos subidos en el disco privado de importaciones. */
final class ImportFileStore
{
    /** @return array{path: string, hash: string, size: int} */
    public function store(UploadedFile $file, string $type): array
    {
        $path = $type.'/'.date('Y/m').'/'.Str::uuid().'.xlsx';
        Storage::disk(config('imports.disk'))->putFileAs(dirname($path), $file, basename($path));

        return [
            'path' => $path,
            'hash' => (string) hash_file('sha256', (string) $file->getRealPath()),
            'size' => (int) $file->getSize(),
        ];
    }

    public function absolutePath(ImportBatch $batch): string
    {
        return Storage::disk(config('imports.disk'))->path($batch->stored_path);
    }

    public function delete(ImportBatch $batch): void
    {
        Storage::disk(config('imports.disk'))->delete($batch->stored_path);
    }
}
