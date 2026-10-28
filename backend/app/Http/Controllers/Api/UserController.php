<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * User management (Pengaturan → Pengguna).
 *
 * Guard rails:
 * - Only a super_admin may grant/revoke super_admin or touch a super_admin account.
 * - Nobody can deactivate/delete themselves, and the last active super_admin
 *   can never be removed, deactivated or demoted.
 * - Deactivation and password resets revoke every token the target holds.
 * - A user with any history (created_by, approved_by, requester_id, ...) is
 *   never hard-deleted — those FKs are nullOnDelete/cascadeOnDelete, so a
 *   delete would erase who did what (or the rows themselves). Deactivate instead.
 */
class UserController extends Controller
{
    public const SUPER_ADMIN = 'super_admin';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with(['roles:id,slug,name', 'site:id,code,name'])->orderBy('name');

        if ($search = $validated['search'] ?? null) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if ($role = $validated['role'] ?? null) {
            $query->whereHas('roles', fn ($q) => $q->where('slug', $role));
        }
        if (isset($validated['is_active'])) {
            $query->where('is_active', $validated['is_active'] === '1');
        }

        $page = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => $this->row($u))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Dropdown data for the user form. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => Role::orderBy('name')->get(['id', 'slug', 'name', 'is_system']),
            'sites' => Site::orderBy('name')->get(['id', 'code', 'name']),
        ]]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->row($user->load(['roles:id,slug,name', 'site:id,code,name']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'password' => $this->passwordRules(),
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'slug')],
        ]);
        $actor = $request->user();
        $this->authorizeRoleChange($actor, [], $data['roles']);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'position' => $data['position'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'password' => $data['password'],
            ]);
            $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));

            return $user;
        });

        $this->audit($actor, 'user.create', $user, ['roles' => $data['roles']]);

        return response()->json(['data' => $this->row($user->load(['roles:id,slug,name', 'site:id,code,name']))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeTouchingSuperAdmin($actor, $user);

        $data = $request->validate($this->rules($user) + [
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'slug')],
        ]);

        $currentRoles = $user->roles()->pluck('slug')->all();
        $newRoles = $data['roles'] ?? $currentRoles;
        $rolesChanged = array_values(array_diff($newRoles, $currentRoles)) !== [] || array_values(array_diff($currentRoles, $newRoles)) !== [];

        if ($rolesChanged) {
            abort_unless($actor->hasPermission('user.assign_role'), 403, 'Anda tidak punya izin mengubah role pengguna.');
            $this->authorizeRoleChange($actor, $currentRoles, $newRoles);
        }

        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'] && $user->is_active;
        if ($deactivating) {
            abort_if($user->is($actor), 422, 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
        }
        if (($deactivating || ($rolesChanged && ! in_array(self::SUPER_ADMIN, $newRoles, true))) && $this->isLastActiveSuperAdmin($user)) {
            abort(422, 'Ini satu-satunya Super Admin aktif — tidak bisa dinonaktifkan atau diturunkan rolenya.');
        }

        DB::transaction(function () use ($user, $data, $newRoles, $rolesChanged, $deactivating) {
            $user->fill(collect($data)->only(['name', 'username', 'email', 'phone', 'position', 'site_id', 'is_active'])->all())->save();
            if ($rolesChanged) {
                $user->roles()->sync(Role::whereIn('slug', $newRoles)->pluck('id'));
            }
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        $this->audit($actor, 'user.update', $user, [
            'fields' => array_keys(collect($data)->except('roles')->all()),
            'roles' => $rolesChanged ? $newRoles : null,
            'deactivated' => $deactivating,
        ]);

        return response()->json(['data' => $this->row($user->fresh(['roles:id,slug,name', 'site:id,code,name']))]);
    }

    /** Admin sets a new password for someone else; all their sessions are logged out. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeTouchingSuperAdmin($actor, $user);

        $data = $request->validate(['password' => $this->passwordRules()]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password']])->save();
            $user->tokens()->delete();
        });

        $this->audit($actor, 'user.reset_password', $user);

        return response()->json(['message' => 'Password berhasil direset. Semua sesi login pengguna ini sudah diakhiri.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeTouchingSuperAdmin($actor, $user);
        abort_if($user->is($actor), 422, 'Anda tidak bisa menghapus akun Anda sendiri.');
        abort_if($this->isLastActiveSuperAdmin($user), 422, 'Ini satu-satunya Super Admin aktif — tidak bisa dihapus.');

        $references = $this->historyReferences($user);
        if ($references > 0) {
            abort(409, "Pengguna ini sudah punya {$references} data riwayat transaksi, jadi tidak bisa dihapus permanen "
                .'(jejak siapa yang membuat/menyetujui data akan hilang). Nonaktifkan saja akunnya.');
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->roles()->detach();
            $user->delete();
        });

        $this->audit($actor, 'user.delete', $user);

        return response()->json(['message' => 'Pengguna dihapus.']);
    }

    // ------------------------------------------------------------------

    private function rules(?User $user = null): array
    {
        $required = $user ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'username' => [$required, 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Shared with AuthController::changePassword(). */
    public static function passwordRules(): array
    {
        return [
            'required', 'string', 'confirmed', Password::min(8)->letters()->numbers(),
            // The seeded default is published in the repo — never allow it again.
            function (string $attribute, mixed $value, \Closure $fail) {
                if (hash_equals(UserSeeder::PASSWORD, (string) $value)) {
                    $fail('Password ini adalah password bawaan sistem dan tidak boleh dipakai.');
                }
            },
        ];
    }

    private function authorizeTouchingSuperAdmin(User $actor, User $target): void
    {
        if ($target->hasRole(self::SUPER_ADMIN) && ! $actor->hasRole(self::SUPER_ADMIN)) {
            abort(403, 'Hanya Super Admin yang bisa mengubah akun Super Admin.');
        }
    }

    private function authorizeRoleChange(User $actor, array $current, array $new): void
    {
        $touchesSuper = in_array(self::SUPER_ADMIN, $current, true) !== in_array(self::SUPER_ADMIN, $new, true);
        if ($touchesSuper && ! $actor->hasRole(self::SUPER_ADMIN)) {
            abort(403, 'Hanya Super Admin yang bisa memberi atau mencabut role Super Admin.');
        }
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole(self::SUPER_ADMIN) || ! $user->is_active) {
            return false;
        }

        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', self::SUPER_ADMIN))
            ->count() <= 1;
    }

    /** Rows in any table that point at this user via a foreign key (role_user excluded). */
    private function historyReferences(User $user): int
    {
        $total = 0;
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, ['users', 'role_user', 'personal_access_tokens', 'sessions', 'password_reset_tokens'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (($fk['foreign_table'] ?? null) !== 'users' || count($fk['columns']) !== 1) {
                    continue;
                }
                $total += DB::table($table)->where($fk['columns'][0], $user->id)->count();
            }
        }

        return $total;
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'email' => $u->email,
            'phone' => $u->phone,
            'position' => $u->position,
            'is_active' => (bool) $u->is_active,
            'site' => $u->site ? ['id' => $u->site->id, 'code' => $u->site->code, 'name' => $u->site->name] : null,
            'roles' => $u->roles->map(fn (Role $r) => ['slug' => $r->slug, 'name' => $r->name])->values(),
            'last_login_at' => $u->last_login_at,
            'created_at' => $u->created_at,
        ];
    }

    private function audit(User $actor, string $action, User $target, array $extra = []): void
    {
        Log::info('[admin] '.$action, array_filter([
            'actor_id' => $actor->id,
            'actor' => $actor->username,
            'target_id' => $target->id,
            'target' => $target->username,
        ] + $extra, fn ($v) => $v !== null));
    }
}
