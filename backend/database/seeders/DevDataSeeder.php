<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de desarrollo completamente ficticios. Idempotente.
 * Todos los usuarios comparten la contraseña de desarrollo definida abajo.
 */
class DevDataSeeder extends Seeder
{
    public const PASSWORD = 'Desarrollo-123';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevDataSeeder no puede ejecutarse en producción.');
        }

        $alfa = Company::updateOrCreate(['code' => 'ALFA'], ['name' => 'Compañía Alfa (ficticia)', 'is_active' => true]);
        $beta = Company::updateOrCreate(['code' => 'BETA'], ['name' => 'Compañía Beta (ficticia)', 'is_active' => true]);

        $a1 = $this->branch($alfa, 'CEN', 'Alfa - Central');
        $a2 = $this->branch($alfa, 'NOR', 'Alfa - Norte');
        $b1 = $this->branch($beta, 'CEN', 'Beta - Central');

        $this->user('admin', 'Administrador Demo', Role::ADMINISTRADOR);
        $this->user('gerente', 'Gerente Demo', Role::GERENTE_GENERAL, [$alfa, $beta]);
        $this->user('supervisor', 'Supervisor Demo', Role::SUPERVISOR_VENTAS, [$alfa], [$a1, $a2]);
        $this->user('vendedor1', 'Vendedor Uno Demo', Role::VENDEDOR, [$alfa], [$a1]);
        $this->user('vendedor2', 'Vendedor Dos Demo', Role::VENDEDOR, [$beta], [$b1]);
    }

    private function branch(Company $company, string $code, string $name): Branch
    {
        return Branch::updateOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
    }

    /**
     * @param  list<Company>  $companies
     * @param  list<Branch>  $branches
     */
    private function user(string $username, string $name, string $role, array $companies = [], array $branches = []): void
    {
        $user = User::updateOrCreate(['username' => $username], [
            'name' => $name,
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', $role)->value('id'),
            'is_active' => true,
        ]);

        $user->companies()->withoutGlobalScopes()->sync(collect($companies)->pluck('id'));
        $user->branches()->withoutGlobalScopes()->sync(collect($branches)->pluck('id'));
    }
}
