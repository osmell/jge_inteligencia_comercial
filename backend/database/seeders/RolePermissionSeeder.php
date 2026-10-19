<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::ADMINISTRADOR => 'Administrador',
            Role::GERENTE_GENERAL => 'Gerente General',
            Role::SUPERVISOR_VENTAS => 'Supervisor de Ventas',
            Role::VENDEDOR => 'Vendedor',
        ];

        foreach ($roles as $slug => $name) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        // Permisos de Fases 1 y 2. Los de supervisores están pendientes de definir (decisión #19).
        $permissions = [
            'organization.manage' => 'Gestionar compañías y sucursales',
            'users.manage' => 'Gestionar usuarios y roles',
            'audit.view' => 'Consultar auditoría',
            'imports.manage' => 'Importar archivos (validar, confirmar, descartar)',
        ];

        foreach ($permissions as $slug => $name) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        Role::where('slug', Role::ADMINISTRADOR)->first()
            ->permissions()->sync(Permission::pluck('id'));
    }
}
