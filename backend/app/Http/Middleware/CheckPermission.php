<?php

namespace App\Http\Middleware;

use App\Support\Rbac\Rbac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `permission:slug` or `permission:slug1|slug2` (any-of).
 * Backend is the authority — never trust the client (brief §AC).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $slugs = explode('|', $permissions);

        foreach ($slugs as $slug) {
            if (! in_array($slug, Rbac::allPermissionSlugs(), true)) {
                abort(500, "Unknown permission slug: {$slug}");
            }
        }

        if (! $user->hasAnyPermission(...$slugs)) {
            abort(403, 'This action is unauthorized.');
        }

        return $next($request);
    }
}
