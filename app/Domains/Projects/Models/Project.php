<?php

namespace App\Domains\Projects\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'title', 'description', 'deadline', 'status', 'value',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'deadline' => 'date',
            'value' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Project yang masih berjalan: baru atau in_progress. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [ProjectStatus::New, ProjectStatus::InProgress]);
    }

    public function activityLabel(): string
    {
        return "project {$this->title}";
    }
}
