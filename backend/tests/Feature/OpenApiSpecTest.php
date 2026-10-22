<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * El documento de la API (`openapi.json`) se genera desde el código y de él salen los tipos del frontend. Si cambia una respuesta o una
 * validación y no se regenera, esta prueba falla. Para regenerar: `UPDATE_OPENAPI=1 php artisan test --filter=OpenApiSpecTest` y luego
 * `npm run api:types` en el frontend. Se genera siempre dentro de la prueba (SQLite con las migraciones aplicadas) porque Scramble lee el
 * esquema de la base de datos para tipar los modelos: así el resultado no depende de la base del equipo de cada persona.
 */
class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_committed_openapi_document_matches_the_code(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'openapi');
        Artisan::call('scramble:export', ['--path' => $tmp]);
        $generated = $this->normalized(json_decode((string) file_get_contents($tmp)));
        @unlink($tmp);
        $committed = json_decode((string) file_get_contents(base_path('openapi.json')));

        if (getenv('UPDATE_OPENAPI') === '1') {
            file_put_contents(base_path('openapi.json'), (string) json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('openapi.json regenerado.');
        }

        // La dirección del servidor depende de APP_URL de cada entorno.
        unset($generated->servers, $committed->servers);

        $this->assertSame(
            json_encode($committed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'openapi.json está desactualizado: ejecute «UPDATE_OPENAPI=1 php artisan test --filter=OpenApiSpecTest», luego «npm run api:types» (frontend) y suba ambos archivos.',
        );
    }

    /**
     * Scramble escribe `[]` donde no pudo deducir el tipo (un esquema vacío) y los generadores de tipos lo rechazan: se convierte en `{}`
     * (cualquier valor), que es lo que significa.
     */
    private function normalized(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map($this->normalized(...), $node);
        }
        if (! is_object($node)) {
            return $node;
        }
        foreach (get_object_vars($node) as $key => $value) {
            $isSchemaSlot = $key === 'items';
            $node->$key = $value === [] && $isSchemaSlot ? new \stdClass : $this->normalized($value);
            if ($key === 'properties' && is_object($node->$key)) {
                foreach (get_object_vars($node->$key) as $name => $schema) {
                    if ($schema === []) {
                        $node->$key->$name = new \stdClass;
                    }
                }
            }
        }

        return $node;
    }

    public function test_every_documented_operation_has_a_response(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true);

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertNotEmpty($operation['responses'] ?? [], "$method $path no documenta ninguna respuesta.");
            }
        }
    }

    /** Operaciones cuya respuesta aún trae partes sin tipo (ver docs/27). Solo puede bajar: al migrar una a Resource, baje este número. */
    private const OPAQUE_BACKLOG = 30;

    public function test_untyped_responses_never_grow(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('openapi.json')));
        $opaque = [];
        foreach ($spec->paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $schema = $operation->responses->{'200'}->content->{'application/json'}->schema ?? $operation->responses->{'201'}->content->{'application/json'}->schema ?? null;
                if ($schema !== null && $this->hasUntypedPart($schema)) {
                    $opaque[] = strtoupper($method).' '.$path;
                }
            }
        }

        $this->assertLessThanOrEqual(self::OPAQUE_BACKLOG, count($opaque), "Hay respuestas nuevas sin tipo (use un Resource): \n".implode("\n", $opaque));
        $this->assertGreaterThanOrEqual(self::OPAQUE_BACKLOG, count($opaque), 'Se tipó alguna respuesta: baje OPAQUE_BACKLOG a '.count($opaque).' para que no vuelva a empeorar.');
    }

    private function hasUntypedPart(mixed $node): bool
    {
        if (is_object($node)) {
            foreach (get_object_vars($node) as $key => $value) {
                if (($key === 'items' || $key === 'properties') && $value instanceof \stdClass && (array) $value === []) {
                    return true;
                }
                if ($this->hasUntypedPart($value)) {
                    return true;
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                if ($this->hasUntypedPart($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_the_api_docs_are_for_administrators_only(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }
}
