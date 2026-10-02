<?php

namespace App\Domains\Security\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SecurityActionRequest extends FormRequest
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
            'acted_at' => ['required', 'date'],
            'action' => ['required', 'string', 'max:5000'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Klien wajib dipilih.',
            'acted_at.required' => 'Tanggal tindakan wajib diisi.',
            'action.required' => 'Uraian tindakan wajib diisi.',
        ];
    }
}
