<?php

namespace App\Domains\Core\Traits;

use App\Domains\Core\Models\ActivityLog;

/**
 * Otomatis mencatat created/updated/deleted ke tabel activity_logs.
 * Model yang memakai trait ini wajib mengimplementasikan activityLabel().
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => $model->logActivity('created'));
        static::updated(fn ($model) => $model->logActivity('updated'));
        static::deleted(fn ($model) => $model->logActivity('deleted'));
    }

    abstract public function activityLabel(): string;

    protected function logActivity(string $event): void
    {
        ActivityLog::record($this, $event);
    }
}
