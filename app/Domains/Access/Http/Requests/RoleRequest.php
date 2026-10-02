<?php

namespace App\Domains\Access\Http\Requests;

use App\Domains\Access\Enums\AccessLevel;
use App\Domains\Access\Enums\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\-_]*$/',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['nullable', Rule::in(array_column(AccessLevel::cases(), 'value'))],
            'is_admin' => ['boolean'],
        ];
    }

    /**
     * Normalisasi: hanya modul yang dikenal, level Manage otomatis untuk modul
     * baca-saja diturunkan ke View, dan flag is_admin hanya boleh diset oleh
     * administrator (serta tidak bisa dicabut dari role admin — cegah lockout).
     */
    protected function prepareForValidation(): void
    {
        $input = $this->input('permissions');
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach (Module::cases() as $module) {
            $value = $input[$module->value] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $level = AccessLevel::tryFrom($value);

            if ($level === null) {
                continue;
            }

            if ($level === AccessLevel::Manage && ! $module->supportsManage()) {
                $level = AccessLevel::View;
            }

            $clean[$module->value] = $level->value;
        }

        $isAdmin = $this->boolean('is_admin') && (bool) $this->user()?->isAdmin();

        $role = $this->route('role');

        if ($role !== null && $role->is_admin) {
            $isAdmin = true;
        }

        $this->merge([
            'permissions' => $clean,
            'is_admin' => $isAdmin,
        ]);
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama role',
            'label' => 'label',
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Nama role hanya boleh huruf kecil, angka, tanda hubung, dan garis bawah.',
            'name.unique' => 'Nama role sudah dipakai.',
        ];
    }
}
