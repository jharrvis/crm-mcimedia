<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\Access\Enums\Module;
use App\Domains\Access\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Administrator = role penanda is_admin ATAU akun warisan tanpa role
     * (pra-F4-1). Akun warisan dianggap akses penuh agar sistem lama tetap
     * jalan; beri mereka role lewat menu Pengaturan › Pengguna.
     */
    public function isAdmin(): bool
    {
        $role = $this->role;

        return $role === null || $role->is_admin;
    }

    /**
     * Cek hak akses modul. Action non-view (create/update/delete) butuh
     * level Manage. User tanpa role = akses penuh (lihat isAdmin()).
     */
    public function hasPermission(string $module, string $action = 'view'): bool
    {
        $role = $this->role;

        return $role === null ? true : $role->allows($module, $action);
    }

    /** Cek akses (minimal lihat) ke sebuah modul. */
    public function canAccessModule(Module|string $module): bool
    {
        return $this->hasPermission(
            $module instanceof Module ? $module->value : $module,
            'view',
        );
    }
}
