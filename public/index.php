<?php

declare(strict_types=1);

use App\Config\Env;
use App\Controllers\AdminClientController;
use App\Controllers\AdminMarketController;
use App\Controllers\AdminQrCodeController;
use App\Controllers\ArchiveController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\CategoryTreeController;
use App\Controllers\DashboardController;
use App\Controllers\EventController;
use App\Controllers\FeedbackController;
use App\Controllers\LanguageController;
use App\Controllers\MarketController;
use App\Controllers\MenuItemController;
use App\Controllers\QrCodeController;
use App\Controllers\TranslateController;
use App\Core\ApiException;
use App\Core\AuthMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$basePath = dirname(__DIR__);

// PHP's built-in dev server (php -S) always runs this router script unless it
// returns false, so real static files (e.g. uploaded images) must be served
// directly here. Apache in production already does this via .htaccess
// (RewriteCond %{REQUEST_FILENAME} !-f), so this only matters for local dev.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($requestedFile !== __DIR__ . '/index.php' && is_file($requestedFile)) {
        return false;
    }
}

spl_autoload_register(function (string $class) use ($basePath): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $file = $basePath . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load($basePath . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

$debug = Env::get('APP_DEBUG', 'false') === 'true';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

// Mobile-only API for now; kept permissive but explicit rather than "*" so it
// is easy to lock down to known origins if a web client is added later.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

// --- Client-scoped auth ---
$router->post('/api/v1/client/auth/login', [AuthController::class, 'clientLogin']);
$router->post('/api/v1/client/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/v1/client/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::role('client')]);
$router->get('/api/v1/client/auth/me', [AuthController::class, 'me'], [AuthMiddleware::role('client')]);

// --- Admin-scoped auth ---
$router->post('/api/v1/admin/auth/login', [AuthController::class, 'adminLogin']);
$router->post('/api/v1/admin/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/v1/admin/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::role('super_admin')]);
$router->get('/api/v1/admin/auth/me', [AuthController::class, 'me'], [AuthMiddleware::role('super_admin')]);

// --- Client-scoped menu management ---
$clientOnly = [AuthMiddleware::role('client')];
$router->get('/api/v1/client/dashboard/stats', [DashboardController::class, 'clientStats'], $clientOnly);
$router->get('/api/v1/client/dashboard/access-series', [DashboardController::class, 'accessSeries'], $clientOnly);
$router->get('/api/v1/client/dashboard/logins-series', [DashboardController::class, 'loginsSeries'], $clientOnly);
$router->get('/api/v1/client/dashboard/ratings-distribution', [DashboardController::class, 'ratingsDistribution'], $clientOnly);
$router->get('/api/v1/client/dashboard/browser-stats', [DashboardController::class, 'browserStats'], $clientOnly);
$router->get('/api/v1/client/categories', [CategoryController::class, 'index'], $clientOnly);
$router->post('/api/v1/client/categories', [CategoryController::class, 'store'], $clientOnly);
$router->patch('/api/v1/client/categories/reorder', [CategoryController::class, 'reorder'], $clientOnly);

// Sub-category tree (categorie_sub_sub / categorie_sub levels above `categorie`)
// — registered before the generic /categories/{id} so literal segments below
// ("tree", "tree/children") aren't swallowed by the {id} pattern.
$router->get('/api/v1/client/categories/tree', [CategoryTreeController::class, 'roots'], $clientOnly);
$router->get('/api/v1/client/categories/tree/children', [CategoryTreeController::class, 'children'], $clientOnly);
$router->post('/api/v1/client/categories/tree', [CategoryTreeController::class, 'store'], $clientOnly);
$router->put('/api/v1/client/categories/tree/{level}/{id}', [CategoryTreeController::class, 'update'], $clientOnly);
$router->delete('/api/v1/client/categories/tree/{level}/{id}', [CategoryTreeController::class, 'destroy'], $clientOnly);
$router->post('/api/v1/client/categories/tree/{level}/{id}/image', [CategoryTreeController::class, 'uploadImage'], $clientOnly);
$router->patch('/api/v1/client/categories/tree/{level}/reorder', [CategoryTreeController::class, 'reorder'], $clientOnly);

$router->get('/api/v1/client/categories/{id}', [CategoryController::class, 'show'], $clientOnly);
$router->put('/api/v1/client/categories/{id}', [CategoryController::class, 'update'], $clientOnly);
$router->delete('/api/v1/client/categories/{id}', [CategoryController::class, 'destroy'], $clientOnly);
$router->post('/api/v1/client/categories/{id}/image', [CategoryController::class, 'uploadImage'], $clientOnly);
$router->get('/api/v1/client/categories/{id}/translations', [CategoryController::class, 'translations'], $clientOnly);
$router->put('/api/v1/client/categories/{id}/translations/{langueId}', [CategoryController::class, 'updateTranslation'], $clientOnly);
$router->delete('/api/v1/client/categories/{id}/translations/{langueId}', [CategoryController::class, 'destroyTranslation'], $clientOnly);
$router->get('/api/v1/client/categories/tree/{level}/{id}/translations', [CategoryTreeController::class, 'translations'], $clientOnly);
$router->put('/api/v1/client/categories/tree/{level}/{id}/translations/{langueId}', [CategoryTreeController::class, 'updateTranslation'], $clientOnly);
$router->delete('/api/v1/client/categories/tree/{level}/{id}/translations/{langueId}', [CategoryTreeController::class, 'destroyTranslation'], $clientOnly);

$router->get('/api/v1/client/menu-items', [MenuItemController::class, 'index'], $clientOnly);
$router->post('/api/v1/client/menu-items', [MenuItemController::class, 'store'], $clientOnly);
$router->get('/api/v1/client/menu-items/{id}', [MenuItemController::class, 'show'], $clientOnly);
$router->put('/api/v1/client/menu-items/{id}', [MenuItemController::class, 'update'], $clientOnly);
$router->delete('/api/v1/client/menu-items/{id}', [MenuItemController::class, 'destroy'], $clientOnly);
$router->post('/api/v1/client/menu-items/{id}/image', [MenuItemController::class, 'uploadImage'], $clientOnly);
$router->get('/api/v1/client/menu-items/{id}/translations', [MenuItemController::class, 'translations'], $clientOnly);
$router->put('/api/v1/client/menu-items/{id}/translations/{langueId}', [MenuItemController::class, 'updateTranslation'], $clientOnly);
$router->delete('/api/v1/client/menu-items/{id}/translations/{langueId}', [MenuItemController::class, 'destroyTranslation'], $clientOnly);

$router->get('/api/v1/client/markets/{marketId}/languages', [LanguageController::class, 'forMarket'], $clientOnly);
$router->put('/api/v1/client/markets/{marketId}/languages/{langueId}', [LanguageController::class, 'setEnabled'], $clientOnly);

$router->post('/api/v1/client/translate', [TranslateController::class, 'translate'], $clientOnly);

$router->get('/api/v1/client/archive', [ArchiveController::class, 'index'], $clientOnly);
$router->post('/api/v1/client/archive/restore', [ArchiveController::class, 'restore'], $clientOnly);

$router->get('/api/v1/client/markets/{marketId}/feedback', [FeedbackController::class, 'forMarket'], $clientOnly);

$router->get('/api/v1/client/events', [EventController::class, 'index'], $clientOnly);
$router->post('/api/v1/client/events', [EventController::class, 'store'], $clientOnly);
$router->put('/api/v1/client/events/{id}', [EventController::class, 'update'], $clientOnly);
$router->delete('/api/v1/client/events/{id}', [EventController::class, 'destroy'], $clientOnly);
$router->post('/api/v1/client/events/{id}/image', [EventController::class, 'uploadImage'], $clientOnly);

$router->get('/api/v1/client/qr-codes', [QrCodeController::class, 'index'], $clientOnly);
$router->post('/api/v1/client/qr-codes', [QrCodeController::class, 'store'], $clientOnly);
$router->get('/api/v1/client/qr-codes/custom', [QrCodeController::class, 'customStyle'], $clientOnly);
$router->put('/api/v1/client/qr-codes/custom', [QrCodeController::class, 'updateCustomStyle'], $clientOnly);
$router->get('/api/v1/client/qr-codes/{id}', [QrCodeController::class, 'show'], $clientOnly);
$router->put('/api/v1/client/qr-codes/{id}', [QrCodeController::class, 'update'], $clientOnly);
$router->delete('/api/v1/client/qr-codes/{id}', [QrCodeController::class, 'destroy'], $clientOnly);

$router->get('/api/v1/client/markets', [MarketController::class, 'index'], $clientOnly);
$router->get('/api/v1/client/markets/{id}', [MarketController::class, 'show'], $clientOnly);
$router->put('/api/v1/client/markets/{id}', [MarketController::class, 'update'], $clientOnly);
$router->get('/api/v1/client/markets/{id}/menu-qrcode', [MarketController::class, 'menuQrCode'], $clientOnly);
$router->get('/api/v1/client/markets/{id}/menu-qrcode/image', [MarketController::class, 'menuQrCodeImage'], $clientOnly);

// --- Admin-scoped platform management ---
$adminOnly = [AuthMiddleware::role('super_admin')];
$router->get('/api/v1/admin/dashboard/stats', [DashboardController::class, 'adminStats'], $adminOnly);

$router->get('/api/v1/admin/clients', [AdminClientController::class, 'index'], $adminOnly);
$router->post('/api/v1/admin/clients', [AdminClientController::class, 'store'], $adminOnly);
$router->get('/api/v1/admin/clients/{id}', [AdminClientController::class, 'show'], $adminOnly);
$router->put('/api/v1/admin/clients/{id}', [AdminClientController::class, 'update'], $adminOnly);
$router->delete('/api/v1/admin/clients/{id}', [AdminClientController::class, 'destroy'], $adminOnly);
$router->post('/api/v1/admin/clients/{id}/reactivate', [AdminClientController::class, 'reactivate'], $adminOnly);

$router->get('/api/v1/admin/markets', [AdminMarketController::class, 'index'], $adminOnly);
$router->get('/api/v1/admin/markets/{id}', [AdminMarketController::class, 'show'], $adminOnly);

$router->get('/api/v1/admin/qr-codes', [AdminQrCodeController::class, 'index'], $adminOnly);

try {
    $request = new Request();
    $router->dispatch($request);
} catch (ApiException $e) {
    Response::error($e->errorCode(), $e->getMessage(), $e->statusCode(), $e->fields());
} catch (\Throwable $e) {
    if ($debug) {
        // File/line included only in debug mode — needed once to track down
        // a live-vs-local discrepancy (a ParseError whose message alone
        // didn't say which deployed file it came from).
        Response::error('SERVER_ERROR', $e->getMessage(), 500, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } else {
        error_log($e->getMessage());
        Response::error('SERVER_ERROR', 'An unexpected error occurred', 500);
    }
}
