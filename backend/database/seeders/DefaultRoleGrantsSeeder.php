<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Permisos de partida de Gerente general, Supervisor de ventas y Vendedor: `php artisan db:seed --class=DefaultRoleGrantsSeeder`.
 * Solo AGREGA (nunca quita un permiso que administración otorgó o retiró a mano) y se puede repetir. El administrador siempre lo tiene todo.
 *
 * Criterio (decisión #19 de docs/10_DECISIONES_PENDIENTES.md, propuesta confirmada por administración):
 *  - Gerente: consulta de todo lo comercial, costos de inventario y reportes; las conversaciones solo en lectura (nunca escribe a clientes).
 *  - Supervisor: lo de gerente sin costos, más el trato con clientes de su equipo: seguimiento, promesas de pago, asignaciones y responder conversaciones.
 *  - Vendedor: sus clientes (seguimiento, crédito y promesas) y sus conversaciones.
 *  - La configuración (usuarios, roles, compañías, sucursales, correo, WhatsApp, webhook, automatizaciones, importaciones y auditoría) es solo del administrador.
 *
 * @see RolePermissionSeeder::PERMISSIONS
 */
class DefaultRoleGrantsSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    public const GRANTS = [
        Role::GERENTE_GENERAL => [
            'sales.view', 'customers.view', 'goals.manage', 'credit.view', 'inventory.view', 'inventory.costs',
            'insights.view', 'digests.view', 'reports.view', 'conversations.view',
        ],
        Role::SUPERVISOR_VENTAS => [
            'sales.view', 'customers.view', 'customers.manage', 'goals.manage', 'interactions.manage', 'credit.view', 'credit.promises',
            'inventory.view', 'insights.view', 'digests.view', 'reports.view', 'conversations.view', 'conversations.reply',
        ],
        Role::VENDEDOR => [
            'customers.view', 'interactions.manage', 'credit.view', 'credit.promises', 'conversations.view', 'conversations.reply',
        ],
    ];

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        foreach (self::GRANTS as $role => $slugs) {
            $permissions = Permission::whereIn('slug', $slugs)->pluck('id');
            if ($permissions->count() !== count($slugs)) {
                throw new RuntimeException("Hay permisos por defecto que no existen para el rol {$role}.");
            }

            Role::where('slug', $role)->firstOrFail()->permissions()->syncWithoutDetaching($permissions);
        }
    }
}
