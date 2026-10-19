<?php

namespace App\Imports\Sales;

use App\Imports\ImportContext;
use App\Models\Operator;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use DateTimeImmutable;

/** Escribe documentos y líneas de ventas nuevos. Debe ejecutarse dentro de una transacción. */
final class SalesPersister
{
    /** @var array<string, int> */
    private array $sellers = [];

    /** @var array<string, int> */
    private array $operators = [];

    public function persist(ImportContext $context): void
    {
        foreach ($context->groups as $key => $group) {
            if (isset($context->existingKeys[$key])) {
                continue; // ya importado con contenido idéntico
            }

            $f = $group->fields;
            $companyId = (int) $f['company'];
            $issued = new DateTimeImmutable($f['issued_on']);
            $hasTime = strlen($f['issued_on']) > 10;

            $document = SalesDocument::create([
                'company_id' => $companyId,
                'branch_id' => (int) $f['branch'],
                'series' => $f['series'],
                'number' => $f['document'],
                'issued_on' => $issued->format('Y-m-d'),
                'issued_at' => $hasTime ? $issued : null,
                'customer_code' => $f['customer_code'],
                'customer_name' => $f['customer_name'],
                'seller_id' => $this->person(Seller::class, $this->sellers, $companyId, $f['seller_name']),
                'operator_id' => $this->person(Operator::class, $this->operators, $companyId, $f['operator_name']),
                'import_batch_id' => $context->batch->id,
                'lines_count' => $group->linesCount(),
                'content_hash' => $group->contentHash(),
            ]);

            $rows = $group->rows;
            usort($rows, fn ($a, $b) => $a->rowNumber <=> $b->rowNumber);
            $now = now();
            $lines = [];
            foreach ($rows as $i => $row) {
                $lines[] = [
                    'sales_document_id' => $document->id,
                    'company_id' => $companyId,
                    'branch_id' => (int) $f['branch'],
                    'issued_on' => $issued->format('Y-m-d'),
                    'line_number' => $i + 1,
                    ...$row->line,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($lines, 500) as $chunk) {
                SalesLine::insert($chunk);
            }
        }
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
}
