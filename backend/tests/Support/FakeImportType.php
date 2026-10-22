<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\ParsedRow;
use App\Models\ImportBatch;

/** Tipo de importación mínimo para probar el marco, independiente de ventas. */
class FakeImportType implements ImportType
{
    public static bool $explode = false;

    public function key(): string
    {
        return 'fake';
    }

    public function prepare(ImportBatch $batch): void {}

    public function persist(ImportContext $context): void {}

    public function columns(): array
    {
        return [
            new ColumnDefinition('document', ['Documento']),
            new ColumnDefinition('customer', ['Cliente', 'Nombre Cliente']),
            new ColumnDefinition('product', ['Código Producto']),
            new ColumnDefinition('amount', ['Importe']),
            new ColumnDefinition('date', ['Fecha']),
            new ColumnDefinition('note', ['Nota'], required: false),
        ];
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $document = $row->code('document');
        $customer = $row->text('customer');
        $date = $row->date('date', ['Y-m-d', 'd/m/Y']);
        $product = $row->code('product');
        $amount = $row->decimal('amount');

        if ($row->failed()) {
            return null;
        }

        return new ParsedRow($row->number, $document, ['customer' => $customer, 'date' => $date->format('Y-m-d')], ['product' => $product, 'amount' => $amount]);
    }

    public function finalize(ImportContext $context): void
    {
        if (self::$explode) {
            throw new \RuntimeException('fallo interno secreto');
        }
        $context->documentsNew = count($context->groups);
        $context->summary['custom'] = 'ok';
    }
}
