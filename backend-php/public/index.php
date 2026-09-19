<?php
declare(strict_types=1);

use App\Config\Env;
use App\Config\Uploads;
use App\Controllers\ActivityController;
use App\Controllers\AppSubAdminController;
use App\Controllers\AuthController;
use App\Controllers\ChamberController;
use App\Controllers\ChamberTempController;
use App\Controllers\CustomerController;
use App\Controllers\CustomerNotesController;
use App\Controllers\CustomerReportController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\InwardController;
use App\Controllers\LeadController;
use App\Controllers\MasterController;
use App\Controllers\OperatorController;
use App\Controllers\OutwardController;
use App\Controllers\PermissionController;
use App\Controllers\TempController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

// Shared hosting: never leak PHP errors to browsers in production
if (strtolower((string) (Env::get('APP_ENV', 'production') ?? 'production')) === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
}

// CORS — FRONTEND_URL (comma-separated) + common local / Vercel / Netlify hosts
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5000',
    'http://localhost:5080',
];
$frontend = Env::get('FRONTEND_URL');
if ($frontend) {
    foreach (explode(',', $frontend) as $u) {
        $u = rtrim(trim($u), '/');
        if ($u === '') {
            continue;
        }
        $allowed[] = $u;
        // www twin
        if (preg_match('#^https?://www\.(.+)$#i', $u, $m)) {
            $allowed[] = preg_replace('#^(https?://)www\.#i', '$1', $u);
        } elseif (preg_match('#^https?://([^/]+)#i', $u, $m)) {
            $scheme = parse_url($u, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($u, PHP_URL_HOST) ?: '';
            if ($host !== '' && !str_starts_with($host, 'www.')) {
                $allowed[] = $scheme . '://www.' . $host;
            }
        }
    }
}

$originOk = false;
if ($origin === '') {
    $originOk = true;
} else {
    $o = rtrim($origin, '/');
    if (in_array($origin, $allowed, true) || in_array($o, $allowed, true)) {
        $originOk = true;
    } elseif (preg_match('#^http://(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+)(:\d+)?$#', $o)) {
        $originOk = true;
    } elseif (preg_match('#^https://([a-z0-9-]+\.)*vercel\.app$#i', $o)) {
        $originOk = true;
    } elseif (preg_match('#^https://([a-z0-9-]+\.)*netlify\.app$#i', $o)) {
        $originOk = true;
    } elseif (preg_match('#^https://([a-z0-9-]+\.)*hostingersite\.com$#i', $o)) {
        $originOk = true;
    }
}

if ($originOk && $origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Vary: Origin');
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$req = Request::fromGlobals();

// Static uploads before API router
if (Uploads::tryServe($req->path)) {
    exit;
}

$router = new Router();

// Health (public)
$router->get('/', [HealthController::class, 'live']);
$router->get('/health', [HealthController::class, 'live']);
$router->get('/api/health', [HealthController::class, 'live']);
$router->get('/api/health/db', [HealthController::class, 'db']);

// Auth
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me'], []); // any authenticated
$router->post('/api/auth/verify-profile-access', [AuthController::class, 'verifySuperAdminProfileAccess'], ['super_admin']);
$router->post('/api/auth/change-password', [AuthController::class, 'changeSuperAdminPassword'], ['super_admin']);
$router->post('/api/auth/push-token', [AuthController::class, 'registerPushToken'], ['sub_admin', 'do_operator']);
$router->delete('/api/auth/push-token', [AuthController::class, 'clearPushToken'], ['sub_admin', 'do_operator']);

// Masters (super_admin + sub_admin)
$masterRoles = ['super_admin', 'sub_admin'];
$router->get('/api/masters/warehouses', [MasterController::class, 'listWarehouses'], $masterRoles);
$router->post('/api/masters/warehouses', [MasterController::class, 'createWarehouse'], $masterRoles);
$router->put('/api/masters/warehouses/{id}', [MasterController::class, 'updateWarehouse'], $masterRoles);
$router->delete('/api/masters/warehouses/{id}', [MasterController::class, 'deleteWarehouse'], $masterRoles);
$router->get('/api/masters/clients', [MasterController::class, 'listClients'], $masterRoles);
$router->post('/api/masters/clients', [MasterController::class, 'createClient'], $masterRoles);
$router->put('/api/masters/clients/{id}', [MasterController::class, 'updateClient'], $masterRoles);
$router->delete('/api/masters/clients/{id}', [MasterController::class, 'deleteClient'], $masterRoles);

// DO operators (super_admin + sub_admin — matches Node mount)
$opRoles = ['super_admin', 'sub_admin'];
$router->get('/api/do-operators', [OperatorController::class, 'getOperators'], $opRoles);
$router->post('/api/do-operators', [OperatorController::class, 'createOperator'], $opRoles);
$router->put('/api/do-operators/{id}', [OperatorController::class, 'updateOperator'], $opRoles);
$router->delete('/api/do-operators/{id}', [OperatorController::class, 'deleteOperator'], $opRoles);

// Portal customers
$router->get('/api/customers', [CustomerController::class, 'list'], $opRoles);
$router->post('/api/customers', [CustomerController::class, 'create'], $opRoles);
$router->put('/api/customers/{id}', [CustomerController::class, 'update'], $opRoles);
$router->delete('/api/customers/{id}', [CustomerController::class, 'delete'], $opRoles);

// Mobile Sub-Admins (super_admin only)
$saOnly = ['super_admin'];
$router->get('/api/sub-admins', [AppSubAdminController::class, 'list'], $saOnly);
$router->post('/api/sub-admins', [AppSubAdminController::class, 'create'], $saOnly);
$router->put('/api/sub-admins/{id}', [AppSubAdminController::class, 'update'], $saOnly);
$router->delete('/api/sub-admins/{id}', [AppSubAdminController::class, 'delete'], $saOnly);

// Chambers (all mobile roles + SA) — static paths before /{id}
$chamberRoles = ['super_admin', 'customer', 'do_operator', 'sub_admin'];
$router->get('/api/chambers', [ChamberController::class, 'getChambers'], $chamberRoles);
$router->post('/api/chambers', [ChamberController::class, 'createChamber'], $chamberRoles);
$router->get('/api/chambers/assignments', [ChamberController::class, 'getAssignments'], $chamberRoles);
$router->post('/api/chambers/assignments', [ChamberController::class, 'addAssignment'], $chamberRoles);
$router->delete('/api/chambers/assignments', [ChamberController::class, 'deleteAssignment'], $chamberRoles);
$router->post('/api/chambers/inspections', [ChamberController::class, 'addInspection'], $chamberRoles);
$router->get('/api/chambers/inspections', [ChamberController::class, 'getInspections'], $chamberRoles);
$router->delete('/api/chambers/inspections/{id}', [ChamberController::class, 'deleteInspection'], $chamberRoles);
$router->put('/api/chambers/{id}', [ChamberController::class, 'updateChamber'], $chamberRoles);
$router->delete('/api/chambers/{id}', [ChamberController::class, 'deleteChamber'], $chamberRoles);

// Chamber temp logs
$router->get('/api/chamber-temp', [ChamberTempController::class, 'getChamberLogs'], $chamberRoles);
$router->post('/api/chamber-temp', [ChamberTempController::class, 'addChamberLog'], $chamberRoles);
$router->put('/api/chamber-temp/{id}', [ChamberTempController::class, 'updateChamberLog'], $chamberRoles);
$router->delete('/api/chamber-temp/{id}', [ChamberTempController::class, 'deleteChamberLog'], $chamberRoles);

// Legacy DO daily temp logs (web)
$router->get('/api/temp-logs', [TempController::class, 'getAllTempLogs'], $chamberRoles);
$router->post('/api/temp-logs', [TempController::class, 'createTempLog'], $chamberRoles);
$router->delete('/api/temp-logs/{id}', [TempController::class, 'deleteTempLog'], $chamberRoles);

// Inward / Outward
$router->get('/api/inward-logs', [InwardController::class, 'getInwardLogs'], $chamberRoles);
$router->post('/api/inward-logs', [InwardController::class, 'addInwardLog'], $chamberRoles);
$router->put('/api/inward-logs/{id}/pod-photo', [InwardController::class, 'updateInwardPodPhoto'], $chamberRoles);
$router->put('/api/inward-logs/{id}', [InwardController::class, 'updateInwardLog'], $chamberRoles);
$router->delete('/api/inward-logs/{id}', [InwardController::class, 'deleteInwardLog'], $chamberRoles);

$router->get('/api/outward-logs', [OutwardController::class, 'getOutwardLogs'], $chamberRoles);
$router->post('/api/outward-logs', [OutwardController::class, 'addOutwardLog'], $chamberRoles);
$router->put('/api/outward-logs/{id}/pod-photo', [OutwardController::class, 'updateOutwardPodPhoto'], $chamberRoles);
$router->put('/api/outward-logs/{id}', [OutwardController::class, 'updateOutwardLog'], $chamberRoles);
$router->delete('/api/outward-logs/{id}', [OutwardController::class, 'deleteOutwardLog'], $chamberRoles);

// Permission requests (auth per-route like Node)
$router->get('/api/permission-requests/config', [PermissionController::class, 'getSystemConfig'], []);
$router->post('/api/permission-requests/config', [PermissionController::class, 'updateSystemConfig'], ['super_admin']);
$router->get('/api/permission-requests/check', [PermissionController::class, 'checkPermission'], []);
$router->get('/api/permission-requests/record-history', [PermissionController::class, 'getRecordPermissionHistory'], ['super_admin', 'customer', 'sub_admin']);
$router->get('/api/permission-requests', [PermissionController::class, 'getPermissionRequests'], []);
$router->post('/api/permission-requests', [PermissionController::class, 'createPermissionRequest'], []);
$router->put('/api/permission-requests/{id}', [PermissionController::class, 'updatePermissionRequestStatus'], ['super_admin', 'sub_admin']);
$router->patch('/api/permission-requests/{id}/complete', [PermissionController::class, 'markPermissionActionComplete'], ['do_operator', 'super_admin']);

// Operator activities
$router->get('/api/operator-activities', [ActivityController::class, 'getActivityLogs'], $chamberRoles);
$router->post('/api/operator-activities', [ActivityController::class, 'createActivityLog'], $chamberRoles);

// Dashboard (Phase 7)
$dashRoles = ['super_admin', 'customer', 'sub_admin', 'do_operator'];
$router->get('/api/dashboard', [DashboardController::class, 'getDashboardStats'], $dashRoles);
$router->get('/api/dashboard/stats', [DashboardController::class, 'getDashboardStats'], $dashRoles);
$router->get('/api/dashboard/access-options', [DashboardController::class, 'getAccessScopeOptions'], $dashRoles);
$router->get('/api/dashboard/inventory-filter-options', [DashboardController::class, 'getInventoryFilterOptions'], $dashRoles);
$router->get('/api/dashboard/inventory-reconciliation', [DashboardController::class, 'getInventoryReconciliation'], $dashRoles);
$router->get('/api/dashboard/daily-inventory-deltas', [DashboardController::class, 'getDailyInventoryDeltas'], $dashRoles);
$router->get('/api/dashboard/client-month-box-sheet', [DashboardController::class, 'getClientMonthBoxSheet'], $dashRoles);
$router->get('/api/dashboard/do-task-overview', [DashboardController::class, 'getDoTaskOverview'], $dashRoles);
$router->get('/api/dashboard/customers', [DashboardController::class, 'getPortalCustomers'], $dashRoles);
$router->get('/api/dashboard/do-operators', [DashboardController::class, 'getDoOperatorsList'], $dashRoles);
$router->get('/api/dashboard/do-operator-io-counts', [DashboardController::class, 'getDoOperatorIoCounts'], $dashRoles);

// Leads
$leadRoles = ['super_admin', 'customer', 'sub_admin'];
$router->get('/api/leads', [LeadController::class, 'getAllLeads'], $leadRoles);
$router->post('/api/leads', [LeadController::class, 'createLead'], $leadRoles);
$router->get('/api/leads/{id}', [LeadController::class, 'getLeadById'], $leadRoles);
$router->put('/api/leads/{id}', [LeadController::class, 'updateLead'], $leadRoles);
$router->delete('/api/leads/{id}', [LeadController::class, 'deleteLead'], $leadRoles);

// Customer reports
$reportRoles = ['customer', 'super_admin'];
$router->get('/api/customer-reports', [CustomerReportController::class, 'getCustomerReports'], $reportRoles);
$router->post('/api/customer-reports', [CustomerReportController::class, 'createCustomerReport'], $reportRoles);
$router->patch('/api/customer-reports/{id}/status', [CustomerReportController::class, 'updateCustomerReportStatus'], $reportRoles);
$router->delete('/api/customer-reports/{id}', [CustomerReportController::class, 'deleteCustomerReport'], $reportRoles);

// Customer admin notes
$notesRoles = ['customer', 'super_admin'];
$router->get('/api/customer-notes/threads', [CustomerNotesController::class, 'listThreads'], $notesRoles);
$router->get('/api/customer-notes', [CustomerNotesController::class, 'listNotes'], $notesRoles);
$router->post('/api/customer-notes', [CustomerNotesController::class, 'createNote'], $notesRoles);
$router->delete('/api/customer-notes/{id}', [CustomerNotesController::class, 'deleteNote'], $notesRoles);

try {
    $router->dispatch($req);
} catch (\Throwable $e) {
    error_log('[php-api] ' . $e->getMessage());
    Response::error('A server error occurred. Please contact support.', 500, [
        'error' => $e->getMessage(),
    ]);
}
