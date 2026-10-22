<?php

declare(strict_types=1);

namespace App\Imports\Credit;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\Decimal;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Imports\Rules\CreditAmounts;
use App\Imports\Rules\RowRules;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Facturas de crédito: una fila por factura (compañía + serie + documento), que es la misma factura de venta. Moneda única.
 *
 * Reglas:
 *  - Subtotal − Descuento + Impuesto = Total (tolerancia de redondeo configurable); no se aceptan importes negativos ni un total en cero.
 *  - Una factura que ya existe con los mismos datos se omite; con datos distintos es un error (no se sobrescribe: una corrección
 *    es un movimiento del libro).
 *  - Si la factura de venta ya está cargada se enlaza y se compara (avisos, no errores); si llega después, se enlaza al importar las ventas.
 *  - Cada factura nueva abre su saldo con un movimiento «factura» en el libro.
 */
final class CreditInvoicesImportType implements ImportType
{
    private const HINTS = [
        'issued_on' => 'Fecha de la factura. La antigüedad de la cartera se cuenta desde esta fecha.',
        'discount_total' => 'Descuento del documento (vacío = 0).',
        'subtotal' => 'Subtotal del documento, antes del descuento.',
        'tax_total' => 'Impuesto del documento.',
        'total' => 'Subtotal − Descuento + Impuesto. Moneda única (NIO).',
        'document' => 'Número del documento. Compañía + Serie + Documento identifican la factura de venta.',
        'series' => 'Serie del documento.',
        'customer_code' => 'Código del cliente.',
        'customer_name' => 'Nombre del cliente.',
        'seller_name' => 'Nombre del vendedor.',
        'operator_name' => 'Nombre del operador que emitió la factura.',
        'branch' => 'Código o nombre de la sucursal donde se generó la factura.',
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
    ];

    private const AMOUNTS = ['subtotal', 'discount_total', 'tax_total', 'total'];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    private ?string $min = null;

    private ?string $max = null;

    /** @var array<string, int> */
    private array $sums = [];

