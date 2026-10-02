<?php

namespace App\Domains\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi aksi ubah auto-renew pada driver NameSilo (F4-7).
 *
 * Terpisah dari DomainProviderRequest karena ini aksi runtime (bukan CRUD
 * provider) dan hanya relevan bila driver mendukung auto-renew.
 */
class DomainAutoRenewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.\-_]+$/i'],
            'enable' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['domain' => 'domain'];
    }

    public function messages(): array
    {
        return [
            'domain.required' => 'Domain wajib dipilih.',
            'domain.regex' => 'Format domain tidak valid.',
            'enable.required' => 'Tentukan auto-renew aktif atau nonaktif.',
            'enable.boolean' => 'Nilai auto-renew tidak valid.',
        ];
    }
}
