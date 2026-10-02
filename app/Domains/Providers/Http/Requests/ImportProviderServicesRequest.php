<?php

namespace App\Domains\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi impor domain provider menjadi layanan CRM (F4-7).
 */
class ImportProviderServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => [
                'required',
                'integer',
                // Hanya klien aktif agar layanan tidak terimpor ke akun nonaktif.
                Rule::exists('clients', 'id')->where('is_active', true),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['client_id' => 'klien'];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Pilih klien pemilik domain terlebih dahulu.',
            'client_id.exists' => 'Klien yang dipilih tidak ada atau sedang nonaktif.',
        ];
    }
}
