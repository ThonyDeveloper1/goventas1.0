<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\MikrotikIspController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PDFController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupervisionController;
use App\Http\Controllers\SupervisionEstadoController;
use App\Http\Controllers\SuspiciousSaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — GO Systems & Technology
|--------------------------------------------------------------------------
*/

// ── Public ──────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

// ── Authenticated ────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);
    Route::put('/me',      [AuthController::class, 'updateProfile']);
    Route::put('/me/dni',  [AuthController::class, 'updateDni']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);

    // ── App-wide config (all authenticated roles) ─────────────────────────
    Route::get('/app-config', [SettingsController::class, 'publicConfig']);

    // ── Dashboard (all authenticated roles) ──────────────────────────────
    Route::get('/dashboard', DashboardController::class);

    // ── Plans (all authenticated — read only; admin for CRUD) ───────────
    Route::get('/plans', [PlanController::class, 'index']);

    // ── RENIEC lookup (all authenticated roles) ──────────────────────────
    // 20 lookups/minute per user. Results cached 1h in ReniecService, so real API hits are far fewer.
    Route::get('/reniec/{dni}', [ClientController::class, 'reniec'])->middleware('throttle:20,1');

    // ── Clients (Admin + Vendedora) ───────────────────────────────────────
    Route::middleware('role:admin,vendedora')->group(function () {
        Route::get('clients/mikrotik-statuses', [ClientController::class, 'mikrotikStatuses']);
        Route::apiResource('clients', ClientController::class)->except(['destroy']);
        Route::delete(
            'clients/{client}/photos/{photo}',
            [ClientController::class, 'destroyPhoto']
        )->name('clients.photos.destroy');
        Route::get('clients/{client}/pagos', [PagoController::class, 'index']);
    });

    // Client delete only for admin
    Route::middleware('role:admin')->group(function () {
        Route::patch('clients/{client}/status', [ClientController::class, 'updateStatus']);
        Route::post('clients/{client}/status', [ClientController::class, 'updateStatus']);
        Route::post('clients/{client}/assign-ip', [ClientController::class, 'assignIp']);
        Route::post('clients/{client}/clear-ip',  [ClientController::class, 'clearIp']);
        Route::get('clients/{client}/ip-history', [ClientController::class, 'ipHistory']);
        Route::delete('clients/{client}', [ClientController::class, 'destroy']);
    });

    // ── Pagos (Admin + Vendedora) ─────────────────────────────────────────
    Route::middleware('role:admin,vendedora')->group(function () {
        Route::get('pagos',       [PagoController::class, 'index']);
        Route::post('pagos',      [PagoController::class, 'store']);
        Route::get('pagos/{id}',  [PagoController::class, 'show']);
    });

    // ── Admin only ────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Settings / API Credentials
        Route::get('settings',              [SettingsController::class, 'index']);
        Route::post('settings',             [SettingsController::class, 'update']);
        Route::post('settings/test-reniec', [SettingsController::class, 'testReniec']);
        Route::delete('settings/reniec-token', [SettingsController::class, 'clearToken']);

        // MikroTik Routers
        Route::get('routers',                [SettingsController::class, 'routers']);
        Route::post('routers',               [SettingsController::class, 'storeRouter']);
        Route::put('routers/{router}',       [SettingsController::class, 'updateRouter']);
        Route::delete('routers/{router}',    [SettingsController::class, 'destroyRouter']);
        Route::post('routers/{router}/test', [SettingsController::class, 'testRouter']);

        // User management
        Route::apiResource('users', \App\Http\Controllers\UserController::class);

        // Plans management
        Route::apiResource('plans', PlanController::class)->except('index');
    });

    // ── Installations (Admin + Supervisor + Vendedora) ───────────────────
    Route::middleware('role:admin,supervisor,vendedora')->group(function () {
        Route::get('installations/availability', [InstallationController::class, 'availability']);
        Route::get('installations/available-slots', [InstallationController::class, 'availableSlots']);
        Route::apiResource('installations', InstallationController::class);
    });

    // ── Supervisions (Admin + Supervisor) ────────────────────────────────
    Route::middleware('role:admin,supervisor')->group(function () {
        Route::get('supervisions/supervisors',            [SupervisionController::class, 'supervisors']);
        Route::get('supervisions/tickets',                [SupervisionController::class, 'tickets']);
        Route::get('supervisions',                        [SupervisionController::class, 'index']);
        Route::get('supervisions/{id}',                   [SupervisionController::class, 'show']);
        Route::post('supervisions/assign',                [SupervisionController::class, 'assign']);
        Route::patch('supervisions/{id}',                 [SupervisionController::class, 'update']);
        Route::post('supervisions/{id}/estado',           [SupervisionController::class, 'setState']);
        Route::post('supervisions/{id}/start',            [SupervisionController::class, 'start']);
        Route::post('supervisions/{id}/complete',         [SupervisionController::class, 'complete']);
        Route::post('supervisions/{id}/photos',           [SupervisionController::class, 'uploadPhotos']);
        Route::delete('supervisions/{id}/photos/{photoId}', [SupervisionController::class, 'destroyPhoto']);
        // Estados: read-only for supervisors
        Route::get('supervisions/estados',                [SupervisionEstadoController::class, 'index']);
    });
    // Estados: full CRUD for admin only
    Route::middleware('role:admin')->group(function () {
        Route::post('supervisions/estados',               [SupervisionEstadoController::class, 'store']);
        Route::put('supervisions/estados/{id}',           [SupervisionEstadoController::class, 'update']);
        Route::delete('supervisions/estados/{id}',        [SupervisionEstadoController::class, 'destroy']);
    });

    // ── Notifications (all authenticated) ──────────────────────────────
    Route::get('notifications',              [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read',   [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all',    [NotificationController::class, 'markAllRead']);

    // ── Suspicious Sales (Admin only) ──────────────────────────────────
    Route::middleware('role:admin')->group(function () {
        Route::get('suspicious-sales',                  [SuspiciousSaleController::class, 'index']);
        Route::get('suspicious-sales/stats',            [SuspiciousSaleController::class, 'stats']);
        Route::post('suspicious-sales/analyze/{client}',[SuspiciousSaleController::class, 'analyze']);
        Route::post('suspicious-sales/{id}/approve',    [SuspiciousSaleController::class, 'approve']);
        Route::post('suspicious-sales/{id}/reject',     [SuspiciousSaleController::class, 'reject']);
        Route::post('suspicious-sales/{id}/unapprove',  [SuspiciousSaleController::class, 'unapprove']);
    });

    // ── Reports (Admin only) ───────────────────────────────────────
    Route::middleware('role:admin')->prefix('reports')->group(function () {
        Route::get('summary',     [ReportController::class, 'summary']);
        Route::get('sales',       [ReportController::class, 'salesReport']);
        Route::get('vendors',     [ReportController::class, 'vendorPerformance']);
        Route::get('clients/pdf', [PDFController::class, 'exportClientsPDF']);
        Route::get('clients',     [ReportController::class, 'clientsByVendor']);
        Route::get('map',         [ReportController::class, 'clientsMap']);
    });

    // ── MikroTik (Admin only for actions, all auth for status) ─────────
    Route::get('mikrotik/network-overview', [MikrotikController::class, 'networkOverview']);
    Route::get('mikrotik/status/{client}',  [MikrotikController::class, 'status']);

    Route::middleware('role:admin')->group(function () {
        Route::post('mikrotik/activate/{client}',  [MikrotikController::class, 'activate']);
        Route::post('mikrotik/suspend/{client}',   [MikrotikController::class, 'suspend']);
        Route::post('mikrotik/provision/{client}',  [MikrotikController::class, 'provision']);
        Route::post('mikrotik/sync-all',            [MikrotikController::class, 'syncAll']);

        // ── MikroTik ISP (multi-router) ──────────────────────────────────
        Route::prefix('mikrotik/isp/{routerId}')->group(function () {
            Route::get('online-users',     [MikrotikIspController::class, 'onlineUsers']);
            Route::post('sync',            [MikrotikIspController::class, 'sync']);
            Route::post('ensure-profiles', [MikrotikIspController::class, 'ensureProfiles']);
            Route::get('corte-moroso',     [MikrotikIspController::class, 'corteMoroso']);
            Route::post('sync-morosos',    [MikrotikIspController::class, 'syncMorosos']);

            Route::get('profiles',                  [MikrotikIspController::class, 'profiles']);
            Route::post('profiles',                 [MikrotikIspController::class, 'createProfile']);
            Route::patch('profiles/{profileId}',    [MikrotikIspController::class, 'updateProfile']);
            Route::delete('profiles/{profileId}',   [MikrotikIspController::class, 'deleteProfile']);

            Route::get('secrets',                   [MikrotikIspController::class, 'secrets']);

            Route::get('pools',                     [MikrotikIspController::class, 'pools']);
            Route::get('pools/availability',        [MikrotikIspController::class, 'poolAvailability']);
        });

        Route::get('mikrotik/morosos-por-vendedora', [MikrotikIspController::class, 'morososPorVendedora']);

        // ── Backup ──────────────────────────────────────────────────────
        Route::prefix('admin/backup')->group(function () {
            Route::get('config',  [BackupController::class, 'getConfig']);
            Route::post('config', [BackupController::class, 'saveConfig']);
            Route::post('run',    [BackupController::class, 'runBackup']);
            Route::get('logs',    [BackupController::class, 'getLogs']);
        });
    });
});
