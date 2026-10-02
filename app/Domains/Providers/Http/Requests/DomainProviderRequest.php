<?php

namespace App\Domains\Providers\Http\Requests;

use App\Domains\Providers\DomainProviderRegistry;
use App\Domains\Providers\Models\DomainProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi tambah/ubah provider domain (F4-5).
 *
 * Aturan kredensial dibangun DINAMIS dari `DomainProviderDriver::credentialFields()`
 * driver yang dipilih — sehingga driver baru otomatis tervalidasi tanpa
 * menyentuh kelas ini.
 */
class DomainProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $registry = app(DomainProviderRegistry::class);
        $existing = $this->route('domain_provider');
        $existingId = $existing instanceof DomainProvider ? $existing->id : null;

        $rules = [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('domain_providers', 'name')->ignore($existingId),
            ],
            'driver' => ['required', 'string', Rule::in($registry->keys())],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        $driverKey = $this->input('driver');

        if (is_string($driverKey) && $registry->has($driverKey)) {
            // Field rahasia hanya wajib saat create (atau saat ganti driver),
            // bukan saat edit — biar nilai lama tidak perlu diketik ulang.
            $driverChanged = ! $existing instanceof DomainProvider || $existing->driver !== $driverKey;

            foreach ($registry->credentialFields($driverKey) as $field => $definition) {
                $rules["credentials.{$field}"] = $this->fieldRules($definition, $driverChanged);
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama provider wajib diisi.',
            'name.unique' => 'Nama provider sudah dipakai.',
            'driver.required' => 'Jenis driver wajib dipilih.',
            'driver.in' => 'Jenis driver tidak dikenal.',
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function fieldRules(array $definition, bool $driverChanged): array
    {
        $type = (string) ($definition['type'] ?? 'text');
        $required = (bool) ($definition['required'] ?? false);
        $secret = (bool) ($definition['secret'] ?? false);

        if ($type === 'checkbox') {
            return ['sometimes', 'boolean'];
        }

        $mustFill = $required && (! $secret || $driverChanged);

        $rules = [$mustFill ? 'required' : 'nullable'];

        if ($type === 'number') {
            $rules[] = 'numeric';
        } elseif ($type === 'textarea') {
            $rules[] = 'string';
            $rules[] = 'json';
            $rules[] = 'max:50000';
        } else {
            $rules[] = 'string';
            $rules[] = 'max:1000';
        }

        return $rules;
    }
}
