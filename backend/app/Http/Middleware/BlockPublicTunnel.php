<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes STOCKWISE off the internet while it stays usable on the office network.
 *
 * Public visitors only arrive through the Cloudflare Tunnel, and Cloudflare stamps
 * every such request with CF-Ray / CF-Connecting-IP. While the flag file
 * storage/framework/public-access-off exists, those requests get a 503 page; LAN
 * PCs (http://192.168.2.41:8001) and the local Sync Agent never carry these headers
 * and are untouched.
 *
 *   off:  php artisan stockwise:public off   (or create the flag file)
 *   on:   php artisan stockwise:public on    (or delete it)
 */
class BlockPublicTunnel
{
    public static function flagPath(): string
    {
        return storage_path('framework/public-access-off');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $viaTunnel = $request->headers->has('CF-Ray') || $request->headers->has('CF-Connecting-IP');
        if ($viaTunnel && is_file(self::flagPath())) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'STOCKWISE sedang tidak dapat diakses dari internet. Gunakan jaringan kantor.'], 503);
            }

            return response(<<<'HTML'
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>STOCKWISE</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f4f6;font:15px system-ui,sans-serif;color:#1f2937}
div{max-width:420px;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;text-align:center}h1{font-size:18px;margin:0 0 8px}p{margin:0;color:#6b7280}</style></head>
<body><div><h1>STOCKWISE tidak tersedia dari internet</h1><p>Untuk sementara STOCKWISE hanya dapat diakses dari jaringan kantor.</p></div></body></html>
HTML, 503, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Retry-After' => '3600']);
        }

        return $next($request);
    }
}
