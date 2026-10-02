<?php

namespace App\Domains\Tasks\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Enums\TaskPriority;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'title', 'description', 'client_id', 'project_id', 'assigned_user_id',
        'priority', 'due_date', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Tugas "terbuka" = belum selesai (todo / dikerjakan / review).
     * Status lain (done) dianggap selesai.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Review]);
    }

    /** Tugas yang sudah lewat due date dan belum selesai. */
    public function isOverdue(): bool
    {
        return ! $this->status->isDone()
            && $this->due_date !== null
            && $this->due_date->isBefore(Carbon::today());
    }

    /** Terbuka dan due date <= 3 hari ke depan (termasuk yang sudah lewat). */
    public function scopeUrgent(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', Carbon::today()->addDays(3));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today());
    }

    public function complete(): void
    {
        $this->update(['status' => TaskStatus::Done, 'completed_at' => now()]);
    }

    public function reopen(): void
    {
        $this->update(['status' => TaskStatus::Open, 'completed_at' => null]);
    }

    public function activityLabel(): string
    {
        return "tugas {$this->title}";
    }
}
