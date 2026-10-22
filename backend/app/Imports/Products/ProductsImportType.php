<?php

declare(strict_types=1);

namespace App\Imports\Products;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\User;
use App\Services\DataScope;

/**
 * Archivo maestro de productos: una fila por producto (compañía + código). Los nuevos se insertan y los existentes se
 * actualizan con lo que trae el archivo; una celda vacía en un nivel borra ese dato. El código es un número entero único
 * por compañía. La sucursal indica dónde se facturó y solo se valida: un mismo producto se repite en varias sucursales
 * (esas filas no son conflicto mientras el resto de los datos coincida).
 * Nivel 1 = grupo general, Nivel 2 = línea, Nivel 3 = categoría.
 */
final class ProductsImportType implements ImportType
{
    /** Datos del producto (sin compañía/sucursal) que se comparan para saber si hubo cambios. */
    private const DATA = ['name', 'level1', 'level2', 'level3'];

    private const HINTS = [
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'branch' => 'Código o nombre de la sucursal donde se generó la factura (se valida; el producto es de la compañía).',
        'code' => 'Código del producto: número entero, único por compañía.',
        'name' => 'Descripción o nombre del producto.',
        'level1' => 'Nivel 1: grupo general.',
        'level2' => 'Nivel 2: línea.',
        'level3' => 'Nivel 3: categoría.',
    ];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'products';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
    }

    public function columns(): array
    {
        $optional = config('imports.products.optional', []);

        return array_values(collect((array) config('imports.products.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, array_values($aliases), required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->all());
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $code = $this->integerCode($row);
        $name = $this->limited($row, 'name', $row->text('name'));
        $fields = [
            'level1' => $this->limited($row, 'level1', $row->text('level1', required: false)),
            'level2' => $this->limited($row, 'level2', $row->text('level2', required: false)),
            'level3' => $this->limited($row, 'level3', $row->text('level3', required: false)),
        ];

        [$company, $branch] = $this->resolver()->resolve($row);

        if ($row->failed() || $code === null || ! $company || ! $branch) {
            return null;
        }

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$code}",
            ['company' => (string) $company->id, 'code' => $code, 'name' => $name, ...$fields],
            [],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existing($context);
        $updated = 0;

        foreach ($context->groups as $key => $group) {
            $current = $existing[$key] ?? null;

            if ($current === null) {
                $context->documentsNew++;
            } elseif ($this->same($current, $group->fields)) {
                $context->documentsDuplicate++;     // sin cambios
                $context->existingKeys[$key] = true;
            } else {
                $updated++;
            }
        }

        $context->summary = [...$context->summary, 'products' => ['new' => $context->documentsNew, 'updated' => $updated, 'unchanged' => $context->documentsDuplicate]];
    }

    public function persist(ImportContext $context): void
    {
        $now = now();
        $new = [];

        foreach ($context->groups as $key => $group) {
            if (isset($context->existingKeys[$key])) {
                continue;
            }
            $f = $group->fields;
            $values = [...array_intersect_key($f, array_flip(self::DATA)), 'updated_at' => $now];
            $where = ['company_id' => (int) $f['company'], 'code' => $f['code']];

            if (Product::withoutGlobalScopes()->where($where)->exists()) {
                Product::withoutGlobalScopes()->where($where)->update($values);
            } else {
                $new[] = [...$where, ...$values, 'created_at' => $now];
            }
        }

        foreach (array_chunk($new, 500) as $chunk) {
            Product::withoutGlobalScopes()->insert($chunk);
        }
    }

    /** @return array<string, Product> clave «compañía|código» => producto guardado */
    private function existing(ImportContext $context): array
    {
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['code'];
        }

        $found = [];
        foreach ($byCompany as $companyId => $codes) {
            foreach (array_chunk(array_unique($codes), 500) as $chunk) {
                Product::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', $chunk)->get()
                    ->each(function (Product $p) use (&$found) {
                        $found["{$p->company_id}|{$p->code}"] = $p;
                    });
            }
        }

        return $found;
    }

    /** @param  array<string, mixed>  $fields */
    private function same(Product $product, array $fields): bool
    {
        foreach (self::DATA as $key) {
            if (($fields[$key] ?? null) !== $product->{$key}) {
                return false;
            }
        }

        return true;
    }

    private function integerCode(ImportRow $row): ?string
    {
        $value = $row->raw('code');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $row->fail('code', 'required', 'El valor es obligatorio.');

            return null;
        }

        $text = is_float($value) && floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : trim((string) $value);
        if (! preg_match('/^\d{1,18}$/', $text)) {
            $row->fail('code', 'invalid_product_code', 'El código del producto debe ser un número entero (sin letras, decimales ni signos).');

            return null;
        }

        return $text;
    }

    private function limited(ImportRow $row, string $key, ?string $value, int $max = 255): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $row->fail($key, 'too_long', "El texto supera los {$max} caracteres.");

            return null;
        }

        return $value;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user, $this->scope);
    }
}
