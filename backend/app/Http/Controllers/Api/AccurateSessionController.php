<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accurate\AccurateIpLabels;
use App\Services\Accurate\AccurateSessionMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Accurate Online — see App\Services\Accurate\AccurateSessionMonitor. */
class AccurateSessionController extends Controller
{
    public function index(Request $request, AccurateSessionMonitor $monitor, AccurateIpLabels $labels): JsonResponse
    {
        try {
            $data = $monitor->snapshot($request->boolean('fresh'));
            $all = $labels->all();
            $data['clients'] = array_map(fn (array $c) => $c + [
                'user_name' => $all[$c['ip']]?->user_name ?? null,
                'division' => $all[$c['ip']]?->division ?? null,
                'note' => $all[$c['ip']]?->note ?? null,
            ], $data['clients']);

            return response()->json(['data' => $data]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function terminate(Request $request, AccurateSessionMonitor $monitor): JsonResponse
    {
        $data = $request->validate([
            'remote_address' => ['required', 'ipv4'],
            'remote_port' => ['nullable', 'integer', 'between:1,65535'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $results = $monitor->terminate($data['remote_address'], $data['remote_port'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $ok = collect($results)->where('ok', true)->count();

        Log::warning('[accurate-session] terminate', [
            'actor_id' => $request->user()->id,
            'actor' => $request->user()->username,
            'remote_address' => $data['remote_address'],
            'remote_port' => $data['remote_port'] ?? 'semua',
            'reason' => $data['reason'] ?? null,
            'terminated' => $ok,
            'results' => $results,
        ]);

        return response()->json([
            'message' => $ok > 0
                ? "{$ok} koneksi dari {$data['remote_address']} diputus."
                : 'Tidak ada koneksi yang berhasil diputus.',
            'data' => ['terminated' => $ok, 'results' => $results],
        ], $ok > 0 ? 200 : 422);
    }

    /** Save user name / division / note of an IP (all empty = remove). */
    public function saveLabel(Request $request, AccurateIpLabels $labels): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ipv4'],
            'user_name' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $label = $labels->put($data['ip'], $data, $request->user()->id);

        Log::info('[accurate-session] ip label', [
            'actor' => $request->user()->username,
            'ip' => $data['ip'],
            'user_name' => $label?->user_name,
            'division' => $label?->division,
        ]);

        return response()->json([
            'message' => $label ? "Data {$data['ip']} disimpan." : "Data {$data['ip']} dikosongkan.",
            'data' => $label?->only(['ip', 'user_name', 'division', 'note']),
        ]);
    }
}
