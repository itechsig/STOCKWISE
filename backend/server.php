<?php

// Router for `php artisan serve` (ServeCommand uses base_path('server.php') when it exists) —
// Laravel's own resources/server.php plus one rule: while STOCKWISE is closed to the internet
// (storage/framework/public-access-off, `php artisan stockwise:public off`), a request that came
// through the Cloudflare Tunnel (CF-Ray / CF-Connecting-IP) is never served a static file
// (app.html, assets): it goes to Laravel, where BlockPublicTunnel answers 503.
// Office-network requests are served exactly as before.

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

$viaTunnel = isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
$publicClosed = $viaTunnel && is_file(__DIR__.'/storage/framework/public-access-off');

if (! $publicClosed && $uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
