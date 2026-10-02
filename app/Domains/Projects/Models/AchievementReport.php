<?php

namespace App\Domains\Projects\Models;

use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Projects\Enums\ReportPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laporan pencapaian (F3-4): satu laporan per project per periode, berisi
 * ringkasan otomatis dari data periode (task selesai + jurnal) dan narasi
 * manual. `generated_at` menandai laporan yang dibuat otomatis oleh command.
 */
class AchievementReport extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'project_id', 'period_type', 'period_start', 'period_end',
        'summary', 'narrative', 'generated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => ReportPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Label periode manusiawi, mis. "Bulan Oktober 2026". */
    public function periodLabel(): string
    {
        return $this->period_type->rangeLabel($this->period_start, $this->period_end);
    }

    public function activityLabel(): string
    {
        $title = $this->project?->title;

        return $title ? "laporan pencapaian project {$title}" : 'laporan pencapaian';
    }
}
