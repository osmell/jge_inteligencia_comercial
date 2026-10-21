<?php

use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\ImportController;
use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationToolsController;
use App\Http\Controllers\Api\V1\CreditInvoiceController;
use App\Http\Controllers\Api\V1\CreditPortfolioController;
use App\Http\Controllers\Api\V1\CreditSettlementController;
use App\Http\Controllers\Api\V1\CustomerAssignmentController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerInteractionController;
use App\Http\Controllers\Api\V1\CustomerSalesReportController;
use App\Http\Controllers\Api\V1\CustomerTypeController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DigestController;
use App\Http\Controllers\Api\V1\InsightAlertController;
use App\Http\Controllers\Api\V1\InsightAnalysisController;
use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\InsightCrossSellController;
use App\Http\Controllers\Api\V1\InsightRfmController;
use App\Http\Controllers\Api\V1\InventoryMovementController;
use App\Http\Controllers\Api\V1\InventoryRotationController;
use App\Http\Controllers\Api\V1\InventoryShortfallController;
use App\Http\Controllers\Api\V1\InventoryStockController;
use App\Http\Controllers\Api\V1\InventoryUnitController;
use App\Http\Controllers\Api\V1\MailController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentPromiseController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\Sales\ProductController;
use App\Http\Controllers\Api\V1\Sales\SalesStatsController;
use App\Http\Controllers\Api\V1\Sales\SellerGoalsController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Cualquier usuario autenticado: el contenido se limita a su ámbito.
    Route::get('companies', [OrganizationController::class, 'companies']);
    Route::get('branches', [OrganizationController::class, 'branches']);

    // Inteligencia de ventas: exige permiso y se limita al ámbito del usuario.
    Route::middleware('permission:sales.view')->prefix('sales')->group(function () {
        Route::get('summary', [SalesStatsController::class, 'summary']);
        Route::get('breakdown', [SalesStatsController::class, 'breakdown']);
        Route::get('rankings', [SalesStatsController::class, 'rankings']);
        Route::get('export', [SalesStatsController::class, 'export']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/levels', [ProductController::class, 'levels']);
        Route::get('products/missing', [ProductController::class, 'missing']);
        Route::get('products/coverage', [ProductController::class, 'coverage']);
        Route::get('goals', [SellerGoalsController::class, 'index']);
        Route::get('goals/export', [SellerGoalsController::class, 'export']);
        Route::get('goals/suggestions', [SellerGoalsController::class, 'suggestions']);
        Route::put('goals', [SellerGoalsController::class, 'update'])->middleware('permission:goals.manage');
    });

    // Ficha de cliente (solo lectura): exige permiso y se limita al ámbito del usuario.
    Route::middleware('permission:customers.view')->prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('profile', [CustomerController::class, 'profile']);
        Route::get('purchases', [CustomerController::class, 'purchases']);
        Route::get('document', [CustomerController::class, 'document']);
        Route::get('types', [CustomerTypeController::class, 'index']);
        Route::get('sales-report', [CustomerSalesReportController::class, 'index']);
        Route::get('sales-report/export', [CustomerSalesReportController::class, 'export']);
        Route::get('sellers', [CustomerAssignmentController::class, 'sellers']);
        Route::get('operators', [CustomerAssignmentController::class, 'operators']);
        Route::get('seller-users', [CustomerAssignmentController::class, 'sellerUsers'])->middleware('permission:customers.manage');
        Route::get('supervisors', [CustomerAssignmentController::class, 'supervisors']);
        Route::get('activity-rules', [CustomerController::class, 'activityRules']);
        Route::get('interactions', [CustomerInteractionController::class, 'index']);
    });

    // Crédito y cobranzas: facturas de crédito con su saldo (calculado desde el libro de movimientos).
    Route::middleware('permission:credit.view')->prefix('credit')->group(function () {
        Route::get('invoices', [CreditInvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [CreditInvoiceController::class, 'show'])->whereNumber('invoice');
        Route::get('aging', [CreditPortfolioController::class, 'aging']);
        Route::get('aging/export', [CreditPortfolioController::class, 'export']);
        Route::get('customer', [CreditPortfolioController::class, 'customer']);
        Route::get('score', [CreditPortfolioController::class, 'score']);
        Route::get('promises', [PaymentPromiseController::class, 'index']);
        Route::post('promises', [PaymentPromiseController::class, 'store'])->middleware('permission:credit.promises');
        Route::post('promises/{promise}/cancel', [PaymentPromiseController::class, 'cancel'])->middleware('permission:credit.promises')->whereNumber('promise');
        Route::get('open-invoices', [CreditSettlementController::class, 'openInvoices']);
        Route::post('payments', [CreditSettlementController::class, 'payment'])->middleware('permission:credit.payments');
        Route::post('credit-notes', [CreditSettlementController::class, 'creditNote'])->middleware('permission:credit.notes');
        Route::post('movements/{movement}/reverse', [CreditSettlementController::class, 'reverse'])->whereNumber('movement');
    });

    // Inventario: unidades, equivalencias por producto y (en pasos siguientes) existencias y movimientos.
    Route::middleware('permission:inventory.view')->prefix('inventory')->group(function () {
        Route::get('stock', [InventoryStockController::class, 'index']);
        Route::get('kardex', [InventoryStockController::class, 'kardex']);
        Route::get('rotation', [InventoryRotationController::class, 'index']);
        Route::get('rotation/export', [InventoryRotationController::class, 'export']);
        Route::get('movements', [InventoryMovementController::class, 'index']);
        Route::get('shortfalls', [InventoryShortfallController::class, 'index']);
        Route::get('units', [InventoryUnitController::class, 'units']);
        Route::get('products', [InventoryUnitController::class, 'products']);
        Route::get('products/{product}/units', [InventoryUnitController::class, 'productUnits'])->whereNumber('product');
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::post('shortfalls/{shortfall}/reconcile', [InventoryShortfallController::class, 'reconcile'])->whereNumber('shortfall');
            Route::post('movements', [InventoryMovementController::class, 'store']);
            Route::post('transfers', [InventoryMovementController::class, 'transfer']);
            Route::post('movements/{movement}/reverse', [InventoryMovementController::class, 'reverse'])->whereNumber('movement');
            Route::post('sync-sales', [InventoryShortfallController::class, 'sync']);
            Route::post('units', [InventoryUnitController::class, 'storeUnit']);
            Route::put('units/{unit}', [InventoryUnitController::class, 'updateUnit'])->whereNumber('unit');
            Route::put('products/{product}/units', [InventoryUnitController::class, 'updateProductUnits'])->whereNumber('product');
        });
    });

    // Dashboard inicial: cada sección exige el permiso de su pantalla de origen (lo valida el servicio).
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/{section}', [DashboardController::class, 'show'])->where('section', '[a-z]+');

    // Resúmenes para gerencia.
    Route::middleware('permission:digests.view')->prefix('digests')->group(function () {
        Route::get('/', [DigestController::class, 'index']);
        Route::post('generate', [DigestController::class, 'generate']);
        Route::get('{digest}', [DigestController::class, 'show'])->whereNumber('digest');
    });

    // Configuración del servidor de correo y registro de envíos (credenciales: solo administración).
    Route::middleware('permission:mail.manage')->prefix('mail')->group(function () {
        Route::get('settings', [MailController::class, 'show']);
        Route::put('settings', [MailController::class, 'update']);
        Route::post('test', [MailController::class, 'test']);
        Route::get('log', [MailController::class, 'log']);
    });

    // Datos públicos de conexión al tiempo real (vacío si no está configurado).
    Route::get('realtime/config', fn () => response()->json(['data' => config('broadcasting.default') === 'reverb' && config('conversations.realtime.key') ? config('conversations.realtime') : null]));

    // Conversaciones de WhatsApp: el alcance de cada persona lo resuelve ConversationAccess.
    Route::middleware('permission:conversations.view')->prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::get('attachments/{attachment}', [ConversationController::class, 'attachment'])->whereNumber('attachment');
        Route::get('{conversation}/messages', [ConversationController::class, 'messages'])->whereNumber('conversation');
        Route::post('{conversation}/read', [ConversationController::class, 'read'])->whereNumber('conversation');
        Route::get('{conversation}/notes', [ConversationController::class, 'notes'])->whereNumber('conversation');
        Route::post('{conversation}/notes', [ConversationController::class, 'storeNote'])->whereNumber('conversation');
        Route::get('templates', [ConversationController::class, 'templates']);
        Route::get('unread', [ConversationController::class, 'unread']);
        Route::get('quick-replies', [ConversationToolsController::class, 'quickReplies']);
        Route::get('{conversation}/quotations', [ConversationToolsController::class, 'quotations'])->whereNumber('conversation');
        Route::get('{conversation}/candidates', [ConversationToolsController::class, 'candidates'])->whereNumber('conversation');
        Route::middleware(['permission:conversations.reply', 'throttle:60,1'])->group(function () {
            Route::post('{conversation}/messages', [ConversationController::class, 'reply'])->whereNumber('conversation');
            Route::post('{conversation}/template', [ConversationController::class, 'sendTemplate'])->whereNumber('conversation');
            Route::post('quick-replies', [ConversationToolsController::class, 'storeQuickReply']);
            Route::put('quick-replies/{reply}', [ConversationToolsController::class, 'updateQuickReply'])->whereNumber('reply');
            Route::post('{conversation}/assign', [ConversationToolsController::class, 'assign'])->whereNumber('conversation');
            Route::put('{conversation}/labels', [ConversationToolsController::class, 'labels'])->whereNumber('conversation');
            Route::post('{conversation}/quotation', [ConversationToolsController::class, 'sendQuotation'])->whereNumber('conversation');
            Route::post('messages/{message}/retry', [ConversationController::class, 'retry'])->whereNumber('message');
        });
    });

    // WhatsApp: cuenta, plantillas y registro (credenciales: solo administración).
    Route::middleware('permission:whatsapp.manage')->prefix('whatsapp')->group(function () {
        Route::get('settings', [WhatsappController::class, 'show']);
        Route::put('settings', [WhatsappController::class, 'update']);
        Route::post('test', [WhatsappController::class, 'test']);
        Route::get('templates', [WhatsappController::class, 'templates']);
        Route::post('templates', [WhatsappController::class, 'storeTemplate']);
        Route::put('templates/{template}', [WhatsappController::class, 'updateTemplate'])->whereNumber('template');
        Route::get('messages', [WhatsappController::class, 'messages']);
    });

    // Webhook de salida (secreto de firma: solo administración).
    Route::middleware('permission:webhooks.manage')->prefix('webhook')->group(function () {
        Route::get('settings', [WebhookController::class, 'show']);
        Route::put('settings', [WebhookController::class, 'update']);
        Route::post('test', [WebhookController::class, 'test']);
        Route::get('log', [WebhookController::class, 'log']);
    });

    // Administración de las automatizaciones (catálogo, parámetros, interruptor general, ejecución manual y registro).
    Route::middleware('permission:automations.manage')->prefix('automations')->group(function () {
        Route::get('/', [AutomationController::class, 'index']);
        Route::put('settings', [AutomationController::class, 'settings']);
        Route::get('runs', [AutomationController::class, 'runs']);
        Route::put('{automation}', [AutomationController::class, 'update']);
        Route::post('{automation}/run', [AutomationController::class, 'run']);
    });

    // Avisos internos de la persona que inició sesión (la campana). No requieren un permiso: cada quien solo ve los suyos.
    Route::prefix('notifications')->group(function () {
        Route::get('summary', [NotificationController::class, 'summary']);
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('seen-all', [NotificationController::class, 'seenAll']);
        Route::post('test', [NotificationController::class, 'test']);
        Route::get('preferences', [NotificationController::class, 'preferences']);
        Route::put('preferences', [NotificationController::class, 'updatePreferences']);
        Route::post('{notification}/seen', [NotificationController::class, 'seen'])->whereNumber('notification');
        Route::post('{notification}/dismiss', [NotificationController::class, 'dismiss'])->whereNumber('notification');
    });

    // Inteligencia comercial avanzada: estado de los análisis, parámetros (solo insights.manage los cambia) y registro de cálculos.
    Route::middleware('permission:insights.view')->prefix('insights')->group(function () {
        Route::get('status', [InsightController::class, 'status']);
        Route::get('parameters', [InsightController::class, 'parameters']);
        Route::get('runs', [InsightController::class, 'runs']);
        Route::get('concentration', [InsightAnalysisController::class, 'concentration']);
        Route::get('cohorts', [InsightAnalysisController::class, 'cohorts']);
        Route::get('seasonality', [InsightAnalysisController::class, 'seasonality']);
        Route::post('{analysis}/recalculate', [InsightAnalysisController::class, 'recalculate'])->whereIn('analysis', ['concentration', 'cohorts', 'seasonality'])->middleware('permission:insights.manage');
        Route::get('alerts', [InsightAlertController::class, 'index']);
        Route::get('alerts/customer', [InsightAlertController::class, 'customer']);
        Route::post('alerts/{alert}/close', [InsightAlertController::class, 'close'])->middleware('permission:interactions.manage')->whereNumber('alert');
        Route::post('alerts/{alert}/follow-up', [InsightAlertController::class, 'followUp'])->middleware('permission:interactions.manage')->whereNumber('alert');
        Route::get('cross-sell', [InsightCrossSellController::class, 'index']);
        Route::get('cross-sell/opportunities', [InsightCrossSellController::class, 'opportunities']);
        Route::get('cross-sell/customer', [InsightCrossSellController::class, 'customer']);
        Route::post('cross-sell/recalculate', [InsightCrossSellController::class, 'recalculate'])->middleware('permission:insights.manage');
        Route::get('rfm', [InsightRfmController::class, 'index']);
        Route::get('rfm/export', [InsightRfmController::class, 'export']);
        Route::get('rfm/customer', [InsightRfmController::class, 'customer']);
        Route::post('rfm/recalculate', [InsightRfmController::class, 'recalculate'])->middleware('permission:insights.manage');
        Route::put('parameters', [InsightController::class, 'updateParameters'])->middleware('permission:insights.manage');
    });

    // Cotizaciones: consulta con permiso de clientes; alta y cambios con el permiso de seguimiento.
    Route::middleware('permission:customers.view')->prefix('quotations')->group(function () {
        Route::get('/', [QuotationController::class, 'index']);
        Route::get('{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation');
        Route::get('{quotation}/attachment', [QuotationController::class, 'download'])->whereNumber('quotation');
        Route::get('{quotation}/documents', [QuotationController::class, 'documents'])->whereNumber('quotation')->middleware('permission:interactions.manage');
        Route::middleware('permission:interactions.manage')->group(function () {
            Route::post('/', [QuotationController::class, 'store']);
            Route::put('{quotation}', [QuotationController::class, 'update'])->whereNumber('quotation');
            Route::put('{quotation}/status', [QuotationController::class, 'status'])->whereNumber('quotation');
            Route::put('{quotation}/link', [QuotationController::class, 'link'])->whereNumber('quotation');
            Route::post('{quotation}/attachment', [QuotationController::class, 'attach'])->whereNumber('quotation');
            Route::delete('{quotation}/attachment', [QuotationController::class, 'detach'])->whereNumber('quotation');
        });
        Route::delete('{quotation}/link', [QuotationController::class, 'unlink'])->whereNumber('quotation')->middleware('permission:customers.manage');
    });

    // Seguimiento comercial: el vendedor y el supervisor registran contactos con sus clientes.
    Route::middleware(['permission:customers.view', 'permission:interactions.manage'])->prefix('customers')->group(function () {
        Route::get('agenda', [CustomerInteractionController::class, 'agenda']);
        Route::get('follow-up-kpis', [CustomerInteractionController::class, 'kpis']);
        Route::post('interactions', [CustomerInteractionController::class, 'store']);
        Route::put('interactions/{interaction}/complete', [CustomerInteractionController::class, 'complete'])->whereNumber('interaction');
        Route::put('interactions/{interaction}', [CustomerInteractionController::class, 'update'])->whereNumber('interaction');
        Route::put('contact-preference', [CustomerInteractionController::class, 'updatePreference']);
        Route::get('{customer}/whatsapp', [WhatsappController::class, 'customer'])->whereNumber('customer');
        Route::post('{customer}/whatsapp/consent', [WhatsappController::class, 'consent'])->whereNumber('customer');
        Route::post('{customer}/whatsapp/send', [WhatsappController::class, 'send'])->whereNumber('customer');
    });

    // Tipos de cliente y su asignación: permiso de gestión de clientes.
    Route::middleware('permission:customers.manage')->group(function () {
        Route::post('customers/types', [CustomerTypeController::class, 'store']);
        Route::put('customers/types/{customerType}', [CustomerTypeController::class, 'update'])->whereNumber('customerType');
        Route::put('customers/type', [CustomerTypeController::class, 'assign']);
        Route::put('customers/data', [CustomerAssignmentController::class, 'updateCustomer']);
        Route::post('customers/sellers', [CustomerAssignmentController::class, 'storeSeller']);
        Route::post('customers/operators', [CustomerAssignmentController::class, 'storeOperator']);
        Route::put('customers/operators/{operator}', [CustomerAssignmentController::class, 'updateOperator'])->whereNumber('operator');
        Route::put('customers/assignment', [CustomerAssignmentController::class, 'assign']);
        Route::put('customers/sellers/{seller}', [CustomerAssignmentController::class, 'updateSeller'])->whereNumber('seller');
        Route::put('customers/activity-rules', [CustomerController::class, 'updateActivityRules']);
    });

    // Rutas administrativas: exigen permiso explícito en el backend.
    Route::prefix('admin')->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        Route::apiResource('companies', CompanyController::class)->except('destroy')->middleware('permission:companies.manage');
        Route::apiResource('branches', BranchController::class)->except('destroy')->middleware('permission:branches.manage');

        Route::middleware('permission:imports.manage')->prefix('imports')->group(function () {
            Route::get('types', [ImportController::class, 'types']);
            Route::get('templates/{type}', [ImportController::class, 'template']);
            Route::get('/', [ImportController::class, 'index']);
            Route::post('/', [ImportController::class, 'store']);
            Route::get('{batch}', [ImportController::class, 'show'])->whereNumber('batch');
            Route::get('{batch}/errors', [ImportController::class, 'errors'])->whereNumber('batch');
            Route::get('{batch}/errors.csv', [ImportController::class, 'errorsCsv'])->whereNumber('batch');
            Route::post('{batch}/confirm', [ImportController::class, 'confirm'])->whereNumber('batch');
            Route::post('{batch}/discard', [ImportController::class, 'discard'])->whereNumber('batch');
        });

        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('role-permissions', [RolePermissionController::class, 'index']);
            Route::put('role-permissions/{role}', [RolePermissionController::class, 'update']);
        });

        Route::middleware('permission:users.manage')->group(function () {
            Route::get('roles', [UserController::class, 'roles']);
            Route::apiResource('users', UserController::class)->except('destroy');
        });
    });
});

// Webhook de Meta (sin sesión): se verifica con el token de verificación y se acepta solo con firma válida.
Route::prefix('webhooks')->middleware('throttle:120,1')->group(function () {
    Route::get('whatsapp', [WhatsappController::class, 'verify']);
    Route::post('whatsapp', [WhatsappController::class, 'webhook']);
});