    /** @var array<int, int> documentos por compañía */
    private array $perCompany = [];

    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'credit_invoices';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->min = $this->max = null;
        $this->sums = array_fill_keys(self::AMOUNTS, 0);
        $this->perCompany = [];
    }

    public function columns(): array
    {
        $optional = config('imports.credit_invoices.optional', []);

        return array_values(collect((array) config('imports.credit_invoices.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, array_values($aliases), required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->all());
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $document = RowRules::limit($row, 'document', $row->code('document', warnNumeric: false));
        $series = RowRules::limit($row, 'series', $row->code('series'));
        $issued = $row->date('issued_on', config('imports.sales.date_formats'));
        $customerCode = RowRules::limit($row, 'customer_code', $row->code('customer_code', warnNumeric: false));
        $customerName = RowRules::limit($row, 'customer_name', $row->text('customer_name'));
        $seller = RowRules::limit($row, 'seller_name', $row->text('seller_name'));
        $operator = RowRules::limit($row, 'operator_name', $row->text('operator_name'));

        $amounts = [];
        foreach (self::AMOUNTS as $key) {
            $amounts[$key] = $row->decimal($key, required: $key !== 'discount_total');
        }
        $amounts['discount_total'] ??= Decimal::format(0);

        [$company, $branch] = $this->resolver()->resolve($row);

        if ($row->failed() || ! $issued || ! $company || ! $branch) {
            return null;
        }

        CreditAmounts::check($row, $amounts);
        RowRules::issuedDate($row, $issued);
        if ($row->failed()) {
            return null;
        }

        $this->collect($company, $issued, $amounts);

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$series}|{$document}",
            [
                'code' => "{$series}-{$document}",
                'company' => (string) $company->id, 'branch' => (string) $branch->id, 'series' => $series, 'document' => $document,
                'issued_on' => $issued->format('Y-m-d'), 'customer_code' => $customerCode, 'customer_name' => $customerName,
                'seller_name' => $seller, 'operator_name' => $operator, ...$amounts,
            ],
            [],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existing($context);
        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        $sales = $this->salesTotals($context);
        $linked = 0;
        $mismatches = 0;

        foreach ($context->groups as $key => $group) {
            $hash = $this->hash($group->fields);
            if (! isset($existing[$key])) {
                $context->documentsNew++;
            } elseif ($existing[$key] === $hash) {
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;

                continue;
            } else {
                $context->errors->error(
                    'document_conflict',
                    "La factura de crédito {$group->fields['code']} ya existe con contenido distinto. No se sobrescribe: una corrección se registra como movimiento.",
                    $group->firstRow,
                    config('imports.credit_invoices.columns.document.0'),
                    $group->fields['document'],
                );

                continue;
            }

            $sale = $sales[$key] ?? null;
            if ($sale === null) {
                continue;
            }
            $linked++;
            $f = $group->fields;
            $net = Decimal::units($f['subtotal']) - Decimal::units($f['discount_total']);
            if ($sale['customer_code'] !== $f['customer_code']) {
                $mismatches++;
                $context->errors->warning('sales_customer_mismatch', "El cliente ({$f['customer_code']}) no coincide con el de la factura de venta ({$sale['customer_code']}).", $group->firstRow, config('imports.credit_invoices.columns.customer_code.0'), $f['customer_code']);
            }
            if (abs($sale['net'] - $net) > $tolerance || abs($sale['tax'] - Decimal::units($f['tax_total'])) > $tolerance) {
                $mismatches++;
                $context->errors->warning('sales_mismatch', sprintf(
                    'Los importes no coinciden con la factura de venta %s (neto %s e impuesto %s en ventas; %s y %s en el archivo).',
                    $f['code'], Decimal::format($sale['net']), Decimal::format($sale['tax']), Decimal::format($net), $f['tax_total'],
                ), $group->firstRow, config('imports.credit_invoices.columns.total.0'), $f['total']);
            }
        }

        $context->summary = [...$context->summary, ...$this->summary($context, $linked, $mismatches)];
    }

    public function persist(ImportContext $context): void
    {
        $now = now();
        $people = ['seller' => [], 'operator' => []];
        $pending = array_filter($context->groups, fn ($g) => ! isset($context->existingKeys[$g->key]));
        $sales = $this->salesTotals($context);

        foreach (array_chunk($pending, 500, preserve_keys: true) as $chunk) {
            $rows = [];
            foreach ($chunk as $key => $group) {
                $f = $group->fields;
                $companyId = (int) $f['company'];
                $rows[] = [
                    'company_id' => $companyId, 'branch_id' => (int) $f['branch'], 'series' => $f['series'], 'number' => $f['document'], 'issued_on' => $f['issued_on'],
                    'customer_code' => $f['customer_code'], 'customer_name' => $f['customer_name'],
                    'seller_id' => $this->person(Seller::class, $people['seller'], $companyId, $f['seller_name']),
                    'operator_id' => $this->person(Operator::class, $people['operator'], $companyId, $f['operator_name']),
                    'subtotal' => $f['subtotal'], 'discount_total' => $f['discount_total'], 'tax_total' => $f['tax_total'], 'total' => $f['total'],
                    'sales_document_id' => $sales[$key]['id'] ?? null, 'import_batch_id' => $context->batch->id, 'content_hash' => $this->hash($f),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            CreditInvoice::insert($rows);

            $ids = CreditInvoice::withoutGlobalScopes()->where('import_batch_id', $context->batch->id)->whereIn('number', array_column($rows, 'number'))
                ->get(['id', 'company_id', 'series', 'number'])->mapWithKeys(fn (CreditInvoice $i) => ["{$i->company_id}|{$i->series}|{$i->number}" => $i->id])->all();

            $movements = [];
            foreach ($chunk as $key => $group) {
                $movements[] = [
                    'company_id' => (int) $group->fields['company'], 'credit_invoice_id' => $ids[$key] ?? throw new \RuntimeException("No se pudo crear la factura de crédito {$key}."),
                    'type' => CreditMovement::INVOICE, 'amount' => $group->fields['total'], 'occurred_on' => $group->fields['issued_on'],
                    'user_id' => $context->batch->user_id, 'import_batch_id' => $context->batch->id, 'created_at' => $now,
                ];
            }
            CreditMovement::insert($movements);
        }
    }

    /**
     * Enlaza con su factura de venta las facturas de crédito que quedaron sin enlace (la venta se cargó después).
     * Se usa el constructor de consultas porque las facturas de crédito son inmutables desde los modelos.
     *
     * @param  list<int>|null  $companyIds
     */
    public static function linkPendingToSales(?array $companyIds = null): int
    {
        $query = DB::table('credit_invoices')->whereNull('sales_document_id')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds));

        $linked = 0;
        foreach ($query->get(['id', 'company_id', 'series', 'number']) as $invoice) {
            $sale = DB::table('sales_documents')->where(['company_id' => $invoice->company_id, 'series' => $invoice->series, 'number' => $invoice->number])->value('id');
            if ($sale !== null) {
                DB::table('credit_invoices')->where('id', $invoice->id)->update(['sales_document_id' => $sale]);
                $linked++;
            }
        }

        return $linked;
    }

    /** @param  array<string, mixed>  $amounts */
    private function collect(Company $company, DateTimeImmutable $issued, array $amounts): void
    {
        $day = $issued->format('Y-m-d');
        $this->min = $this->min === null || $day < $this->min ? $day : $this->min;
        $this->max = $this->max === null || $day > $this->max ? $day : $this->max;
        foreach (self::AMOUNTS as $key) {
            $this->sums[$key] += Decimal::units((string) $amounts[$key]);
        }
        $this->perCompany[$company->id] = ($this->perCompany[$company->id] ?? 0) + 1;
    }

    /** @param  array<string, mixed>  $fields */
    private function hash(array $fields): string
    {
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> clave «compañía|serie|número» => content_hash ya guardado */
    private function existing(ImportContext $context): array
    {
        $found = [];
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['document'];
        }
        foreach ($byCompany as $companyId => $numbers) {
            foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
                CreditInvoice::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('number', $chunk)->get(['company_id', 'series', 'number', 'content_hash'])
                    ->each(function (CreditInvoice $i) use (&$found) {
                        $found["{$i->company_id}|{$i->series}|{$i->number}"] = $i->content_hash;
                    });
            }
        }

        return $found;
    }

    /**
     * Importes de las facturas de venta ya cargadas que corresponden a estas facturas de crédito.
     *
     * @return array<string, array{id: int, customer_code: string, net: int, tax: int}>
     */
    private function salesTotals(ImportContext $context): array
    {
        $out = [];
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['document'];
        }
        foreach ($byCompany as $companyId => $numbers) {
            foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
                $rows = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')
                    ->where('d.company_id', $companyId)->whereIn('d.number', $chunk)
                    ->groupBy('d.id', 'd.company_id', 'd.series', 'd.number', 'd.customer_code')
                    ->selectRaw('d.id, d.company_id, d.series, d.number, d.customer_code, sum(l.net_total) as net, sum(l.tax_total) as tax')->get();
                foreach ($rows as $r) {
                    $out["{$r->company_id}|{$r->series}|{$r->number}"] = [
                        'id' => (int) $r->id, 'customer_code' => (string) $r->customer_code,
                        'net' => Decimal::units(number_format((float) $r->net, Decimal::SCALE, '.', '')), 'tax' => Decimal::units(number_format((float) $r->tax, Decimal::SCALE, '.', '')),
                    ];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function summary(ImportContext $context, int $linked, int $mismatches): array
    {
        $companies = Company::query()->whereIn('id', array_keys($this->perCompany))->pluck('name', 'id');

        return [
            'date_from' => $this->min,
            'date_to' => $this->max,
            'credit' => [
                'new' => $context->documentsNew, 'unchanged' => $context->documentsDuplicate,
                'linked_to_sales' => $linked, 'without_sales' => max(0, $context->documentsNew - $linked), 'mismatches' => $mismatches,
                'totals' => array_map(fn (int $v) => Decimal::format($v), $this->sums),
                'companies' => collect($this->perCompany)->map(fn (int $n, int $id) => ['id' => $id, 'name' => $companies[$id] ?? "#{$id}", 'documents' => $n])->values()->all(),
                'new_sellers' => $this->newPeople($context, Seller::class, 'seller_name'),
                'new_operators' => $this->newPeople($context, Operator::class, 'operator_name'),
            ],
        ];
    }

    /**
     * Nombres que se darán de alta (no existen aún en la compañía).
     *
     * @param  class-string<Seller|Operator>  $model
     * @return list<string>
     */
    private function newPeople(ImportContext $context, string $model, string $field): array
    {
        $names = [];
        foreach ($context->groups as $group) {
            $names[(int) $group->fields['company']][$model::normalize((string) $group->fields[$field])] ??= (string) $group->fields[$field];
        }

        $new = [];
        foreach ($names as $companyId => $byNormalized) {
            $known = $model::query()->where('company_id', $companyId)->whereIn('normalized_name', array_keys($byNormalized))->pluck('normalized_name')->all();
            foreach (array_diff_key($byNormalized, array_flip($known)) as $name) {
                $new[] = $name;
            }
        }

        return array_values(array_unique($new));
    }

    /**
     * @param  class-string<Seller|Operator>  $model
     * @param  array<string, int>  $cache
     */
    private function person(string $model, array &$cache, int $companyId, string $name): int
    {
        $normalized = $model::normalize($name);

        return $cache["{$companyId}|{$normalized}"] ??= $model::firstOrCreate(['company_id' => $companyId, 'normalized_name' => $normalized], ['name' => $name])->id;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user, $this->scope);
    }
}
