<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'site' => $this->whenLoaded('site', fn () => [
                'id' => $this->site?->id,
                'code' => $this->site?->code,
                'name' => $this->site?->name,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
            'permissions' => $this->permissionSlugs(),
            'last_login_at' => $this->last_login_at,
        ];
    }
}
