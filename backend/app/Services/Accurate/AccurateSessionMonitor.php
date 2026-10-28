<?php

namespace App\Services\Accurate;

use App\Support\WindowsEnv;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * "Accurate Online": which PCs are connected to Accurate right now, and a
 * manual disconnect. Deliberately standalone — no link to the Sync Agent,
 * the sync, or any Firebird account:
 *
 * - reading uses Windows' own TCP table (resources/scripts/accurate-sessions.ps1):
 *   connections to Firebird's port (3051 = Accurate is actually using the
 *   database) and to the Accurate license service ports (Accurate open);
 * - disconnecting closes ONE TCP connection on port 3051
 *   (resources/scripts/accurate-session-kill.ps1). Firebird rolls back that
 *   client's uncommitted transaction; committed data is untouched.
 *
 * Accurate's own login names are not available: they live in Firebird's
 * MON$ATTACHMENTS, which needs a production Firebird account.
 */
class AccurateSessionMonitor
{
    public const DB_PORT = 3051;

    protected const CACHE_KEY = 'accurate-sessions:snapshot';

    protected const CACHE_SECONDS = 10;

    /** Aggregated per client IP; cached briefly (the probe spawns PowerShell). */
    public function snapshot(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->aggregate($this->probe()));
    }

    /**
     * Terminates the database connection(s) of one client. With
     * $remotePort only that connection, otherwise every database
     * connection from $remoteAddress. Returns one result per connection.
     */
    public function terminate(string $remoteAddress, ?int $remotePort = null): array
    {
        $raw = $this->probe();
        $serverAddresses = collect($raw['connections'] ?? [])->pluck('local_address')->unique()->all();

        if (in_array($remoteAddress, $serverAddresses, true) || str_starts_with($remoteAddress, '127.')) {
            throw new RuntimeException('Koneksi dari server ini sendiri tidak boleh diputus dari sini.');
        }

        $targets = collect($raw['connections'] ?? [])
            ->where('kind', 'database')
            ->where('local_port', self::DB_PORT)
            ->where('ip', $remoteAddress)
            ->when($remotePort !== null, fn ($c) => $c->where('remote_port', $remotePort))
            ->values();

        if ($targets->isEmpty()) {
            throw new RuntimeException('Tidak ada koneksi Accurate aktif dari PC itu (mungkin sudah terputus).');
        }

        $results = $targets->map(fn (array $c) => ['remote_port' => $c['remote_port']] + $this->runScript('accurate-session-kill.ps1', [
            '-LocalAddress', $c['local_address'], '-LocalPort', (string) $c['local_port'],
            '-RemoteAddress', $c['ip'], '-RemotePort', (string) $c['remote_port'],
        ]))->all();

        Cache::forget(self::CACHE_KEY);

        return $results;
    }

    protected function probe(): array
    {
        return $this->runScript('accurate-sessions.ps1', ['-DbPort', (string) self::DB_PORT]);
    }

    protected function runScript(string $script, array $args): array
    {
        $path = resource_path('scripts/'.$script);
        $result = Process::env(WindowsEnv::vars())->timeout(30)->run(array_merge(
            ['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $path],
            $args,
        ));

        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
        $json = $lines ? json_decode(end($lines), true) : null;

        if (! $result->successful() || ! is_array($json)) {
            throw new RuntimeException('Gagal membaca data koneksi Accurate: '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300));
        }

        return $json;
    }

    protected function aggregate(array $raw): array
    {
        $hosts = $raw['hosts'] ?? [];

        $clients = collect($raw['connections'] ?? [])
            ->groupBy('ip')
            ->map(function ($conns, $ip) use ($hosts) {
                $db = $conns->where('kind', 'database');
                $since = $conns->pluck('since')->filter()->sort()->first();

                return [
                    'ip' => $ip,
                    'hostname' => $hosts[$ip]['hostname'] ?? null,
                    'mac' => $hosts[$ip]['mac'] ?? null,
                    'status' => $db->isNotEmpty() ? 'using_database' : 'license_only',
                    'db_connections' => $db->count(),
                    'license_connections' => $conns->where('kind', 'license')->count(),
                    'since' => $since,
                    'connections' => $conns->sortBy('since')->values()->map(fn ($c) => [
                        'kind' => $c['kind'],
                        'local_address' => $c['local_address'],
                        'local_port' => $c['local_port'],
                        'remote_port' => $c['remote_port'],
                        'since' => $c['since'] ?? null,
                    ])->all(),
                ];
            })
            ->sortBy([['db_connections', 'desc'], ['since', 'asc']])
            ->values();

        return [
            'generated_at' => $raw['generated_at'] ?? now()->toIso8601String(),
            'db_port' => $raw['db_port'] ?? self::DB_PORT,
            'summary' => [
                'clients' => $clients->count(),
                'clients_using_database' => $clients->where('status', 'using_database')->count(),
                'db_connections' => $clients->sum('db_connections'),
                'license_connections' => $clients->sum('license_connections'),
            ],
            'clients' => $clients->all(),
        ];
    }
}
