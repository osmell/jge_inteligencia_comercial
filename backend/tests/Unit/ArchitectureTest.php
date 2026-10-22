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
        $offenders = array_filter($this->phpFiles('app/Http/Controllers'), fn (string $f) => preg_match('/(?<![>\w$])(?<!function )(app|resolve)\(/', (string) file_get_contents($f)) === 1);

        $this->assertSame([], array_values($offenders), 'Las dependencias llegan por constructor.');
    }

    public function test_services_and_imports_do_not_use_the_service_locator(): void
    {
        $offenders = [];
        foreach (['app/Services', 'app/Imports'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                if (preg_match('/(?<![>\w$])(?<!function )(app|resolve)\(/', (string) file_get_contents($file)) === 1) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders, 'Las dependencias de servicios e importaciones llegan por constructor.');
    }

    public function test_only_the_integrations_talk_http_to_external_systems(): void
    {
        $offenders = [];
        foreach (['app/Services', 'app/Http', 'app/Jobs', 'app/Imports', 'app/Automations'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                if (str_contains((string) file_get_contents($file), 'Http::')) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders, 'Las llamadas a sistemas externos van detrás de un puerto en app/Contracts con su implementación en app/Integrations.');
    }

    public function test_controllers_do_not_decide_access_by_comparing_roles(): void
    {
        $offenders = array_filter($this->phpFiles('app/Http/Controllers'), fn (string $f) => preg_match('/role->slug\s*(===|!==|==|!=)/', (string) file_get_contents($f)) === 1 && ! str_contains($f, '/Admin/'));

        $this->assertSame([], array_values($offenders), 'Las reglas de acceso a un registro van en una Policy (app/Policies), no en el controlador.');
    }
}
