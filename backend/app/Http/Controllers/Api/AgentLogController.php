<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * Read-only view of the Sync Agent's own log files (sync-service writes
 * them; Laravel only reads). Shows what Sync History (sync_batches) can't:
 * runs that failed before a batch was ever started (restore/copy/gfix/
 * validation/preflight), and snapshot-only runs that never push.
 *
 * sync.log  — one line per run: "<iso-time> backup=<source> status=<S> error=<msg>"
 *             (an error message can continue on following lines)
 * agent.log — "<Y-m-d H:i:s,ms> <LEVEL>: <message>" (+ traceback lines)
 */
class AgentLogController extends Controller
{
    private const MAX_BYTES = 262144;

    public function index()
    {
        $dir = (string) config('stockwise.agent_log_path');

        return response()->json([
            'data' => [
                'runs' => array_reverse($this->parseRuns($this->tail($dir.DIRECTORY_SEPARATOR.'sync.log'), 50)),
                'events' => array_reverse($this->parseEvents($this->tail($dir.DIRECTORY_SEPARATOR.'agent.log'), 100)),
                'log_available' => is_dir($dir),
            ],
        ]);
    }

    /** Last MAX_BYTES of a file as lines (first, possibly partial, line dropped). */
    private function tail(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $offset = max(0, $size - self::MAX_BYTES);
        fseek($fh, $offset);
        $content = stream_get_contents($fh) ?: '';
        fclose($fh);

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        if ($offset > 0) {
            array_shift($lines);
        }

        return $lines;
    }

    private function parseRuns(array $lines, int $limit): array
    {
        $runs = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T[\d:.]+) backup=(.*) status=(\S+) error=(.*)$/', $line, $m)) {
                $runs[] = [
                    'time' => $m[1],
                    'source' => $m[2],
                    'status' => $m[3],
                    'error' => $m[4] === '-' ? null : $m[4],
                ];
            } elseif ($runs !== [] && trim($line) !== '') {
                $last = count($runs) - 1;
                $runs[$last]['error'] = trim(($runs[$last]['error'] ?? '')."\n".$line);
            }
        }
        foreach ($runs as &$run) {
            $run['error'] = $run['error'] === null ? null : $this->redact(mb_substr($run['error'], 0, 2000));
        }

        return array_slice($runs, -$limit);
    }

    private function parseEvents(array $lines, int $limit): array
    {
        $events = [];
        foreach ($lines as $line) {
            // Traceback / continuation lines are dropped — the first line of
            // each event carries the message; details stay in the file.
            if (preg_match('/^(\d{4}-\d{2}-\d{2} [\d:]{8}),\d+ (INFO|WARNING|ERROR|CRITICAL|DEBUG): (.*)$/', $line, $m)) {
                $events[] = [
                    'time' => str_replace(' ', 'T', $m[1]),
                    'level' => $m[2],
                    'message' => $this->redact(mb_substr($m[3], 0, 1000)),
                ];
            }
        }

        return array_slice($events, -$limit);
    }

    /** The Agent never logs secrets, but never trust that blindly on a page other users can see. */
    private function redact(string $text): string
    {
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._|~+\/=-]+/i', '$1***', $text) ?? $text;

        return preg_replace('/((?:password|passwd|pass|token|secret|api[_-]?key)["\']?\s*[=:]\s*["\']?)[^\s"\',;]+/i', '$1***', $text) ?? $text;
    }
}
