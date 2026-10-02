<?php

namespace App\Domains\Providers\Models;

use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Providers\Contracts\DomainProviderDriver;
use App\Domains\Providers\DomainProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Penyedia (provider) domain/hosting terdaftar di CRM (F4-5).
 *
 * `driver` = kunci driver dari registry (`DomainProviderRegistry`).
 * `credentials` = array JSON terenkripsi; bentuk isinya ditentukan driver
 * (lihat DomainProviderDriver::credentialFields()). Kolom ini TIDAK PERNAH
 * ditampilkan utuh — hanya field non-rahasia yang dirender ulang ke form.
 */
class DomainProvider extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'name', 'driver', 'credentials', 'is_active', 'notes', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function activityLabel(): string
    {
        return "Provider domain \"{$this->name}\"";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Driver terkait provider ini (dibangun dari registry). */
    public function driver(): DomainProviderDriver
    {
        return app(DomainProviderRegistry::class)->make($this);
    }

    /** Nama driver yang ramah dibaca (fallback ke kunci bila tak dikenal). */
    public function driverLabel(): string
    {
        return app(DomainProviderRegistry::class)->label($this->driver);
    }

    /** Field kredensial non-rahasia untuk ditampilkan ulang di form edit. */
    public function safeCredentials(): array
    {
        $class = app(DomainProviderRegistry::class)->resolve($this->driver);
        $credentials = is_array($this->credentials) ? $this->credentials : [];
        $safe = [];

        foreach ($class::credentialFields() as $name => $field) {
            if ($field['secret'] ?? false) {
                continue;
            }

            $safe[$name] = $credentials[$name] ?? ($field['default'] ?? null);
        }

        return $safe;
    }
}
