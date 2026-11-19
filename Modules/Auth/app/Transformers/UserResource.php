<?php

namespace Modules\Auth\app\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'timezone' => $this->timezone ?: 'UTC',
            'avatar' => $this->avatar,
            'isEmailVerified' => $this->email_verified_at ? true : false,
            'accountType' => $this->account_type,
            'is_admin' => (bool) $this->is_admin,
            'isAdmin' => (bool) $this->is_admin,
            'admin_role' => $this->admin_role,
            'adminRole' => $this->admin_role,
            'admin_permissions' => $this->admin_permissions ?? [],
            'adminPermissions' => $this->admin_permissions ?? [],
        ];
    }
}
