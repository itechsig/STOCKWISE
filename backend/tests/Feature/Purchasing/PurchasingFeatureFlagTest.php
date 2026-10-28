<?php

namespace Tests\Feature\Purchasing;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bukti Keluar Barang is not activated yet (user decision 2026-09-29). */
class PurchasingFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_goods_issue_endpoints_are_blocked_when_the_flag_is_off(): void
    {
        config(['stockwise.features.goods_issue_enabled' => false]);
        $this->actingAsRole('super_admin');

        $res = $this->getJson('/api/goods-issues')->assertForbidden();
        $this->assertStringContainsString('belum diaktifkan', $res->json('message'));
        $this->postJson('/api/goods-issues', [])->assertForbidden();
    }

    public function test_accurate_purchasing_mirrors_stay_available(): void
    {
        $this->actingAsRole('super_admin');

        // PPB / PO / RI mirror data from Accurate has no feature flag.
        $this->getJson('/api/ppb')->assertOk();
        $this->getJson('/api/ri')->assertOk();
    }
}
