<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** Un permiso por opción del menú, en el orden en que aparece el menú. */
    public const PERMISSIONS = [
        'companies.manage' => 'Compañías',
        'branches.manage' => 'Sucursales',
        'users.manage' => 'Usuarios',
        'roles.manage' => 'Roles y permisos',
        'audit.view' => 'Auditoría',
        'imports.manage' => 'Importaciones',
        'sales.view' => 'Inteligencia de ventas',
        'customers.view' => 'Clientes',
        'customers.manage' => 'Gestión de clientes (tipos y asignaciones)',
        'goals.manage' => 'Captura de metas de ventas',
        'interactions.manage' => 'Seguimiento de clientes (registrar contactos)',
        'credit.view' => 'Crédito y cobranzas (consulta de facturas y saldos)',
        'credit.payments' => 'Crédito y cobranzas (registrar abonos)',
        'credit.promises' => 'Crédito y cobranzas (registrar promesas de pago)',
        'credit.notes' => 'Crédito y cobranzas (registrar notas de crédito)',
        'inventory.view' => 'Inventario (consultar existencias y kárdex)',
        'inventory.manage' => 'Inventario (unidades, equivalencias y movimientos)',
        'inventory.costs' => 'Inventario (ver costos y valuación)',
        'insights.view' => 'Inteligencia comercial (consultar análisis y recomendaciones)',
        'insights.manage' => 'Inteligencia comercial (cambiar parámetros)',
        'digests.view' => 'Resúmenes para gerencia (diario y semanal)',
        'mail.manage' => 'Correo (configurar el servidor y consultar los envíos)',
        'whatsapp.manage' => 'WhatsApp (configurar la cuenta, plantillas y consultar los mensajes)',
        'conversations.view' => 'Conversaciones de WhatsApp (ver las de su alcance)',
        'conversations.reply' => 'Conversaciones de WhatsApp (escribir a clientes de su alcance)',
        'webhooks.manage' => 'Webhook de salida (configurar el destino y consultar los envíos)',
        'automations.manage' => 'Automatizaciones (configurar, pausar y consultar el registro)',
    ];

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

        // Un permiso por opción del menú. Los de supervisores están pendientes de definir (decisión #19):
        // se otorgan desde la pantalla «Roles y permisos».
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        // Migración del permiso anterior organization.manage → companies.manage + branches.manage.
        $legacy = Permission::where('slug', 'organization.manage')->first();
        if ($legacy) {
            $new = Permission::whereIn('slug', ['companies.manage', 'branches.manage'])->pluck('id');
            foreach ($legacy->roles as $role) {
                $role->permissions()->syncWithoutDetaching($new);
            }
            $legacy->delete();
        }

        Role::where('slug', Role::ADMINISTRADOR)->first()
            ->permissions()->sync(Permission::pluck('id'));
    }
}
