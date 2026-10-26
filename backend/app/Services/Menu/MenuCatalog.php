<?php

declare(strict_types=1);

namespace App\Services\Menu;

/**
 * Las opciones del menú que la administración puede ocultar a una persona, y qué datos de la API usa cada una.
 *
 * Ocultar una opción solo QUITA: la persona sigue necesitando el permiso de su rol. Y el servidor bloquea una ruta únicamente cuando se
 * ocultaron TODAS las opciones que la usan (las pantallas comparten datos: la ficha de un cliente muestra su crédito, y Crédito usa los mismos).
 *
 * `CLAIMS` asigna cada ruta (relativa a `api/v1`) a las opciones que la usan; se elige el prefijo MÁS LARGO que coincida por segmentos. Una ruta
 * sin dueño ni excepción es un olvido: `MenuCatalogTest` falla si una ruta de la API no está en `CLAIMS` ni en `EXEMPT`.
 * Las llaves deben coincidir con las de `frontend/src/components/navigation.ts` (también lo verifica esa prueba).
 */
final class MenuCatalog
{
    /** @var array<string, array{group: string, label: string, permission: string}> llave → grupo, nombre y permiso que exige la opción */
    public const OPTIONS = [
        'sales' => ['group' => 'Inteligencia', 'label' => 'Ventas', 'permission' => 'sales.view'],
        'goals' => ['group' => 'Inteligencia', 'label' => 'Metas', 'permission' => 'sales.view'],
        'customers' => ['group' => 'Inteligencia', 'label' => 'Clientes', 'permission' => 'customers.view'],
        'conversations' => ['group' => 'Inteligencia', 'label' => 'Conversaciones', 'permission' => 'conversations.view'],
        'agenda' => ['group' => 'Inteligencia', 'label' => 'Agenda', 'permission' => 'interactions.manage'],
        'follow_up' => ['group' => 'Inteligencia', 'label' => 'Seguimiento', 'permission' => 'interactions.manage'],
        'quotations' => ['group' => 'Inteligencia', 'label' => 'Cotizaciones', 'permission' => 'customers.view'],
        'credit' => ['group' => 'Inteligencia', 'label' => 'Crédito', 'permission' => 'credit.view'],
        'digests' => ['group' => 'Inteligencia', 'label' => 'Resúmenes', 'permission' => 'digests.view'],
        'insights' => ['group' => 'Inteligencia', 'label' => 'Inteligencia', 'permission' => 'insights.view'],
        'products' => ['group' => 'Inteligencia', 'label' => 'Productos', 'permission' => 'sales.view'],
        'stock' => ['group' => 'Inteligencia', 'label' => 'Existencias', 'permission' => 'inventory.view'],
        'rotation' => ['group' => 'Inteligencia', 'label' => 'Rotación', 'permission' => 'inventory.view'],
        'movements' => ['group' => 'Inteligencia', 'label' => 'Movimientos', 'permission' => 'inventory.view'],
        'units' => ['group' => 'Inteligencia', 'label' => 'Unidades', 'permission' => 'inventory.view'],
        'report_sellers' => ['group' => 'Reportes', 'label' => 'Vendedores', 'permission' => 'reports.view'],
        'report_operators' => ['group' => 'Reportes', 'label' => 'Operadores', 'permission' => 'reports.view'],
        'report_branches' => ['group' => 'Reportes', 'label' => 'Sucursales', 'permission' => 'reports.view'],
        'report_products' => ['group' => 'Reportes', 'label' => 'Productos', 'permission' => 'reports.view'],
        'report_conciliation' => ['group' => 'Reportes', 'label' => 'Conciliación', 'permission' => 'reports.view'],
        'imports' => ['group' => 'Datos', 'label' => 'Importaciones', 'permission' => 'imports.manage'],
        'companies' => ['group' => 'Catálogos', 'label' => 'Compañías', 'permission' => 'companies.manage'],
        'branches' => ['group' => 'Catálogos', 'label' => 'Sucursales', 'permission' => 'branches.manage'],
        'users' => ['group' => 'Administración', 'label' => 'Usuarios', 'permission' => 'users.manage'],
        'roles' => ['group' => 'Administración', 'label' => 'Roles y permisos', 'permission' => 'roles.manage'],
        'automations' => ['group' => 'Administración', 'label' => 'Automatizaciones', 'permission' => 'automations.manage'],
        'mail' => ['group' => 'Administración', 'label' => 'Correo', 'permission' => 'mail.manage'],
        'whatsapp' => ['group' => 'Administración', 'label' => 'WhatsApp', 'permission' => 'whatsapp.manage'],
        'webhook' => ['group' => 'Administración', 'label' => 'Webhook', 'permission' => 'webhooks.manage'],
        'audit' => ['group' => 'Administración', 'label' => 'Auditoría', 'permission' => 'audit.view'],
    ];

    private const REPORTS = ['report_sellers', 'report_operators', 'report_branches', 'report_products', 'report_conciliation'];

