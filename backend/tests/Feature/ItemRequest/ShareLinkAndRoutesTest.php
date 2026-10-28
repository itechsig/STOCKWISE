<?php

namespace Tests\Feature\ItemRequest;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shared links start with an address others can open; every route names a permission that exists. */
class ShareLinkAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_share_base_is_the_address_the_app_was_opened_with_or_the_configured_one(): void
    {
        $this->actingAsRole('karyawan');
        $this->getJson('http://192.168.2.41:8001/api/share-base')->assertOk()->assertJsonPath('data.base', 'http://192.168.2.41:8001')->assertJsonPath('data.local_only', false);
        config(['stockwise.share_url' => 'http://stockwise.lan/']);
        $this->getJson('http://127.0.0.1:8001/api/share-base')->assertOk()->assertJsonPath('data.base', 'http://stockwise.lan');
    }

    public function test_every_permission_named_by_a_route_exists(): void
    {
        preg_match_all('/permission:([a-z_.|]+)/', file_get_contents(base_path('routes/api.php')), $m);
        $used = array_unique(array_merge(...array_map(fn ($g) => explode('|', $g), $m[1])));
        $this->assertSame([], array_values(array_diff($used, \App\Support\Rbac\Rbac::allPermissionSlugs())));
        // these answered 500 (an unknown permission) before
        $this->actingAsRole('karyawan');
        $this->getJson('/api/units')->assertOk();
    }
}
