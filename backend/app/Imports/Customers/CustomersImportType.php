<?php

declare(strict_types=1);

namespace App\Imports\Customers;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;

/**
 * Archivo maestro de clientes (con tipo, vendedor y facturador opcionales por nombre): una fila por cliente (compañía + código). Los nuevos se insertan y los existentes se
 * actualizan con lo que trae el archivo; una celda vacía en una columna opcional borra ese dato. El código es único
 * por compañía y es un número entero: se conserva tal cual (con sus ceros iniciales si venía como texto) porque es
 * lo que enlaza al cliente con sus ventas.
 */
final class CustomersImportType implements ImportType
{
    /** Datos del cliente (sin compañía/sucursal) que se comparan para saber si hubo cambios. */
    private const DATA = ['name', 'trade_name', 'contact_name', 'phone', 'whatsapp', 'email', 'address'];

    private const HINTS = [
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'branch' => 'Código o nombre de la sucursal de esa compañía.',
        'code' => 'Código del cliente: número entero, único por compañía. Con él se enlaza al cliente con sus ventas.',
        'name' => 'Descripción o nombre del cliente.',
        'phone' => 'Teléfono (dígitos, y opcionalmente +, espacios, guiones o paréntesis).',
        'email' => 'Correo electrónico.',
        'whatsapp' => 'Número de WhatsApp (mismo formato que el teléfono).',
        'address' => 'Dirección.',
        'trade_name' => 'Nombre comercial.',
        'contact_name' => 'Nombre de la persona de contacto.',
        'type' => 'Tipo de cliente, tal como está en el catálogo de tipos (activo). Si la columna viene en el archivo, vacío quita el tipo.',
        'seller' => 'Vendedor asignado, tal como está en el catálogo de la compañía (activo). Si la columna viene, vacío deja al cliente en la tienda por defecto.',
        'operator' => 'Facturador asignado, tal como está en el catálogo de la compañía (activo). Si la columna viene, vacío quita el facturador.',
    ];

    /** Columnas de asignación opcionales: columna → campo del cliente. Solo se tocan si la columna viene en el archivo. */
    private const LINKS = ['type' => 'customer_type_id', 'seller' => 'seller_id', 'operator' => 'operator_id'];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    /** @var array<string, int>|null nombre normalizado => id de tipo activo */
    private ?array $types = null;

