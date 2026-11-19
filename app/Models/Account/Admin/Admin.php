<?php

namespace App\Models\Account\Admin;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable implements CanResetPassword
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'administrators';

    protected $fillable = [
        'username',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $with = ['role'];
    protected $appends = ['permissions'];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function getPermissionsAttribute(): array
    {
        return config('admin_roles.' . $this->role?->code . '.permissions', []);
    }

    public function hasAccess(string $permission): bool
    {
        return $this->is_active && in_array($permission, $this->permissions, true);
    }

    public $timestamps = false;
}
