<?php

namespace App\Domains\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            // Saat membuat user password wajib; saat mengubah boleh dikosongkan
            // (berarti kata sandi lama dipertahankan).
            'password' => $user
                ? ['nullable', 'confirmed', Password::min(8)]
                : ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'role_id' => 'role',
            'password' => 'kata sandi',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email sudah dipakai user lain.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'role_id.required' => 'Role wajib dipilih.',
        ];
    }
}
