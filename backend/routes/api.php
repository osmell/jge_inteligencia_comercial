<?php

use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\ImportController;
use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\Sales\SalesStatsController;
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