    /** @var array<string, array<string, int>> «seller|operator» + compañía => nombre normalizado => id */
    private array $people = [];

    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'customers';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->types = null;
        $this->people = [];
    }

    public function columns(): array
    {
        $optional = config('imports.customers.optional', []);

        return collect((array) config('imports.customers.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, $aliases, required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->values()->all();
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $code = $this->integerCode($row);
        $name = $this->limited($row, 'name', $row->text('name'));
        $fields = [
            'trade_name' => $this->limited($row, 'trade_name', $row->text('trade_name', required: false)),
            'contact_name' => $this->limited($row, 'contact_name', $row->text('contact_name', required: false)),
            'phone' => $this->phone($row, 'phone'),
            'whatsapp' => $this->phone($row, 'whatsapp'),
            'email' => $this->email($row),
            'address' => $this->limited($row, 'address', $row->text('address', required: false), 500),
        ];

        [$company, $branch] = $this->resolver()->resolve($row);

        $links = $company ? $this->links($row, $company->id) : [];

        if ($row->failed() || $code === null || ! $company || ! $branch) {
            return null;
        }

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$code}",
            ['company' => (string) $company->id, 'branch' => (string) $branch->id, 'code' => $code, 'name' => $name, ...$fields, ...$links],
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

        $context->summary = [...$context->summary, 'customers' => ['new' => $context->documentsNew, 'updated' => $updated, 'unchanged' => $context->documentsDuplicate]];
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
            $links = [];
            foreach (self::LINKS as $field) {
                if (array_key_exists($field, $f)) {
                    $links[$field] = $f[$field] === null ? null : (int) $f[$field];
                }
            }
            $values = ['branch_id' => (int) $f['branch'], 'source' => 'import', ...array_intersect_key($f, array_flip(self::DATA)), ...$links, 'updated_at' => $now];
            $where = ['company_id' => (int) $f['company'], 'code' => $f['code']];

            if (Customer::withoutGlobalScopes()->where($where)->exists()) {
                Customer::withoutGlobalScopes()->where($where)->update($values);
            } else {
                $new[] = [...$where, 'customer_type_id' => null, 'seller_id' => null, 'operator_id' => null, ...$values, 'created_at' => $now];
            }
        }

        foreach (array_chunk($new, 500) as $chunk) {
            Customer::withoutGlobalScopes()->insert($chunk);
        }
    }

    /** @return array<string, Customer> clave «compañía|código» => cliente guardado */
    private function existing(ImportContext $context): array
    {
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['code'];
        }

        $found = [];
        foreach ($byCompany as $companyId => $codes) {
            foreach (array_chunk(array_unique($codes), 500) as $chunk) {
                Customer::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', $chunk)->get()
                    ->each(function (Customer $c) use (&$found) {
                        $found["{$c->company_id}|{$c->code}"] = $c;
                    });
            }
        }

        return $found;
    }

    /** @param  array<string, string|null>  $fields */
    private function same(Customer $customer, array $fields): bool
    {
        if ((int) $fields['branch'] !== (int) $customer->branch_id) {
            return false;
        }
        foreach (self::DATA as $key) {
            if (($fields[$key] ?? null) !== $customer->{$key}) {
                return false;
            }
        }
        foreach (self::LINKS as $field) {
            if (array_key_exists($field, $fields) && ($fields[$field] === null ? null : (int) $fields[$field]) !== $customer->{$field}) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tipo, vendedor y facturador por nombre. Solo para las columnas que vienen en el archivo; el nombre debe existir
     * y estar activo (el catálogo no se crea desde este archivo). Vacío quita la asignación.
     *
     * @return array<string, string|null> campo del cliente => id (o null para quitar)
     */
    private function links(ImportRow $row, int $companyId): array
    {
        $out = [];
        foreach (self::LINKS as $column => $field) {
            if (! $row->has($column)) {
                continue;
            }
            $name = $row->text($column, required: false);
            if ($name === null) {
                $out[$field] = null;

                continue;
            }
            $id = $this->lookup($column, $companyId)[Seller::normalize($name)] ?? null;
            if ($id === null) {
                $row->fail($column, 'unknown_'.$column, match ($column) {
                    'type' => 'El tipo de cliente no existe o está inactivo. Créalo antes en Clientes → Tipos de cliente.',
                    'seller' => 'El vendedor no existe o está inactivo en esa compañía. Créalo antes en Clientes → Vendedores.',
                    default => 'El facturador no existe o está inactivo en esa compañía. Créalo antes en Clientes → Facturadores.',
                });

                continue;
            }
            $out[$field] = (string) $id;
        }

        return $out;
    }

    /** @return array<string, int> nombre normalizado => id */
    private function lookup(string $column, int $companyId): array
    {
        if ($column === 'type') {
            return $this->types ??= CustomerType::query()->where('active', true)->get()->mapWithKeys(fn (CustomerType $t) => [Seller::normalize($t->name) => $t->id])->all();
        }

        return $this->people[$column.$companyId] ??= ($column === 'seller' ? Seller::query() : Operator::query())
            ->where('company_id', $companyId)->where('is_active', true)->pluck('id', 'normalized_name')->map(fn ($id) => (int) $id)->all();
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
            $row->fail('code', 'invalid_customer_code', 'El código del cliente debe ser un número entero (sin letras, decimales ni signos).');

            return null;
        }

        return $text;
    }

    private function phone(ImportRow $row, string $key): ?string
    {
        $value = $row->text($key, required: false);
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^\+?[0-9][0-9 ()\-.]{3,24}$/', $value)) {
            $row->fail($key, 'invalid_phone', 'No es un teléfono válido (use dígitos y, si quiere, +, espacios, guiones o paréntesis).');

            return null;
        }

        return $value;
    }

    private function email(ImportRow $row): ?string
    {
        $value = $this->limited($row, 'email', $row->text('email', required: false));
        if ($value !== null && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $row->fail('email', 'invalid_email', 'No es un correo electrónico válido.');

            return null;
        }

        return $value;
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
