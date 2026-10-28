<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the (array) cache so per-route rate limiters don't leak across tests.
        Cache::flush();
    }

    /** Create a user with the given role slug and authenticate as them (Sanctum). */
    protected function actingAsRole(string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        Sanctum::actingAs($user);

        return $user;
    }
}
