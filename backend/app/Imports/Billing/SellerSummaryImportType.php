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
use App\Models\ImportBatch;
use App\Models\Seller;
use App\Models\SellerSalesSummary;
use App\Models\User;
use App\Services\DataScope;

/**
 * Resumen de ventas por vendedor del sistema de origen: los totales oficiales (Venta Neta, impuesto, No. de facturas) acumulados
 * desde el inicio del período hasta la fecha de corte. Sirve para conciliar las ventas importadas.
 *
 * El archivo no trae compañía ni fecha: se agregan las columnas «Compañía» y «Fecha Corte» (y, si el período no empieza el día 1,
 * «Fecha Desde»). Un archivo del mismo corte reemplaza al anterior. «Venta Neta» vacía se toma como Venta − Devoluciones.
 */
final class SellerSummaryImportType implements ImportType
{
    private const HINTS = [
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'cutoff' => 'Fecha de corte del reporte: el último día incluido.',
        'period_from' => 'Primer día incluido (opcional). Si falta, es el día 1 del mes de la fecha de corte.',
        'seller_name' => 'Nombre del vendedor que aplicó la venta.',
        'gross_sale' => 'Monto bruto de venta.',
        'discount' => 'Descuento ofrecido por el vendedor.',
        'sale' => 'Venta después de descuento.',
        'returns' => 'Devoluciones del vendedor (vacío = 0).',
        'net_sale' => 'Venta antes de impuesto: el dato oficial que se concilia. Si viene vacía se toma Venta − Devoluciones.',
        'tax' => 'Impuesto de la venta neta.',
        'withholding' => 'Retención de impuesto (vacío = 0).',
        'total' => 'Total incluyendo impuesto y retención.',
        'invoices' => 'Número de facturas del vendedor.',
    ];

    private const AMOUNTS = ['gross_sale', 'discount', 'sale', 'returns', 'net_sale', 'tax', 'withholding', 'total'];

    private const OPTIONAL = ['period_from', 'returns', 'net_sale', 'withholding'];

