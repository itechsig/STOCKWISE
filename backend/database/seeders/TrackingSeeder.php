<?php

namespace Database\Seeders;

use App\Models\UsedReturnComponentType;
use App\Models\Workshop;
use Illuminate\Database\Seeder;

/**
 * Master pendukung modul tracking yang punya daftar tetap di docs/erd.md Grup J.
 * Asset / Customer / Project belum diseed di sini — datanya berasal dari file Excel
 * perusahaan (Dropdown Ban Luar / Maintenance, dsb.) dan diimport terpisah.
 */
class TrackingSeeder extends Seeder
{
    public function run(): void
    {
        // erd.md: seed 14 component types Pengembalian Bekas.
        $components = [
            'BONIT_BR' => 'Bonit BR', 'PEN_BR' => 'Pen BR', 'PEN_SS' => 'Pen SS',
            'VALVE_BR' => 'Valve BR', 'CYL_CAP' => 'Cyl Cap',
            'MUR_BR' => 'Mur BR', 'MUR_CS' => 'Mur CS', 'MUR_GI' => 'Mur GI', 'MUR_SS' => 'Mur SS',
            'PER_CS' => 'Per CS',
            'BAUT_BR' => 'Baut BR', 'BAUT_CS' => 'Baut CS', 'BAUT_GI' => 'Baut GI', 'BAUT_SS' => 'Baut SS',
        ];
        foreach ($components as $code => $name) {
            UsedReturnComponentType::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        // erd.md: Bengkel — hanya yang eksplisit disebut (SIG internal, CDO).
        foreach (['SIG' => true, 'CDO' => false] as $name => $internal) {
            Workshop::updateOrCreate(['name' => $name], ['is_internal' => $internal, 'is_active' => true]);
        }
    }
}
