<?php

namespace App\Console\Commands\Accurate;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('stockwise:agent-token {--revoke : Revoke all existing sync-agent tokens instead of issuing a new one}')]
#[Description('Issue (or revoke) the Sanctum API token the office Sync Agent uses to push Accurate data to POST /api/agent/sync/*. Printed once — store it only in sync-service/.env (API_TOKEN), never in code.')]
class AgentTokenCommand extends Command
{
    protected const SYSTEM_USERNAME = 'sync-agent@system';

    protected const ABILITY = 'agent:sync';

    public function handle(): int
    {
        $user = User::firstOrCreate(
            ['username' => self::SYSTEM_USERNAME],
            [
                'name' => 'Sync Agent (system)',
                'email' => null,
                'password' => Hash::make(Str::random(40)), // never used to log in — token-only account
                'is_active' => true,
            ]
        );

        if ($this->option('revoke')) {
            $count = $user->tokens()->delete();
            $this->info("Revoked {$count} sync-agent token(s).");

            return self::SUCCESS;
        }

        $user->tokens()->delete(); // one live token at a time — old ones stop working the moment a new one is issued

        $token = $user->createToken('sync-agent', [self::ABILITY])->plainTextToken;

        $this->newLine();
        $this->info('Sync Agent token (copy into sync-service/.env as API_TOKEN — shown only once):');
        $this->line($token);
        $this->newLine();
        $this->warn('This account has no RBAC role/permission and cannot use any human-facing endpoint — it only carries the "agent:sync" ability for POST /api/agent/sync/*.');

        return self::SUCCESS;
    }
}
