<?php

namespace App\Domains\Access\Models;

use App\Domains\Access\Enums\AccessLevel;
use App\Domains\Access\Enums\Module;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'label', 'description', 'permissions', 'is_admin',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_admin' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Level hak akses role untuk sebuah modul, atau null bila tidak diberi akses.
     */
    public function levelFor(Module|string $module): ?AccessLevel
    {
        $key = $module instanceof Module ? $module->value : $module;
        $raw = $this->permissions[$key] ?? null;

        return is_string($raw) ? AccessLevel::tryFrom($raw) : null;
    }

    /**
     * Apakah role punya hak untuk (module, action). Action non-view
     * (create/update/delete) butuh level Manage.
     */
    public function allows(string $module, string $action = 'view'): bool
    {
        if ($this->is_admin) {
            return true;
        }

        $level = $this->levelFor($module);

        if ($level === null) {
            return false;
        }

        return $action === 'view' || $level === AccessLevel::Manage;
    }

    /**
     * Skor hak akses role untuk membandingkan "lebih tinggi" / "lebih rendah".
     * manage = 2, lihat = 1; role administrator = tak terbatas (PHP_INT_MAX).
     * Dipakai guard anti-eskalasi saat user non-admin assigns role.
     */
    public function privilegeRank(): int
    {
        if ($this->is_admin) {
            return PHP_INT_MAX;
        }

        $rank = 0;

        foreach ($this->permissions ?? [] as $level) {
            $rank += match ($level) {
                AccessLevel::Manage->value => 2,
                AccessLevel::View->value => 1,
                default => 0,
            };
        }

        return $rank;
    }

    /** Apakah role punya akses (minimal lihat) ke modul. */
    public function grantsModule(Module|string $module): bool
    {
        $key = $module instanceof Module ? $module->value : $module;

        return $this->is_admin || $this->levelFor($key) !== null;
    }

    /**
     * Peta modul → level yang dinormalisasi untuk form (hanya modul valid).
     *
     * @return array<string, string>
     */
    public function permissionMap(): array
    {
        $map = [];

        foreach (Module::cases() as $module) {
            $level = $this->levelFor($module);

            if ($level !== null) {
                $map[$module->value] = $level->value;
            }
        }

        return $map;
    }
}
