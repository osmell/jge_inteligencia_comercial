<?php

namespace App\Imports\Sales;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\Credit\CreditInvoicesImportType;
use App\Imports\Decimal;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use DateTimeImmutable;

/**
 * Plantilla de ventas: una fila por línea de factura. Una factura = compañía + serie + documento.
 *
 * Reglas (docs/10_DECISIONES_PENDIENTES.md):
 *  - Total Neto + Total Impuesto = Venta + Imp = Gran Total (tolerancia de redondeo configurable); las diferencias son errores.
 *  - No se esperan importes negativos: devoluciones, anulaciones y notas de crédito se emiten aparte.
 *  - Total Neto = Sub Total − Descuento (si el archivo trae esas columnas): el descuento ya está restado en Total Neto.
 *  - Las líneas con unidades e importes en cero (p. ej. facturas anuladas) se omiten con un aviso.
 *  - El costo unitario es el costo ACTUAL (no histórico).
 *  - Costo Tot.Vta. = Und.Vendidas × Costo Unit.Vta. está por confirmar: solo genera advertencia.
 */
final class SalesImportType implements ImportType
{
    /** Importes opcionales que, si la columna viene en el archivo, se guardan y se concilian con Total Neto. */
    private const DISCOUNT_COLUMNS = ['subtotal', 'discount_total'];

    /** «Gran Total» del archivo se omite: el total facturado se calcula como Total Neto + Total Impuesto. */
    private const AMOUNTS = ['unit_cost', 'line_cost_total', 'unit_price', 'normal_price', 'net_total', 'tax_total', 'sale_plus_tax'];

    /** Ayudas que se muestran en la hoja «Instrucciones» de la plantilla. */
    private const HINTS = [
        'document' => 'Número del documento. Formatee la columna como Texto para conservar los ceros iniciales.',
        'series' => 'Serie del documento. Compañía + Serie + Documento identifican la factura de forma única.',
        'issued_on' => 'Fecha de la factura: celda con formato de fecha de Excel, o texto AAAA-MM-DD / DD/MM/AAAA. Columna provisional.',
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema. Columna provisional.',
        'branch' => 'Código o nombre de la sucursal de esa compañía. Columna provisional.',
        'customer_code' => 'Código del cliente (texto).',
        'customer_name' => 'Nombre del cliente.',
        'product_code' => 'Código del producto (texto; conserve ceros iniciales).',
        'units' => 'Unidades vendidas: mayor que cero, con hasta 16 decimales.',
        'product_name' => 'Nombre del producto.',
        'line_cost_total' => 'Costo total de la línea.',
        'unit_cost' => 'Costo unitario ACTUAL del producto (no el histórico de la venta).',
        'unit_price' => 'Precio unitario de venta.',
        'normal_price' => 'Precio unitario normal (opcional).',
        'subtotal' => 'Venta de la línea antes del descuento (opcional). Total Neto = Sub Total − Descuento.',
        'discount_total' => 'Descuento de la línea (opcional). Ya está restado en Total Neto; no se resta otra vez.',
        'net_total' => 'Subtotal menos descuento.',
        'tax_total' => 'Total Neto × IVA.',
        'sale_plus_tax' => 'Total Neto + Total Impuesto.',
        'grand_total' => 'Se omite: no se lee ni se valida. El total facturado se calcula como Total Neto + Total Impuesto.',
        'seller_name' => 'Nombre del vendedor.',
        'category_name' => 'Categoría del producto (Nivel 2).',
        'operator_name' => 'Operador que emitió la factura.',
    ];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    /** @var array{seller: array<string, string>, operator: array<string, string>} clave «compañía|nombre normalizado» => nombre */
    private array $people = ['seller' => [], 'operator' => []];

    /** @var array<string, mixed> */
    private array $stats = ['lines' => [], 'min' => null, 'max' => null, 'net' => 0, 'tax' => 0, 'grand' => 0, 'cost' => 0, 'skipped' => 0];

    public function key(): string
    {
        return 'sales';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
    }