    private const TOTAL_ROWS = ['total', 'totales', 'totalgeneral'];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    /** @var array<string, int> */
    private array $sums = [];

    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'seller_summary';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->sums = ['net_sale' => 0, 'tax' => 0, 'invoices' => 0];
    }

    public function columns(): array
    {
        return array_values(collect((array) config('imports.seller_summary.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, array_values($aliases), required: ! in_array($key, self::OPTIONAL, true), hint: self::HINTS[$key] ?? null))
            ->all());
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $seller = RowRules::limit($row, 'seller_name', $row->text('seller_name'));
        if ($seller !== null && in_array(Seller::normalize($seller), self::TOTAL_ROWS, true)) {
            $row->skip('seller_name', 'total_row_skipped', 'Fila de totales omitida: el total se calcula con las filas de los vendedores.');

            return null;
        }

        $formats = config('imports.sales.date_formats');
        $cutoff = $row->date('cutoff', $formats);
        $from = $row->has('period_from') ? $row->date('period_from', $formats, required: false) : null;

        $amounts = [];
        foreach (self::AMOUNTS as $key) {
            $amounts[$key] = $row->has($key) ? $row->decimal($key, required: ! in_array($key, self::OPTIONAL, true)) : null;
        }
        $invoices = $row->code('invoices', warnNumeric: false);
        if ($invoices !== null && ! ctype_digit($invoices)) {
            $row->fail('invoices', 'invalid_number', 'El número de facturas debe ser un entero.');
        }

        $company = $this->resolver()->resolveCompany($row);

        if ($row->failed() || ! $cutoff || ! $company) {
            return null;
        }

        $from ??= $cutoff->modify('first day of this month');
        if ($from > $cutoff) {
            $row->fail('period_from', 'invalid_period', 'La fecha desde es posterior a la fecha de corte.');

            return null;
        }

        $amounts['returns'] ??= Decimal::format(0);
        $amounts['withholding'] ??= Decimal::format(0);
        $amounts['net_sale'] ??= Decimal::format(Decimal::units($amounts['sale']) - Decimal::units($amounts['returns']));

        $this->check($row, $amounts);
        $this->sums['net_sale'] += Decimal::units($amounts['net_sale']);
        $this->sums['tax'] += Decimal::units($amounts['tax']);
        $this->sums['invoices'] += (int) $invoices;

        return new ParsedRow(
            $row->number,
            $company->id.'|'.$cutoff->format('Y-m-d').'|'.Seller::normalize((string) $seller),
            ['company' => (string) $company->id, 'cutoff' => $cutoff->format('Y-m-d'), 'period_from' => $from->format('Y-m-d'), 'seller_name' => $seller],
            [...$amounts, 'invoices' => $invoices],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existing($context);

        foreach ($context->groups as $key => $group) {
            if (isset($existing[$key])) {
                $context->documentsDuplicate++; // ya había un resumen de ese vendedor y corte: se reemplaza
            } else {
                $context->documentsNew++;
            }

            if ($group->linesCount() > 1) {
                $context->errors->error('duplicate_seller', "El vendedor «{$group->fields['seller_name']}» aparece más de una vez para el corte {$group->fields['cutoff']}.", $group->firstRow);
            }
        }

        $cutoffs = collect($context->groups)->map(fn ($g) => $g->fields['cutoff'])->unique()->sort()->values();
        $context->summary = [...$context->summary, 'seller_summary' => [
            'cutoffs' => $cutoffs->all(),
            'replaced' => $context->documentsDuplicate,
            'net_sale' => Decimal::format($this->sums['net_sale']),
            'tax' => Decimal::format($this->sums['tax']),
            'invoices' => $this->sums['invoices'],
            'unknown_sellers' => $this->unknownSellers($context),
        ]];
    }

    public function persist(ImportContext $context): void
    {
        $now = now();
        $rows = [];
        foreach ($context->rows() as $row) {
            $group = $context->groups[$row->groupKey];
            $f = $group->fields;
            $normalized = Seller::normalize($f['seller_name']);

            SellerSalesSummary::withoutGlobalScopes()->where('company_id', (int) $f['company'])->where('cutoff_on', $f['cutoff'])->where('normalized_name', $normalized)->delete();
            $rows[] = [
                'company_id' => (int) $f['company'],
                'import_batch_id' => $context->batch->id,
                'period_from' => $f['period_from'],
                'cutoff_on' => $f['cutoff'],
                'seller_name' => $f['seller_name'],
                'normalized_name' => $normalized,
                'seller_id' => Seller::withoutGlobalScopes()->where('company_id', (int) $f['company'])->where('normalized_name', $normalized)->value('id'),
                'gross_sale' => $row->line['gross_sale'], 'discount' => $row->line['discount'], 'sale' => $row->line['sale'],
                'returns' => $row->line['returns'], 'net_sale' => $row->line['net_sale'], 'tax' => $row->line['tax'],
                'withholding' => $row->line['withholding'], 'total' => $row->line['total'], 'invoices_count' => (int) $row->line['invoices'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SellerSalesSummary::insert($chunk);
        }
    }

    /**
     * Venta = Bruta − Descuento y Total = Neta + Impuesto − Retención: si no cuadran se avisa (los datos son los oficiales del sistema de origen).
     *
     * @param  array<string, string|null>  $a
     */
    private function check(ImportRow $row, array $a): void
    {
        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        if (abs(Decimal::units($a['gross_sale']) - Decimal::units($a['discount']) - Decimal::units($a['sale'])) > $tolerance) {
            $row->warn('sale', 'sale_mismatch', 'Venta no es igual a Venta Bruta − Descuento.');
        }
        if (abs(Decimal::units($a['net_sale']) + Decimal::units($a['tax']) - Decimal::units($a['withholding']) - Decimal::units($a['total'])) > $tolerance) {
            $row->warn('total', 'total_mismatch', 'Total no es igual a Venta Neta + Impuesto − Retención.');
        }
    }

    /** @return array<string, true> */
    private function existing(ImportContext $context): array
    {
        $found = [];
        foreach ($context->groups as $key => $group) {
            $cutoffs[(int) $group->fields['company']][$group->fields['cutoff']] = true;
        }
        foreach ($cutoffs ?? [] as $companyId => $days) {
            SellerSalesSummary::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('cutoff_on', array_keys($days))
                ->get(['company_id', 'cutoff_on', 'normalized_name'])
                ->each(function (SellerSalesSummary $s) use (&$found) {
                    $found["{$s->company_id}|{$s->cutoff_on->format('Y-m-d')}|{$s->normalized_name}"] = true;
                });
        }

        return $found;
    }

    /** @return list<string> vendedores del resumen que no existen aún en la compañía (no se crean: solo se concilian con los de las ventas) */
    private function unknownSellers(ImportContext $context): array
    {
        $names = [];
        foreach ($context->groups as $group) {
            $names[(int) $group->fields['company']][Seller::normalize($group->fields['seller_name'])] = $group->fields['seller_name'];
        }

        $unknown = [];
        foreach ($names as $companyId => $byKey) {
            $known = Seller::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('normalized_name', array_keys($byKey))->pluck('normalized_name')->all();
            foreach ($byKey as $key => $name) {
                if (! in_array($key, $known, true)) {
                    $unknown[$name] = $name;
                }
            }
        }

        return array_values($unknown);
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user, $this->scope);
    }
}
