<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_ping_returns_ok_and_database_connected(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson([
                'app' => 'STOCKWISE',
                'status' => 'ok',
                'database' => 'connected',
            ])
            ->assertJsonStructure(['app', 'status', 'time', 'version', 'database']);
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
