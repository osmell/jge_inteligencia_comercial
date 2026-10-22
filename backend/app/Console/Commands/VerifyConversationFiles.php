<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsappAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Comprueba que cada archivo de conversación registrado en la base de datos exista en la carpeta privada y coincida con su firma. */
class VerifyConversationFiles extends Command
{
    protected $signature = 'conversations:verify-files {--checksums : Recalcula la firma sha256 de cada archivo (más lento)}';

    protected $description = 'Verifica los archivos de las conversaciones (útil después de restaurar un respaldo).';

    public function handle(): int
    {
        $disk = Storage::disk('conversations');
        $total = $missing = $corrupt = 0;
        WhatsappAttachment::query()->where('status', WhatsappAttachment::STORED)->orderBy('id')->chunkById(500, function ($files) use ($disk, &$total, &$missing, &$corrupt) {
            foreach ($files as $f) {
                $total++;
                if ($f->path === null || ! $disk->exists($f->path)) {
                    $missing++;
                    $this->error("Falta el archivo del adjunto #{$f->id}: {$f->path}");
                } elseif ($this->option('checksums') && $f->sha256 !== null && ! hash_equals($f->sha256, hash('sha256', (string) $disk->get($f->path)))) {
                    $corrupt++;
                    $this->error("El archivo del adjunto #{$f->id} no coincide con su firma: {$f->path}");
                }
            }
        });
        $this->info("Adjuntos revisados: $total · faltantes: $missing · alterados: $corrupt");

        return $missing + $corrupt === 0 ? self::SUCCESS : self::FAILURE;
    }
}
