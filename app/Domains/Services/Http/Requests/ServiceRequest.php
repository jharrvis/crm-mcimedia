<?php

namespace App\Domains\Services\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'type' => ['required', 'in:domain,hosting,server_management,maintenance,seo,other'],
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['nullable', 'integer', 'min:0'],
            'cycle' => ['required', 'in:monthly,yearly,one_time'],
            'status' => ['required', 'in:active,inactive'],
            'reminder_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
