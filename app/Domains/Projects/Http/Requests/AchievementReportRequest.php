<?php

namespace App\Domains\Projects\Http\Requests;

use App\Domains\Projects\Enums\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AchievementReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_type' => ['required', Rule::enum(ReportPeriod::class)],
            // Tanggal acuan di dalam periode; kosong = hari ini.
            'anchor' => ['nullable', 'date'],
            'narrative' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
