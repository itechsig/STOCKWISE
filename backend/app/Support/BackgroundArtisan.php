<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Starts an artisan command as a separate, detached OS process and returns
 * immediately — so long work (a full Accurate sync, ~1 hour before the
 * 2026-09-26 optimisation) never runs inside the single-threaded
 * `php artisan serve` web server, where it froze the whole web app for
 * every user until it finished.
 *
 * Windows: PowerShell Start-Process — the new process does NOT inherit the
 * web server's handles (incl. its listening socket on :8001), so a restart
 * of the STOCKWISE-Laravel service is never blocked by a running sync.
 *
 * Tests and STOCKWISE_SYNC_RUN_INLINE=true run the command inline instead.
 */
class BackgroundArtisan
{
    public static function start(string $command, array $arguments = []): void
    {
        if (app()->runningUnitTests() || config('stockwise.sync_run_inline')) {
            Artisan::call($command, $arguments);

            return;
        }

        $args = array_merge(['artisan', $command], array_map('strval', array_values($arguments)));

        if (PHP_OS_FAMILY === 'Windows') {
            $quote = fn (string $v) => "'".str_replace("'", "''", $v)."'";
            // No -Redirect*: redirection makes .NET start the child with
            // inherited handles; without it Start-Process uses ShellExecute
            // and the child inherits nothing. Errors still reach
            // storage/logs/laravel.log via report() in the command itself.
            $ps = sprintf(
                'Start-Process -FilePath %s -ArgumentList %s -WorkingDirectory %s -WindowStyle Hidden',
                $quote(PHP_BINARY),
                implode(',', array_map($quote, $args)),
                $quote(base_path()),
            );
            $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "'.str_replace('"', '\"', $ps).'"';
        } else {
            $cmd = sprintf(
                'cd %s && nohup %s %s >> %s 2>&1 &',
                escapeshellarg(base_path()),
                escapeshellarg(PHP_BINARY),
                implode(' ', array_map('escapeshellarg', $args)),
                escapeshellarg(storage_path('logs/'.str_replace(':', '-', $command).'.log')),
            );
        }

        // Full Windows environment (see WindowsEnv): under `artisan serve`
        // PowerShell cannot even start without SystemRoot, and the started
        // process inherits this environment too.
        $result = Process::env(WindowsEnv::vars())->timeout(30)->run($cmd);
        if (! $result->successful()) {
            throw new RuntimeException("Could not start background command {$command} (exit {$result->exitCode()}): ".trim($result->errorOutput().' '.$result->output()));
        }

        Log::info('[sync] background command started', ['command' => $command, 'arguments' => $arguments]);
    }
}
