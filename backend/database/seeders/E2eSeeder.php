<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Dato fijo para las pruebas de extremo a extremo (`e2e/`): un cliente reconocible. Repetible (no duplica) y nunca en producción.
 * Uso: php artisan db:seed --class=E2eSeeder
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('E2eSeeder no puede ejecutarse en producción.');
        }

        $company = Company::withoutGlobalScopes()->orderBy('id')->first() ?? throw new RuntimeException('Cree antes las compañías (php artisan migrate --seed).');
        // Cuenta con clave temporal para la prueba del primer ingreso. Cada vez que se siembra vuelve a su estado inicial.
        User::withoutGlobalScopes()->updateOrCreate(
            ['username' => 'primer.ingreso'],
            ['name' => 'Primer Ingreso', 'password' => 'Desarrollo-123', 'role_id' => Role::where('slug', Role::VENDEDOR)->value('id'), 'is_active' => true, 'must_change_password' => true, 'password_changed_at' => null],
        );
        Customer::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'E2E-001'],
            ['name' => 'Cliente E2E', 'source' => 'manual'],
        );
    }
}
