<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Reglas de arquitectura (docs/24): se verifican en cada ejecución para que no se pierdan con el tiempo. */
final class ArchitectureTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(string $relative): array
    {
        $files = [];
        $root = dirname(__DIR__, 2).'/'.$relative;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function test_controllers_do_not_use_the_database_facade(): void
    {
        $offenders = array_filter($this->phpFiles('app/Http/Controllers'), fn (string $f) => str_contains((string) file_get_contents($f), 'DB::'));

        $this->assertSame([], array_values($offenders), 'Las consultas y transacciones van en servicios o scopes del modelo, no en controladores.');
    }

    public function test_every_php_file_declares_strict_types(): void
    {
        $offenders = [];
        foreach (['app', 'database', 'routes', 'tests'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                if (! str_contains(substr((string) file_get_contents($file), 0, 200), 'declare(strict_types=1);')) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_controllers_do_not_use_the_service_locator(): void
    {
        $offenders = array_filter($this->phpFiles('app/Http/Controllers'), fn (string $f) => preg_match('/(?<![>\w])(app|resolve)\(/', (string) file_get_contents($f)) === 1);

        $this->assertSame([], array_values($offenders), 'Las dependencias llegan por constructor.');
    }
}
