<?php

namespace App\Domains\Hestia\Http\Requests;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi tambah/ubah server HestiaCP (F4-12).
 *
 * Field rahasia (`credentials.password`, `credentials.access_key`,
 * `credentials.secret_key`) tidak wajib saat edit — bila kosong, nilai lama
 * dipertahankan. Ini mengikuti pola `DomainProviderRequest` (F4-5) agar admin
 * tidak perlu mengetik ulang kredensial yang sudah tersimpan.
 */
class HestiaServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existing = $this->route('hestia_server');
        $existingId = $existing instanceof HestiaServer ? $existing->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('hestia_servers', 'code')->ignore($existingId),
            ],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'scheme' => ['nullable', 'string', Rule::in(['http', 'https'])],
            'verify_ssl' => ['sometimes', 'boolean'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'netdata_host' => ['nullable', 'string', 'max:255'],
            'netdata_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],

            'credentials.user' => ['nullable', 'string', 'max:255'],
            'credentials.password' => ['nullable', 'string', 'max:255'],
            'credentials.access_key' => ['nullable', 'string', 'max:255'],
            'credentials.secret_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama server wajib diisi.',
            'code.regex' => 'Kode server hanya boleh huruf kecil, angka, dan tanda hubung.',
            'code.unique' => 'Kode server sudah dipakai.',
            'host.required' => 'Host/HESTIA_HOST wajib diisi.',
            'scheme.in' => 'Skema harus http atau https.',
        ];
    }

    public function attributes(): array
    {
        return [
            'credentials.user' => 'user',
            'credentials.password' => 'password',
            'credentials.access_key' => 'access key',
            'credentials.secret_key' => 'secret key',
        ];
    }

    /**
     /** Data siap-simpan. Kredensial rahasia yang tidak dikirim pada edit
          * DIJAGA dari nilai lama (bukan ditimpa jadi kosong).
          *
          * @return array<string, mixed>
          */
         public function payload(): array
         {
             $code = $this->string('code')->trim()->value();
             $name = $this->string('name')->trim()->value();

             return [
                 'name' => $name,
                 // Code dibuat otomatis dari nama bila admin tidak mengisinya.
                 'code' => $code !== '' ? $code : HestiaServer::makeCode($name),
                 'host' => $this->string('host')->trim()->value(),
                 'port' => (int) ($this->input('port') ?: 8083),
                 'scheme' => $this->input('scheme') ?: 'https',
                 'verify_ssl' => $this->boolean('verify_ssl'),
                 'timeout' => (int) ($this->input('timeout') ?: 30),
                 'netdata_host' => $this->string('netdata_host')->trim()->value() ?: null,
                 'netdata_port' => $this->filled('netdata_port') ? (int) $this->input('netdata_port') : null,
                 'is_active' => $this->boolean('is_active'),
                 'notes' => $this->input('notes'),
                 'credentials' => $this->mergedCredentials(),
             ];
         }

    /**
     * @return array<string, string>
     */
    private function mergedCredentials(): array
    {
        $incoming = $this->input('credentials', []);
        $incoming = is_array($incoming) ? $incoming : [];

        $existing = $this->route('hestia_server');
        $current = $existing instanceof HestiaServer ? $existing->credentialBag() : [];

        $secrets = ['user', 'password', 'access_key', 'secret_key'];
        $merged = [];

        foreach ($secrets as $field) {
            $value = trim((string) ($incoming[$field] ?? ''));

            // Kosong saat edit = "pertahankan nilai lama".
            $merged[$field] = $value !== '' ? $value : (string) ($current[$field] ?? '');
        }

        // Buang entri kosong agar tidak menyimpan string kosong sia-sia.
        return array_filter($merged, fn ($value) => $value !== '');
    }
}
