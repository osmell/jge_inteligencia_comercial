<?php

declare(strict_types=1);

namespace App\Imports\Sales;

use App\Imports\ImportContext;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use DateTimeImmutable;

/**
 * Escribe documentos y líneas de ventas nuevos. Debe ejecutarse dentro de una transacción.
 *
 * Dos fases para no tener todas las líneas en memoria: 1) se insertan los documentos por bloques a partir de los
 * grupos (que solo guardan los datos del documento y huellas); 2) se relee el archivo en streaming y se insertan
 * las líneas en bloques.
 */
final class SalesPersister
{
    private const LINE_CHUNK = 1000;

    private const DOCUMENT_CHUNK = 500;

    /** @var array<string, int> */
    private array $sellers = [];

    /** @var array<string, int> */
    private array $operators = [];

    public function persist(ImportContext $context): void
    {
        $documents = $this->createDocuments($context);

        $counters = [];
        $buffer = [];
        $now = now();

        foreach ($context->rows() as $row) {
            if (! isset($documents[$row->groupKey])) {
                continue; // documento que ya existía con contenido idéntico
            }

            [$documentId, $fields] = $documents[$row->groupKey];
            $counters[$row->groupKey] = ($counters[$row->groupKey] ?? 0) + 1;

            $buffer[] = [
                'sales_document_id' => $documentId,
                'company_id' => (int) $fields['company'],
                'branch_id' => (int) $fields['branch'],
                'issued_on' => substr($fields['issued_on'], 0, 10),
                'line_number' => $counters[$row->groupKey],
                ...$row->line,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::LINE_CHUNK) {
                SalesLine::insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            SalesLine::insert($buffer);
        }

        // Cada documento debe quedar con tantas líneas como se validaron.
        foreach ($documents as $key => [$documentId]) {
            if (($counters[$key] ?? 0) !== $context->groups[$key]->linesCount()) {
                throw new \RuntimeException("El documento {$key} no coincide con lo validado: el archivo cambió durante la importación.");
            }
        }
    }

    /**
     * Crea los documentos nuevos por bloques (un INSERT por bloque) y recupera sus ids.
     *
     * @return array<string, array{0: int, 1: array<string, mixed>}> clave de grupo => [id del documento, campos]
     */
    private function createDocuments(ImportContext $context): array
    {
        $created = [];
        $now = now();

        $pending = [];
        foreach ($context->groups as $key => $group) {
            if (! isset($context->existingKeys[$key])) { // los ya importados con contenido idéntico se omiten
                $pending[$key] = $group;
            }
        }

        foreach (array_chunk($pending, self::DOCUMENT_CHUNK, preserve_keys: true) as $chunk) {
            $rows = [];
            foreach ($chunk as $group) {
                $f = $group->fields;
                $companyId = (int) $f['company'];
                $issued = new DateTimeImmutable($f['issued_on']);

                $rows[] = [
                    'company_id' => $companyId,
                    'branch_id' => (int) $f['branch'],
                    'series' => $f['series'],
                    'number' => $f['document'],
                    'issued_on' => $issued->format('Y-m-d'),
                    'issued_at' => strlen($f['issued_on']) > 10 ? $issued->format('Y-m-d H:i:s') : null,
                    'customer_code' => $f['customer_code'],
                    'customer_name' => $f['customer_name'],
                    'seller_id' => $this->person(Seller::class, $this->sellers, $companyId, $f['seller_name']),
                    'operator_id' => $this->person(Operator::class, $this->operators, $companyId, $f['operator_name']),
                    'import_batch_id' => $context->batch->id,
                    'lines_count' => $group->linesCount(),
                    'content_hash' => $group->contentHash(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            SalesDocument::insert($rows);
            $this->ensureCustomers($rows);

            // Los ids se recuperan por la clave única (compañía + serie + número) dentro de este lote.
            $numbers = array_column($rows, 'number');
            $ids = [];
            SalesDocument::withoutGlobalScopes()->where('import_batch_id', $context->batch->id)->whereIn('number', $numbers)
                ->get(['id', 'company_id', 'series', 'number'])
                ->each(function (SalesDocument $d) use (&$ids) {
                    $ids["{$d->company_id}|{$d->series}|{$d->number}"] = $d->id;
                });

            foreach ($chunk as $key => $group) {
                $created[$key] = [$ids[$key] ?? throw new \RuntimeException("No se pudo crear el documento {$key}."), $group->fields];
            }
        }

        return $created;
    }

    /**
     * @param  class-string<Seller|Operator>  $model
     * @param  array<string, int>  $cache
     */
    private function person(string $model, array &$cache, int $companyId, string $name): int
    {
        $normalized = $model::normalize($name);

        return $cache["{$companyId}|{$normalized}"] ??= $model::firstOrCreate(
            ['company_id' => $companyId, 'normalized_name' => $normalized],
            ['name' => $name],
        )->id;
    }

    /**
     * Da de alta los clientes que aparecen por primera vez en las ventas (compañía + código). Un cliente que ya existe
     * no se toca: el archivo maestro es la fuente de sus datos.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    private function ensureCustomers(array $documents): void
    {
        $now = now();
        $customers = [];
        foreach ($documents as $d) {
            $customers["{$d['company_id']}|{$d['customer_code']}"] ??= [
                'company_id' => $d['company_id'], 'branch_id' => $d['branch_id'], 'code' => $d['customer_code'], 'name' => $d['customer_name'],
                'source' => 'sales', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        Customer::withoutGlobalScopes()->insertOrIgnore(array_values($customers));
    }
}
