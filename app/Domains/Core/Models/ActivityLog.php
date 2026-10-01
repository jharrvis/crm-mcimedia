<?php

namespace App\Domains\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'event', 'description', 'properties', 'ip_address',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public static function record(Model $subject, string $event, ?string $description = null): self
    {
        $label = method_exists($subject, 'activityLabel')
            ? (fn () => $this->activityLabel())->call($subject)
            : class_basename($subject).' #'.$subject->getKey();

        return static::create([
            'user_id' => auth()->id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'description' => $description ?? "{$label} ".event_id($event),
            'ip_address' => request()->ip(),
        ]);
    }
}
