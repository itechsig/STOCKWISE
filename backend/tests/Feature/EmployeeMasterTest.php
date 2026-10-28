<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Data · Data Pekerja: list, search, add, edit, link an account; one name once per divisi. */
class EmployeeMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_add_search_edit_and_link_an_account(): void
    {
        $gudang = Department::create(['name' => 'Gudang']);
        $driver = Department::create(['name' => 'DRIVER']);
        $admin = $this->actingAsRole('super_admin');

        $made = $this->postJson('/api/employees', ['name' => 'Budi Santoso', 'position' => 'Kepala Gudang', 'phone' => '0812', 'department_id' => $gudang->id])
            ->assertCreated()->assertJsonPath('data.department', 'Gudang')->json('data');
        $this->postJson('/api/employees', ['name' => ' budi  santoso ', 'department_id' => $gudang->id])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/employees', ['name' => 'Budi Santoso', 'department_id' => $driver->id])->assertCreated(); // another divisi: another person
        $this->postJson('/api/employees', ['position' => 'x'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->getJson('/api/employees?search=kepala')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/employees?department_id={$driver->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/employees/departments')->assertJsonCount(2, 'data');
        $this->assertContains($admin->id, array_column($this->getJson('/api/employees/users')->assertOk()->json('data'), 'id'));

        $this->putJson("/api/employees/{$made['id']}", ['user_id' => $admin->id, 'is_active' => false])->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)->assertJsonPath('data.is_active', false);
        $other = Employee::where('department_id', $driver->id)->sole();
        $this->putJson("/api/employees/{$other->id}", ['user_id' => $admin->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->getJson('/api/employees?is_active=0')->assertJsonCount(1, 'data');

        // a requester cannot see the roster
        $this->actingAsRole('karyawan');
        $this->getJson('/api/employees')->assertForbidden();
    }
}
