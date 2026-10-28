<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Development accounts (brief §AK). Password: "Password@26" — NOT for production.
 * Login accepts username OR email.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password@26';

    public function run(): void
    {
        $sda = Site::where('code', 'SIG-SDA')->first();
        $bpn = Site::where('code', 'SIG-BPN')->first();

        $accounts = [
            ['username' => 'superadmin', 'email' => 'superadmin@gmail.com', 'name' => 'Super Admin', 'roles' => ['super_admin'], 'site' => $sda],
            ['username' => 'admingudang', 'email' => 'admingudang@gmail.com', 'name' => 'Admin Gudang', 'roles' => ['admin_gudang'], 'site' => $sda],
            ['username' => 'adminlapangan', 'email' => 'adminlapangan@gmail.com', 'name' => 'Admin Lapangan', 'roles' => ['lapangan_gudang'], 'site' => $sda],
            ['username' => 'purchasing', 'email' => 'purchasing@gmail.com', 'name' => 'Purchasing', 'roles' => ['purchasing'], 'site' => $sda],
            ['username' => 'bos', 'email' => 'bos@gmail.com', 'name' => 'BOS / Management', 'roles' => ['bos'], 'site' => $sda],
            ['username' => 'kariawan', 'email' => 'kariawan@gmail.com', 'name' => 'Karyawan', 'roles' => ['karyawan'], 'site' => $sda],
            // Staff PT Surya Inti Gas (2026-09-09) — semua role karyawan.
            ['username' => 'fauzan', 'email' => 'fauzan@gmail.com', 'name' => 'Fauzan', 'phone' => '087715769615', 'position' => 'IT Engineer', 'department' => 'IT', 'roles' => ['karyawan'], 'site' => $sda],
            ['username' => 'rosul', 'email' => 'rosul@gmail.com', 'name' => 'Rosul', 'phone' => '+62 895-3274-08813', 'position' => 'Gudang', 'department' => 'Gudang', 'roles' => ['karyawan'], 'site' => $sda],
            ['username' => 'misse', 'email' => 'misse@gmail.com', 'name' => 'Misse', 'phone' => '+62 858-9520-2576', 'position' => 'Admin Tabung', 'department' => 'Distribusi Tabung', 'roles' => ['karyawan'], 'site' => $sda],
            // extra roles not named by the client, kept for testing
            ['username' => 'anakgudang1', 'email' => 'anakgudang1@gmail.com', 'name' => 'Anak Gudang 1', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'anakgudang2', 'email' => 'anakgudang2@gmail.com', 'name' => 'Anak Gudang 2', 'roles' => ['anak_gudang'], 'site' => $sda],
            ['username' => 'karyawanbpn', 'email' => 'karyawanbpn@gmail.com', 'name' => 'Karyawan Balikpapan', 'roles' => ['karyawan'], 'site' => $bpn],
        ];

        $roles = Role::pluck('id', 'slug');

        foreach ($accounts as $account) {
            $department = isset($account['department'])
                ? Department::firstOrCreate(['name' => $account['department']], ['is_active' => true])
                : null;

            $user = User::updateOrCreate(
                ['username' => $account['username']],
                [
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'phone' => $account['phone'] ?? null,
                    'position' => $account['position'] ?? null,
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => true,
                    'site_id' => $account['site']?->id,
                ],
            );

            $user->roles()->sync(
                collect($account['roles'])->map(fn ($slug) => $roles[$slug])->all()
            );

            if ($department) {
                $employee = Employee::updateOrCreate(
                    ['name_normalized' => Str::upper(trim($account['name'])), 'department_id' => $department->id],
                    [
                        'name' => $account['name'],
                        'phone' => $account['phone'] ?? null,
                        'position' => $account['position'] ?? null,
                        'site_id' => $account['site']?->id,
                        'user_id' => $user->id,
                        'is_active' => true,
                        'needs_review' => false,
                        'source' => 'seeder',
                    ],
                );
                $user->forceFill(['employee_id' => $employee->id])->save();
            }
        }
    }
}
