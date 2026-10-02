<?php

namespace App\Domains\Projects\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Tasks\Enums\TaskStatus;
use App\Domains\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    /** Jurnal progress project, terbaru lebih dulu (timeline). */
    public function journals(): HasMany
    {
        return $this->hasMany(ProjectJournal::class)
            ->orderByDesc('occurred_on')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function achievementReports(): HasMany
    {
        return $this->hasMany(AchievementReport::class)->orderByDesc('period_start');
    }

    /** Project yang masih berjalan: baru atau in_progress. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [ProjectStatus::New, ProjectStatus::InProgress]);
    }

    /** Muat hitungan task total & selesai untuk perhitungan progress tanpa N+1. */
    public function scopeWithTaskCounts(Builder $query): Builder
    {
        return $query->withCount([
            'tasks',
            'tasks as done_tasks_count' => fn (Builder $q) => $q->where('status', TaskStatus::Done),
        ]);
    }

    /** Sama seperti scopeWithTaskCounts, untuk model yang sudah diambil. */
    public function loadTaskCounts(): self
    {
        $this->loadCount([
            'tasks',
            'tasks as done_tasks_count' => fn (Builder $q) => $q->where('status', TaskStatus::Done),
        ]);

        return $this;
    }

    /**
     * Persentase penyelesaian dari task: done/total, dibulatkan.
     * Tanpa task: 100% bila project sudah selesai, selain itu 0%.
     */
    public function progressPercent(): int
    {
        $total = (int) ($this->tasks_count ?? $this->tasks()->count());

        if ($total === 0) {
            return $this->status === ProjectStatus::Done ? 100 : 0;
        }

        $done = (int) ($this->done_tasks_count
            ?? $this->tasks()->where('status', TaskStatus::Done)->count());

        return (int) round($done / $total * 100);
    }

    /** Jumlah task selesai (memakai hasil withCount bila sudah dimuat). */
    public function doneTasksCount(): int
    {
        return (int) ($this->done_tasks_count
            ?? $this->tasks()->where('status', TaskStatus::Done)->count());
    }

    /** Jumlah task (memakai hasil withCount bila sudah dimuat). */
    public function tasksCount(): int
    {
        return (int) ($this->tasks_count ?? $this->tasks()->count());
    }

    /** Project berjalan (baru/berjalan) yang sudah melewati deadline. */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->deadline->isBefore(Carbon::today())
            && in_array($this->status, [ProjectStatus::New, ProjectStatus::InProgress], true);
    }

    public function activityLabel(): string
    {
        return "project {$this->title}";
    }
}
