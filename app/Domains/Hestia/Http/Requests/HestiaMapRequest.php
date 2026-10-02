<?php

namespace App\Domains\Hestia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HestiaMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Pilih klien terlebih dahulu.',
            'client_id.exists' => 'Klien yang dipilih tidak valid.',
        ];
    }
}