    /**
     * Ruta (prefijo por segmentos, relativo a `api/v1`) → opciones que la usan. Gana el prefijo más largo.
     *
     * @var array<string, list<string>>
     */
    public const CLAIMS = [
        'sales/summary' => ['sales'], 'sales/breakdown' => ['sales'], 'sales/rankings' => ['sales'], 'sales/export' => ['sales'],
        'sales/goals' => ['goals'],
        'sales/products' => ['products'],

        // Clientes: la ficha reúne crédito, cotizaciones, alertas, WhatsApp e interacciones del cliente, y comparte listas con otras pantallas.
        'customers' => ['customers', 'quotations'],
        'customers/profile' => ['customers'], 'customers/purchases' => ['customers'], 'customers/document' => ['customers'], 'customers/data' => ['customers'],
        'customers/assignment' => ['customers'], 'customers/type' => ['customers'], 'customers/types' => ['customers'], 'customers/activity-rules' => ['customers'],
        'customers/contact-preference' => ['customers'], 'customers/operators' => ['customers'], 'customers/seller-users' => ['customers'], 'customers/supervisors' => ['customers'],
        'customers/sales-report' => ['customers'],
        'customers/sellers' => ['customers', 'agenda', 'follow_up', 'insights'],
        'customers/interactions' => ['customers', 'agenda', 'follow_up'],
        'customers/agenda' => ['agenda'],
        'customers/follow-up-kpis' => ['follow_up'],

        'conversations' => ['conversations'],
        'quotations' => ['quotations', 'customers', 'conversations'],

        'credit' => ['credit'],
        'credit/customer' => ['credit', 'customers'], 'credit/score' => ['credit', 'customers'],
        'credit/promises' => ['credit', 'customers', 'agenda'],
        'credit/open-invoices' => ['credit', 'customers'], 'credit/payments' => ['credit', 'customers'], 'credit/credit-notes' => ['credit', 'customers'],

        'digests' => ['digests'],

        'insights' => ['insights'],
        'insights/alerts/customer' => ['insights', 'customers'], 'insights/cross-sell/customer' => ['insights', 'customers'], 'insights/rfm/customer' => ['insights', 'customers'],

        'inventory/stock' => ['stock'], 'inventory/kardex' => ['stock'], 'inventory/shortfalls' => ['stock'], 'inventory/sync-sales' => ['stock'],
        'inventory/rotation' => ['rotation'],
        'inventory/movements' => ['movements'],
        'inventory/transfers' => ['movements', 'stock'],
        'inventory/units' => ['units'],
        'inventory/products' => ['stock', 'movements', 'units'],

        'reports/context' => self::REPORTS,
        'reports/sellers' => ['report_sellers'], 'reports/operators' => ['report_operators'], 'reports/branches' => ['report_branches'],
        'reports/products' => ['report_products'], 'reports/conciliation' => ['report_conciliation'],

        'admin/imports' => ['imports'],
        'admin/companies' => ['companies', 'users'], 'admin/branches' => ['branches', 'users'],
        'admin/users' => ['users'], 'admin/roles' => ['users'], 'admin/role-permissions' => ['roles'],
        'admin/audit-logs' => ['audit'],
        'automations' => ['automations'], 'mail' => ['mail'], 'whatsapp' => ['whatsapp'], 'webhook' => ['webhook'],
    ];

    /**
     * Rutas que ninguna opción del menú posee: son parte de entrar y usar el sistema (sesión, inicio, notificaciones, tiempo real, listas de
     * compañías y sucursales) o ya exigen ser administrador. Nunca se bloquean por esta selección.
     *
     * @var list<string>
     */
    public const EXEMPT = [
        'auth', 'dashboard', 'notifications', 'realtime', 'broadcasting', 'companies', 'branches', 'webhooks',
        'conversations/unread',   // la insignia del menú la pide toda sesión con permiso de conversaciones
        'admin/menu-access',      // solo administradores (el propio servidor lo exige)
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function exists(string $key): bool
    {
        return isset(self::OPTIONS[$key]);
    }

    /**
     * Opciones que usan la ruta; vacío si ninguna la posee (exenta).
     *
     * @return list<string>
     */
    public function owners(string $path): array
    {
        $path = trim($path, '/');
        if ($this->isExempt($path)) {
            return [];
        }

        $best = null;
        foreach (array_keys(self::CLAIMS) as $prefix) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? [] : self::CLAIMS[$best];
    }

    /** ¿La ruta está declarada (con dueño o como excepción)? Lo usa la prueba que vigila las rutas nuevas. */
    public function isKnown(string $path): bool
    {
        $path = trim($path, '/');

        return $this->isExempt($path) || $this->owners($path) !== [];
    }

    private function isExempt(string $path): bool
    {
        foreach (self::EXEMPT as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                // Una excepción más corta no oculta una ruta con dueño más específico (p. ej. `conversations/unread` frente a `conversations`).
                return true;
            }
        }

        return false;
    }
}
