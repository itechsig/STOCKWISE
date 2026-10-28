<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SiteSeeder::class,
            RbacSeeder::class,
            UserSeeder::class,
            TrackingSeeder::class,
        ]);

        // Master data from the company Excel files (departments, employees, units,
        // categories, warehouses, items, safety stock).
        // Skipped automatically when the source folder is absent (e.g. CI/tests).
        if (is_dir(config('stockwise.import_path'))) {
            $this->command->call('stockwise:import');
            $this->command->call('stockwise:analyze');
            // Data contoh end-to-end (butuh master barang hasil import).
            $this->call(DemoSeeder::class);
        } else {
            $this->command->warn('stockwise.import_path tidak ada — lewati import Excel & data demo.');
        }
    }
}
