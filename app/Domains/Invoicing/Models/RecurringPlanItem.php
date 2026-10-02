<?php

namespace App\Domains\Invoicing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris item pada template invoice recurring (F4-11). */
class RecurringPlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurring_plan_id', 'description', 'quantity', 'unit_price', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RecurringPlan::class, 'recurring_plan_id');
    }

    /** Nilai baris ini (IDR, integer — tanpa PPN). */
    public function amount(): int
    {
        return $this->quantity * $this->unit_price;
    }
}