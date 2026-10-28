<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\Rbac;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions
        $permissionIds = [];
        foreach (Rbac::PERMISSIONS as $group => $slugs) {
            foreach ($slugs as $slug => $name) {
                $permission = Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'group' => $group],
                );
                $permissionIds[$slug] = $permission->id;
            }
        }

        // Prune permissions no longer in the catalog
        Permission::whereNotIn('slug', array_keys($permissionIds))->delete();

        // Roles + matrix
        foreach (Rbac::ROLES as $slug => [$name, $description]) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'is_system' => true],
            );

            $slugsForRole = Rbac::permissionsForRole($slug);
            $ids = array_values(array_intersect_key($permissionIds, array_flip($slugsForRole)));

            $role->permissions()->sync($ids);
        }

        Role::whereNotIn('slug', array_keys(Rbac::ROLES))->where('is_system', true)->delete();
    }
}
