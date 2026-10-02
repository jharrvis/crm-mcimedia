<?php

namespace App\Domains\Clients\Models;

use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Projects\Models\Project;
use App\Domains\Security\Models\SecurityAction;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Services\Models\Service;
use App\Domains\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'name', 'contact_name', 'email', 'whatsapp', 'address', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function securityIncidents(): HasMany
    {
        return $this->hasMany(SecurityIncident::class);
    }

    public function securityActions(): HasMany
    {
        return $this->hasMany(SecurityAction::class);
    }

    public function securityReports(): HasMany
    {
        return $this->hasMany(SecurityReport::class);
    }

    /** Tautan laporan keamanan publik aktif (F3-3). */
    public function hasSecurityPortal(): bool
    {
        return filled($this->security_portal_token);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function activityLabel(): string
    {
        return "klien {$this->name}";
    }
}