    public function columns(): array
    {
        $optional = config('imports.sales.optional', []);

        return collect(config('imports.sales.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, $aliases, required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->values()->all();
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $integerCodes = config('imports.sales.integer_codes', []);
        $document = $this->limit($row, 'document', $row->code('document', warnNumeric: ! in_array('document', $integerCodes, true)));
        $series = $this->limit($row, 'series', $row->code('series'));
        $issued = $row->date('issued_on', config('imports.sales.date_formats'));
        $customerCode = $this->limit($row, 'customer_code', $row->code('customer_code', warnNumeric: ! in_array('customer_code', $integerCodes, true)));
        $customerName = $this->limit($row, 'customer_name', $row->text('customer_name'));
        $productCode = $this->limit($row, 'product_code', $row->code('product_code', warnNumeric: ! in_array('product_code', $integerCodes, true)));
        $productName = $this->limit($row, 'product_name', $row->text('product_name'));
        $category = $this->limit($row, 'category_name', $row->text('category_name'));
        $seller = $this->limit($row, 'seller_name', $row->text('seller_name'));
        $operator = $this->limit($row, 'operator_name', $row->text('operator_name'));
        $units = $row->decimal('units', scale: (int) config('imports.sales.units_scale', 16));

        $amounts = [];
        foreach (self::AMOUNTS as $key) {
            $amounts[$key] = $row->decimal($key, required: $key !== 'normal_price');
        }

        // Opcionales: solo se guardan si la columna viene en el archivo (una celda vacía cuenta como 0).
        $discounts = [];
        foreach (self::DISCOUNT_COLUMNS as $key) {
            if ($row->has($key)) {
                $discounts[$key] = $row->decimal($key, required: false) ?? Decimal::format(0);
            }
        }

        // «Gran Total» del archivo no se usa: el total facturado es Total Neto + Total Impuesto (= Venta + Imp, ya conciliado).
        $amounts['grand_total'] = $amounts['sale_plus_tax'];

        [$company, $branch] = $this->resolveLocation($row);

        if ($row->failed() || ! $issued || ! $company || ! $branch) {
            return null;
        }

        if ($this->isEmptyLine($units, $amounts, $discounts)) {
            $this->stats['skipped']++;
            $row->skip('units', 'zero_line_skipped', 'Línea omitida: unidades y todos los importes están en cero (por ejemplo, una factura anulada). No se importa.');

            return null;
        }

        $this->checkAmounts($row, $units, $amounts, $discounts);
        $this->checkDate($row, $issued);

        if ($row->failed()) {
            return null;
        }

        $this->collect($company, $issued, $units, $amounts, $seller, $operator, $discounts);
        $hasTime = $issued->format('H:i:s') !== '00:00:00';

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$series}|{$document}",
            [
                'company' => (string) $company->id,
                'branch' => (string) $branch->id,
                'series' => $series,
                'document' => $document,
                'issued_on' => $issued->format($hasTime ? 'Y-m-d H:i:s' : 'Y-m-d'),
                'customer_code' => $customerCode,
                'customer_name' => $customerName,
                'seller_name' => $seller,
                'operator_name' => $operator,
            ],
            [
                'product_code' => $productCode,
                'product_name' => $productName,
                'category_name' => $category,
                'units' => $units,
                ...$amounts,
                ...$discounts,
            ],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existingDocuments($context);

        foreach ($context->groups as $key => $group) {
            if (! isset($existing[$key])) {
                $context->documentsNew++;
            } elseif ($existing[$key] === $group->contentHash()) {
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;
            } else {
                $context->errors->error(
                    'document_conflict',
                    "El documento {$group->fields['series']}-{$group->fields['document']} ya existe con contenido distinto. No se sobrescribe: una corrección requiere un proceso autorizado.",
                    $group->firstRow,
                    config('imports.sales.columns.document.0'),
                    $group->fields['document'],
                );
            }
        }

        $context->summary = [...$context->summary, ...$this->summary($context)];
    }

    public function persist(ImportContext $context): void
    {
        (new SalesPersister)->persist($context);

        // Facturas de crédito que se cargaron antes que su venta quedan enlazadas ahora.
        CreditInvoicesImportType::linkPendingToSales(collect($context->groups)->map(fn ($g) => (int) $g->fields['company'])->unique()->values()->all());
    }

    /** @return array{0: Company|null, 1: Branch|null} */
    private function resolveLocation(ImportRow $row): array
    {
        return $this->locations()->resolve($row);
    }

    /**
     * @param  array<string, string|null>  $amounts
     * @param  array<string, string>  $discounts
     */
    private function checkAmounts(ImportRow $row, ?string $units, array $amounts, array $discounts = []): void
    {
        $negativeHint = 'No se esperan valores negativos: las devoluciones, anulaciones y notas de crédito se emiten aparte.';

        if (! Decimal::isPositive($units)) {
            $row->fail('units', 'non_positive_units', 'Las unidades vendidas deben ser mayores que cero. '.(Decimal::isNegative($units) ? $negativeHint : ''));
        }
        foreach ([...$amounts, ...$discounts] as $key => $value) {
            if ($value !== null && Decimal::units($value) < 0) {
                $row->fail($key, 'negative_amount', $negativeHint);
            }
        }

        $net = Decimal::units($amounts['net_total']);
        $tax = Decimal::units($amounts['tax_total']);
        $sum = $net + $tax;
        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        $show = fn (int $u) => number_format($u / 10 ** Decimal::SCALE, 2, '.', '');
        $detail = "«{$row->label('net_total')}» + «{$row->label('tax_total')}» ({$show($net)} + {$show($tax)} = {$show($sum)})";

        $value = Decimal::units($amounts['sale_plus_tax']);
        if (abs($value - $sum) > $tolerance) {
            $row->fail('sale_plus_tax', 'amount_mismatch', "«{$row->label('sale_plus_tax')}» ({$show($value)}) no coincide con {$detail}.");
        }

        // Total Neto = Sub Total − Descuento (el descuento ya viene restado; no se vuelve a restar al sumar totales).
        if (isset($discounts['subtotal'])) {
            $subtotal = Decimal::units($discounts['subtotal']);
            $discount = Decimal::units($discounts['discount_total'] ?? '0');
            if (abs($subtotal - $discount - $net) > $tolerance) {
                $row->fail('net_total', 'subtotal_mismatch', sprintf(
                    '«%s» (%s) no coincide con «%s» − «%s» (%s − %s = %s).',
                    $row->label('net_total'), $show($net), $row->label('subtotal'), $row->label('discount_total'), $show($subtotal), $show($discount), $show($subtotal - $discount),
                ));
            }
        }

        $rates = config('imports.sales.tax_rates', []);
        if ($rates && ! collect($rates)->contains(fn ($r) => abs($tax - (int) round($net * (float) $r)) <= $tolerance)) {
            $pct = implode(' / ', array_map(fn ($r) => rtrim(rtrim(number_format((float) $r * 100, 2), '0'), '.').' %', $rates));
            $row->fail('tax_total', 'tax_rate_mismatch', "«{$row->label('tax_total')}» ({$show($tax)}) no corresponde a «{$row->label('net_total')}» ({$show($net)}) por la tasa de IVA esperada ({$pct}).");
        }

        // Regla pendiente de confirmar con el responsable del Excel: solo advertencia.
        $unitsF = (float) $units;
        $expected = $unitsF * Decimal::units($amounts['unit_cost']) / 10 ** Decimal::SCALE;
        $actual = Decimal::units($amounts['line_cost_total']) / 10 ** Decimal::SCALE;
        if (abs($actual - $expected) > max(0.01, $unitsF * 0.0001)) {
            $row->warn('line_cost_total', 'cost_total_mismatch', sprintf(
                '«%s» (%.2f) no coincide con «%s» × «%s» (%.2f). Regla por confirmar.',
                $row->label('line_cost_total'), $actual, $row->label('units'), $row->label('unit_cost'), $expected,
            ));
        }
    }

    /**
     * Línea sin movimiento: unidades e importes de venta en cero. Se omite con aviso; si algún importe no es cero, se valida normal.
     *
     * @param  array<string, string|null>  $amounts
     * @param  array<string, string>  $discounts
     */
    private function isEmptyLine(?string $units, array $amounts, array $discounts): bool
    {
        if ($units === null || Decimal::isPositive($units) || Decimal::isNegative($units)) {
            return false;
        }

        foreach (['net_total', 'tax_total', 'sale_plus_tax'] as $key) {
            if (Decimal::units($amounts[$key]) !== 0) {
                return false;
            }
        }

        foreach ($discounts as $value) {
            if (Decimal::units($value) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function checkDate(ImportRow $row, DateTimeImmutable $issued): void
    {
        if ($issued > new DateTimeImmutable('+1 day')) {
            $row->warn('issued_on', 'future_date', 'La fecha es posterior a hoy.');
        } elseif ($issued < new DateTimeImmutable('2000-01-01')) {
            $row->warn('issued_on', 'old_date', 'La fecha es anterior al año 2000.');
        }
    }

    private function limit(ImportRow $row, string $key, ?string $value): ?string
    {
        if ($value !== null && mb_strlen($value) > 255) {
            $row->fail($key, 'too_long', 'El texto supera los 255 caracteres.');

            return null;
        }

        return $value;
    }

    /** @param  array<string, string|null>  $amounts */
    private function collect(Company $company, DateTimeImmutable $issued, ?string $units, array $amounts, ?string $seller, ?string $operator, array $discounts = []): void
    {
        $day = $issued->format('Y-m-d');
        $this->stats['min'] = $this->stats['min'] === null || $day < $this->stats['min'] ? $day : $this->stats['min'];
        $this->stats['max'] = $this->stats['max'] === null || $day > $this->stats['max'] ? $day : $this->stats['max'];
        $this->stats['net'] += Decimal::units($amounts['net_total']);
        $this->stats['tax'] += Decimal::units($amounts['tax_total']);
        $this->stats['grand'] += Decimal::units($amounts['grand_total']);
        $this->stats['cost'] += Decimal::units($amounts['line_cost_total']);
        foreach ($discounts as $key => $value) {
            $this->stats[$key] = ($this->stats[$key] ?? 0) + Decimal::units($value);
        }

        $perCompany = $this->stats['lines'][$company->id] ?? ['lines' => 0, 'net' => 0];
        $perCompany['lines']++;
        $perCompany['net'] += Decimal::units($amounts['net_total']);
        $this->stats['lines'][$company->id] = $perCompany;

        $this->people['seller'][$company->id.'|'.Seller::normalize($seller)] ??= $seller;
        $this->people['operator'][$company->id.'|'.Operator::normalize($operator)] ??= $operator;
    }

    /** @return array<string, string> clave de documento => content_hash ya guardado */
    private function existingDocuments(ImportContext $context): array
    {
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['document'];
        }

        $found = [];
        foreach ($byCompany as $companyId => $numbers) {
            foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
                SalesDocument::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('number', $chunk)
                    ->get(['company_id', 'series', 'number', 'content_hash'])
                    ->each(function (SalesDocument $d) use (&$found) {
                        $found["{$d->company_id}|{$d->series}|{$d->number}"] = $d->content_hash;
                    });
            }
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function summary(ImportContext $context): array
    {
        $companies = collect($this->companies())->unique('id')->keyBy('id');
        $docsPerCompany = [];
        foreach ($context->groups as $group) {
            $id = (int) $group->fields['company'];
            $docsPerCompany[$id] = ($docsPerCompany[$id] ?? 0) + 1;
        }

        return [
            'date_from' => $this->stats['min'],
            'date_to' => $this->stats['max'],
            'zero_lines_skipped' => $this->stats['skipped'],
            'totals' => [
                'net_total' => Decimal::format($this->stats['net']),
                'tax_total' => Decimal::format($this->stats['tax']),
                'grand_total' => Decimal::format($this->stats['grand']),
                'cost_total' => Decimal::format($this->stats['cost']),
                ...(isset($this->stats['discount_total']) ? ['discount_total' => Decimal::format($this->stats['discount_total'])] : []),
            ],
            'companies' => collect($this->stats['lines'])->map(fn (array $s, int $id) => [
                'id' => $id,
                'name' => $companies[$id]->name ?? "#{$id}",
                'documents' => $docsPerCompany[$id] ?? 0,
                'lines' => $s['lines'],
                'net_total' => Decimal::format($s['net']),
            ])->values()->all(),
            'new_sellers' => $this->newPeople(Seller::class, 'seller'),
            'new_operators' => $this->newPeople(Operator::class, 'operator'),
        ];
    }

    /**
     * Nombres que se darán de alta (no existen aún en la compañía).
     *
     * @param  class-string<Seller|Operator>  $model
     * @return list<string>
     */
    private function newPeople(string $model, string $kind): array
    {
        $companyIds = array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], array_keys($this->people[$kind])));
        $known = $model::whereIn('company_id', $companyIds)->get(['company_id', 'normalized_name'])
            ->map(fn ($p) => $p->company_id.'|'.$p->normalized_name)->all();

        return array_values(array_map(
            fn ($name) => $name,
            array_filter($this->people[$kind], fn ($name, $key) => ! in_array($key, $known, true), ARRAY_FILTER_USE_BOTH),
        ));
    }

    private function locations(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user);
    }

    /** @return array<string, Company> */
    private function companies(): array
    {
        return $this->locations()->companies();
    }
}
