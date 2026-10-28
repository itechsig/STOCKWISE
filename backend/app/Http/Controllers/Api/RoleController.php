<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Role management (Pengaturan → Role & Hak Akses).
 *
 * - super_admin is locked: its permissions come from being super_admin
 *   (HasRolesAndPermissions::hasPermission), so it is never edited or deleted.
 * - System roles (is_system) can be renamed and have their permissions
 *   changed, but not deleted, and their slug never changes. NOTE: re-running
 *   RbacSeeder resets system roles' permissions to the defaults in Rbac.php.
 * - Custom roles are fully editable; deleting one requires that no user holds it.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,slug')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->row($role));

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'slug')],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'slug')],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name'], '_');
        abort_if($slug === '' || Role::where('slug', $slug)->exists(), 422, 'Kode role sudah dipakai — isi kode role yang lain.');

        $role = DB::transaction(function () use ($data, $slug) {
            $role = Role::create([
                'slug' => $slug,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));

            return $role;
        });

        $this->audit($request, 'role.create', $role, $data['permissions']);

        return response()->json(['data' => $this->row($role->loadCount('users')->load('permissions:id,slug'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_if($role->slug === UserController::SUPER_ADMIN, 422, 'Role Super Admin terkunci dan tidak bisa diubah.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'slug')],
        ]);

        DB::transaction(function () use ($role, $data) {
            $role->fill(collect($data)->only(['name', 'description'])->all())->save();
            if (array_key_exists('permissions', $data)) {
                $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));
            }
        });

        $this->audit($request, 'role.update', $role, $data['permissions'] ?? null);

        return response()->json(['data' => $this->row($role->fresh()->loadCount('users')->load('permissions:id,slug'))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_if($role->is_system, 422, 'Role bawaan sistem tidak bisa dihapus.');
        $users = $role->users()->count();
        abort_if($users > 0, 409, "Role ini masih dipakai {$users} pengguna. Pindahkan pengguna tersebut ke role lain dulu.");

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        $this->audit($request, 'role.delete', $role);

        return response()->json(['message' => 'Role dihapus.']);
    }

    private function row(Role $role): array
    {
        return [
            'id' => $role->id,
            'slug' => $role->slug,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'is_locked' => $role->slug === UserController::SUPER_ADMIN,
            'users_count' => $role->users_count,
            'permissions' => $role->permissions->pluck('slug'),
        ];
    }

    private function audit(Request $request, string $action, Role $role, ?array $permissions = null): void
    {
        Log::info('[admin] '.$action, array_filter([
            'actor_id' => $request->user()->id,
            'actor' => $request->user()->username,
            'role' => $role->slug,
            'permissions_count' => $permissions === null ? null : count($permissions),
        ], fn ($v) => $v !== null));
    }
}
