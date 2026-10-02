<?php

namespace App\Domains\Projects\Http\Requests;

use App\Domains\Projects\Enums\JournalCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'occurred_on' => ['required', 'date'],
            'category' => ['required', Rule::enum(JournalCategory::class)],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
