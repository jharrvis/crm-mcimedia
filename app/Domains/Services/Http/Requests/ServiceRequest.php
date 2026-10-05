<?php

namespace App\Domains\Services\Http\Requests;

use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'parent_id' => [
                'nullable',
                Rule::exists('services', 'id')->where(function ($query) {
                    // Induk harus layanan domain milik klien yang sama.
                    $query->where('type', 'domain')
                        ->where('client_id', $this->input('client_id'));
                }),
            ],
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

    public function messages(): array
    {
        return [
            'parent_id.exists' => 'Domain induk harus layanan domain milik klien yang sama.',
        ];
    }

    /**
     * Aturan parent-child di luar kolom (F4-9).
     *
     * 1. Hanya layanan jenis `domain` boleh punya domain induk — subdomain
     *    adalah domain, bukan hosting/maintenance.
     * 2. Larangan siklus: layanan tidak boleh jadi induk dirinya sendiri,
     *    dan tidak boleh jadi induk dari subdomain-nya sendiri (a -> b -> a).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_id');

            if ($parentId === null || $parentId === '') {
                return;
            }

            if ($this->input('type') !== 'domain') {
                $validator->errors()->add('parent_id', 'Hanya layanan jenis domain yang bisa dikelompokkan di bawah domain induk.');

                return;
            }

            $service = $this->route('service');

            if (! $service instanceof Service) {
                return;
            }

            if ((int) $parentId === $service->id) {
                $validator->errors()->add('parent_id', 'Layanan tidak bisa menjadi domain induk dari dirinya sendiri.');

                return;
            }

            if ($this->createsCycle($service, (int) $parentId)) {
                $validator->errors()->add('parent_id', 'Layanan tidak bisa menjadi domain induk dari subdomain-nya sendiri.');
            }
        });
    }

    /**
     * True bila $parentId berada di bawah $service (descendant), sehingga
     * menetapkan $service -> $parentId akan membentuk siklus.
     *
     * Berjalan naik rantai induk dengan batas kedalaman sebagai pengaman bila
     * data sudah terlanjur membentuk siklus (mis. diimpor dari luar).
     */
    private function createsCycle(Service $service, int $parentId): bool
    {
        try {
            $parent = Service::findOrFail($parentId);
        } catch (ModelNotFoundException) {
            // Sudah tertangkap aturan `exists`; di sini hanya defensif.
            return false;
        }

        $seen = [];
        $cursor = $parent;
        $depth = 0;

        while ($cursor !== null && $depth < 50) {
            if ($cursor->id === $service->id) {
                return true;
            }

            if (isset($seen[$cursor->id])) {
                return false;
            }

            $seen[$cursor->id] = true;
            $cursor = $cursor->parent_id !== null
                ? Service::find($cursor->parent_id)
                : null;
            $depth++;
        }

        return false;
    }
}
