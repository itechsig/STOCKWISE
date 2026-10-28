<?php

namespace App\Console\Commands;

use App\Http\Middleware\BlockPublicTunnel;
use Illuminate\Console\Command;

/** Open / close STOCKWISE to the internet (Cloudflare Tunnel); the office network always works. */
class PublicAccessCommand extends Command
{
    protected $signature = 'stockwise:public {state? : on | off (empty = show)}';

    protected $description = 'Buka / tutup akses STOCKWISE dari internet (jaringan kantor tetap bisa)';

    public function handle(): int
    {
        $flag = BlockPublicTunnel::flagPath();
        match ($this->argument('state')) {
            'off' => file_put_contents($flag, 'closed '.now()->toDateTimeString()),
            'on' => is_file($flag) ? unlink($flag) : null,
            null => null,
            default => $this->error('Pakai: stockwise:public on|off'),
        };
        $this->info('Akses publik (internet): '.(is_file($flag) ? 'DITUTUP — hanya jaringan kantor' : 'TERBUKA'));

        return self::SUCCESS;
    }
}
