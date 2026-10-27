<?php

declare(strict_types=1);

namespace App\Imports\Billing;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\Decimal;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Imports\Rules\RowRules;
use App\Models\BillingDetailLine;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\DataScope;
use App\Services\Sales\VoidDetection;

/**
 * Detalle de facturación del sistema de origen: una fila por línea de factura con el precio establecido en la factura.
 * Una factura = compañía + «Docu. No.» (único en la compañía sin importar la serie, que el reporte distorsiona).
 *
 * Es una referencia para corregir las equivalencias; no toca las ventas importadas. Se carga por día acumulado: cada archivo
 * trae del primer día del período al día de corte. Un documento idéntico a lo ya cargado se omite; uno que cambió (por ejemplo,
 * una anulación posterior) reemplaza su detalle anterior.
 */
final class BillingDetailImportType implements ImportType
{
    private const HINTS = [
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'customer_name' => 'Nombre del cliente en la factura.',
        'issued_on' => 'Fecha de la factura.',
        'series' => 'Serie tal como la emite el reporte (no identifica la factura).',
        'document' => 'No. de factura, único en la compañía sin importar la serie.',
        'product_code' => 'Código del producto en el catálogo.',
        'product_name' => 'Nombre del producto.',
        'units' => 'Unidades vendidas en la factura (pueden ser fraccionarias).',
        'unit_cost' => 'Costo promedio del producto.',
        'price' => 'Precio establecido en la factura para ese producto (el precio real de la equivalencia).',
        'sale_total' => 'Venta total de la línea, tal como la calcula el reporte.',
        'cost_total' => 'Costo total de la línea.',
        'profit_total' => 'Utilidad total que calcula el sistema de origen (puede ser negativa).',
    ];

    private const AMOUNTS = ['unit_cost', 'price', 'sale_total', 'cost_total', 'profit_total'];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    private ?string $min = null;

    private ?string $max = null;

    private int $sale = 0;

    /** @var array<string, true> documentos del archivo con alguna línea de serie «FA» (anulados) */
    private array $voided = [];

    private int $voidsInFile = 0;

    /** @var array<int, int> documentos por compañía */
    private array $updated = [];

    public function __construct(private readonly DataScope $scope, private readonly VoidDetection $voids) {}

    public function key(): string
    {
        return 'billing_detail';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->min = $this->max = null;
        $this->sale = 0;
        $this->updated = [];
        $this->voided = [];
        $this->voidsInFile = 0;
    }

    public function columns(): array
    {
        return array_values(collect((array) config('imports.billing_detail.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, array_values($aliases), hint: self::HINTS[$key] ?? null))
            ->all());
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $document = RowRules::limit($row, 'document', $row->code('document', warnNumeric: false));
        $series = RowRules::limit($row, 'series', $row->code('series'));
        $issued = $row->date('issued_on', config('imports.sales.date_formats'));
        $customer = RowRules::limit($row, 'customer_name', $row->text('customer_name'));
        $productCode = RowRules::limit($row, 'product_code', $row->code('product_code', warnNumeric: false));
        $productName = RowRules::limit($row, 'product_name', $row->text('product_name'));
        $units = $row->decimal('units', scale: (int) config('imports.sales.units_scale', 16));

        $amounts = [];
        foreach (self::AMOUNTS as $key) {
            $amounts[$key] = $row->decimal($key);
        }

        $company = $this->resolver()->resolveCompany($row);

        if ($row->failed() || ! $issued || ! $company) {
            return null;
        }

        RowRules::issuedDate($row, $issued);
        $day = $issued->format('Y-m-d');
        $this->min = $this->min === null || $day < $this->min ? $day : $this->min;
        $this->max = $this->max === null || $day > $this->max ? $day : $this->max;
        $this->sale += Decimal::units($amounts['sale_total']);
        if ($series === VoidDetection::VOID_SERIES) {
            $this->voided["{$company->id}|{$document}"] = true;
        }

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$document}",
            ['company' => (string) $company->id, 'document' => $document],
            [
                'series' => $series, 'issued_on' => $day, 'customer_name' => $customer, 'product_code' => $productCode,
                'product_name' => $productName, 'units' => $units, ...$amounts,
            ],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existingDocuments($context);
        $this->voidsInFile = count($this->voided);

        foreach ($context->groups as $key => $group) {
            if (! isset($existing[$key])) {
                $context->documentsNew++;
            } elseif ($existing[$key] === $group->contentHash()) {
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;
            } else {
                $company = (int) $group->fields['company'];
                $this->updated[$company] = ($this->updated[$company] ?? 0) + 1;
            }
        }

        $updated = array_sum($this->updated);
        $context->documentsNew += $updated; // un documento que cambió se vuelve a escribir
        if ($updated > 0) {
            $context->errors->warning('documents_replaced', "{$updated} documento(s) ya cargados traen un detalle distinto (por ejemplo, anulados después de la carga anterior): se reemplazará su detalle.");
        }

        $context->summary = [...$context->summary, 'billing' => [
            'date_from' => $this->min,
            'date_to' => $this->max,
            'sale_total' => Decimal::format($this->sale),
            'documents_replaced' => $updated,
            'voids_in_file' => $this->voidsInFile,
        ]];
    }

    public function persist(ImportContext $context): void
    {
        $replace = [];
        foreach ($context->groups as $key => $group) {
            if (! isset($context->existingKeys[$key])) {
                $replace[(int) $group->fields['company']][] = $group->fields['document'];
            }
        }
        foreach ($replace as $companyId => $numbers) {
            foreach (array_chunk($numbers, 500) as $chunk) {
                BillingDetailLine::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('document', $chunk)->delete();
            }
        }

        $counters = [];
        $buffer = [];
        $now = now();
        foreach ($context->rows() as $row) {
            if (isset($context->existingKeys[$row->groupKey])) {
                continue;
            }

            $group = $context->groups[$row->groupKey];
            $counters[$row->groupKey] = ($counters[$row->groupKey] ?? 0) + 1;
            $buffer[] = [
                'company_id' => (int) $group->fields['company'],
                'import_batch_id' => $context->batch->id,
                'document' => $group->fields['document'],
                'line_number' => $counters[$row->groupKey],
                ...$row->line,
                'content_hash' => $group->contentHash(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= 1000) {
                BillingDetailLine::insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            BillingDetailLine::insert($buffer);
        }

        foreach ($counters as $key => $count) {
            if ($count !== $context->groups[$key]->linesCount()) {
                throw new \RuntimeException("El documento {$key} no coincide con lo validado: el archivo cambió durante la importación.");
            }
        }

        // Facturas anuladas (serie «FA»): se registran y se enlazan con su venta si ya está cargada.
        $voids = $this->voids->run($context->batch->id);
        $context->summary['billing'] = [...$context->summary['billing'] ?? [], 'voids_new' => $voids['new'], 'voids_with_sales' => $voids['with_sales']];
    }

    /** @return array<string, string> clave de documento => huella ya guardada */
    private function existingDocuments(ImportContext $context): array
    {
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['document'];
        }

        $found = [];
        foreach ($byCompany as $companyId => $numbers) {
            foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
                BillingDetailLine::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('document', $chunk)
                    ->distinct()->get(['company_id', 'document', 'content_hash'])
                    ->each(function (BillingDetailLine $l) use (&$found) {
                        $found["{$l->company_id}|{$l->document}"] = $l->content_hash;
                    });
            }
        }

        return $found;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user, $this->scope);
    }
}
