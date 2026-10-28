<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sync Agent ingest routes only accept a token that EXPLICITLY carries the
 * `agent:sync` ability (App\Console\Commands\Accurate\AgentTokenCommand).
 *
 * Sanctum's own `abilities:` check is not enough on its own: a token created
 * without abilities gets ['*'], and '*' satisfies every ability — so any
 * human login token could otherwise push into the accurate_* mirror.
 */
class EnsureAgentToken
{
    public const ABILITY = 'agent:sync';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // can('*') is only true for a wildcard token — the agent token lists
        // agent:sync explicitly and nothing else.
        if (! $token || ! $token->can(self::ABILITY) || $token->can('*')) {
            abort(403, 'This endpoint is reserved for the Sync Agent token.');
        }

        return $next($request);
    }
}
