<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Lightweight readiness probe used by the SPA (and monitoring) to confirm
     * the API is up and the database is reachable.
     */
    public function __invoke(): JsonResponse
    {
        $database = 'connected';

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            $database = 'error';
        }

        return response()->json([
            'app' => config('app.name'),
            'status' => 'ok',
            'time' => now()->toIso8601String(),
            'version' => app()->version(),
            'database' => $database,
        ], $database === 'connected' ? 200 : 503);
    }
}
