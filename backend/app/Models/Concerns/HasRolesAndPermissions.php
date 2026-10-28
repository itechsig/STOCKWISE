<?php

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

trait HasRolesAndPermissions
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** All permission slugs granted through the user's roles (cached per request). */
    public function permissionSlugs(): Collection
    {
        return once(fn () => $this->roles()
            ->with('permissions:id,slug')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values());
    }

    public function hasRole(string ...$slugs): bool
    {
        return $this->roles->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->permissionSlugs()->contains($slug);
    }

    public function hasAnyPermission(string ...$slugs): bool
    {
        return collect($slugs)->contains(fn (string $slug) => $this->hasPermission($slug));
    }
}
