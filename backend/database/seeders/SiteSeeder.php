<?php

namespace Database\Seeders;

use App\Models\Site;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        // NC-5: SDA mengelola inventory penuh; BPN hanya membuat Material Request dulu.
        $sites = [
            ['code' => 'SIG-SDA', 'name' => 'SIG Sidoarjo', 'is_inventory_managed' => true],
            ['code' => 'SIG-BPN', 'name' => 'SIG Balikpapan', 'is_inventory_managed' => false],
        ];

        foreach ($sites as $site) {
            Site::updateOrCreate(['code' => $site['code']], $site + ['is_active' => true]);
        }
    }
}
