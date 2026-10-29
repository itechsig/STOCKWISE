<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accurate\StockwisePurge;
use App\Support\WindowsEnv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Manual Sync Agent runs from the web (Log Agen page):
 *   snapshot = copy Accurate's .GDB -> validate -> staging (no sync)
 *   full     = the same, then sync to STOCKWISE
 * Starts `python -m agent.run --snapshot|--full` as a detached process —
 * the exact same code path as the scheduled tasks, with all its safeguards
 * (checksum-verified copy, validation, previous staging kept, one job at a
 * time via the Agent's own lock). The web request returns immediately.
 */
class AgentRunController extends Controller
{
    public const MODES = ['snapshot' => '--snapshot', 'full' => '--full'];

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->currentStatus()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', array_keys(self::MODES))]]);

        $status = $this->currentStatus();
        if ($status['running']) {
            return response()->json([
                'message' => 'Sync Agent sedang menjalankan job lain ('.$status['mode'].') — tunggu sampai selesai.',
                'data' => $status,
            ], 409);
        }

        $python = (string) config('stockwise.agent_python');
        $dir = (string) config('stockwise.agent_dir');
        $quote = fn (string $v) => "'".str_replace("'", "''", $v)."'";
        $ps = sprintf(
            'Start-Process -FilePath %s -ArgumentList %s -WorkingDirectory %s -WindowStyle Hidden',
            $quote($python),
            implode(',', array_map($quote, ['-m', 'agent.run', self::MODES[$data['mode']]])),
            $quote($dir),
        );

        $result = Process::env(WindowsEnv::vars())->timeout(30)->run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $ps]);
        if (! $result->successful()) {
            return response()->json(['message' => 'Gagal menjalankan Sync Agent: '.mb_substr(trim($result->errorOutput()), 0, 300)], 500);
        }

        // its sync is a manual one (never removes STOCKWISE test data): taken by the batch it starts
        if ($data['mode'] === 'full') {
            Cache::put(StockwisePurge::MANUAL_AGENT_RUN, $request->user()->id, now()->addHours(2));
        }

        Log::info('[agent-run] manual start', [
            'actor_id' => $request->user()->id,
            'actor' => $request->user()->username,
            'mode' => $data['mode'],
        ]);

        return response()->json([
            'message' => $data['mode'] === 'full'
                ? 'Sync Agent dimulai: salin GDB → validasi → staging → sync ke STOCKWISE.'
                : 'Sync Agent dimulai: salin GDB → validasi → staging (tanpa sync).',
            'data' => ['mode' => $data['mode']],
        ], 202);
    }

    /** agent-status.json (written by sync-service/agent/status.py) + a liveness check of its pid. */
    protected function currentStatus(): array
    {
        $file = rtrim((string) config('stockwise.agent_log_path'), '\\/').DIRECTORY_SEPARATOR.'agent-status.json';
        $status = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];

        $running = (bool) ($status['running'] ?? false);
        if ($running && ! $this->pidAlive((int) ($status['pid'] ?? 0))) {
            $running = false;
            $status['result'] = $status['result'] ?? 'interrupted';
        }

        return [
            'running' => $running,
            'mode' => $status['mode'] ?? null,
            'started_at' => $status['started_at'] ?? null,
            'finished_at' => $status['finished_at'] ?? null,
            'result' => $running ? null : ($status['result'] ?? null),
        ];
    }

    protected function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $r = Process::env(WindowsEnv::vars())->timeout(15)->run(['tasklist', '/FI', "PID eq {$pid}", '/FO', 'CSV', '/NH']);

        return $r->successful() && str_contains(strtolower($r->output()), 'python');
    }
}
