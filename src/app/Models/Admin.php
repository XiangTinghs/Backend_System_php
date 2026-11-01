<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected $fillable = [
        'username',
        'name',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    public function hasPermission(string $permission): bool
    {
        if (! $this->exists || $permission === '' || $permission !== strtolower($permission)) {
            return false;
        }

        return $this->getConnection()->table('admin_roles')
            ->join('roles_permissions', 'admin_roles.role', '=', 'roles_permissions.role')
            ->where('admin_roles.admin_id', $this->getKey())
            ->where('roles_permissions.permission', $permission)
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
