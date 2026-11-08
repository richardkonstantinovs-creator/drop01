<?php
// Front controller: routes public pages, the JSON API (/api/...) and the admin app (/admin) for Apache and the PHP dev server.
define('DROP01', 1);
require __DIR__ . '/app/bootstrap.php';

$path = Http::path();

// PHP built-in dev server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path) && !preg_match('#^/(app|data|vendor)/#', $path)) {
    if (str_ends_with($path, '.php') && $path !== '/install.php' && $path !== '/cron.php') { http_response_code(403); exit; }
    if ($path === '/install.php') { require __DIR__ . '/install.php'; exit; }
    return false;
}

if (!is_installed()) {
    if (str_starts_with($path, '/api/')) Http::error('The store is not installed yet.', 503);
    header('Location: /install.php', true, 302);
    exit;
}

DB::pdo();
$r = new Router();
PublicController::routes($r);
WaitlistApi::routes($r);
AdminApi::routes($r);
ShopApi::routes($r);
CrmApi::routes($r);
DeliveryApi::routes($r);
MarketingApi::routes($r);
OpsApi::routes($r);
InboxApi::routes($r);
MoneyApi::routes($r);
TextsApi::routes($r);

$isApi = str_starts_with($path, '/api/');
if (!$isApi) Http::securityHeaders();
else { header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin'); }

if (!$r->dispatch(Http::method(), $path)) {
    if ($isApi) Http::error('Not found', 404);
    http_response_code(404);
    View::render('page', ['title' => 'Not found', 'body' => '<p>This page does not exist. <a href="/">Go to the homepage</a>.</p>']);
}
