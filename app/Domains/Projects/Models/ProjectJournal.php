<?php

namespace App\Domains\Projects\Models;

use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Projects\Enums\JournalCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entri jurnal progress project (F3-4): catatan kronologis apa yang sudah
 * dikerjakan, ditampilkan sebagai timeline di halaman detail project.
 */
class ProjectJournal extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'project_id', 'user_id', 'occurred_on', 'category', 'body',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'category' => JournalCategory::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Penulis entri (nullable bila user dihapus). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activityLabel(): string
    {
        $title = $this->project?->title;

        return $title ? "jurnal project {$title}" : 'jurnal project';
    }
}
