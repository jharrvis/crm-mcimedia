<?php

namespace App\Domains\Security\Http\Requests;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SecurityIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'occurred_at' => ['required', 'date'],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'source' => ['required', Rule::enum(IncidentSource::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(IncidentStatus::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Klien wajib dipilih.',
            'occurred_at.required' => 'Waktu kejadian wajib diisi.',
            'severity.required' => 'Tingkat keparahan wajib dipilih.',
            'source.required' => 'Sumber temuan wajib dipilih.',
            'title.required' => 'Judul insiden wajib diisi.',
        ];
    }
}
