<?php

namespace App\Domains\Invoicing\Http\Requests;

use App\Domains\Invoicing\Enums\RecurringCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cycles = implode(',', array_column(RecurringCycle::cases(), 'value'));

        return [
            'client_id' => ['required', 'exists:clients,id'],
            // Layanan harus milik klien yang dipilih (mencegah tautan silang).
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where(
                    fn ($q) => $q->where('client_id', $this->integer('client_id'))
                ),
            ],
            'title' => ['required', 'string', 'max:255'],
            'cycle' => ['required', 'in:'.$cycles],
            'next_invoice_date' => ['required', 'date'],
            'due_days' => ['required', 'integer', 'min:1', 'max:90'],
            'auto_send' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.exists' => 'Layanan harus milik klien yang dipilih.',
            'items.required' => 'Paket recurring minimal punya satu item.',
            'items.min' => 'Paket recurring minimal punya satu item.',
            'items.max' => 'Paket recurring maksimal 50 item.',
            'items.*.unit_price.integer' => 'Harga satuan harus bilangan bulat (IDR).',
        ];
    }

    /** Data ternormalisasi untuk disimpan (boolean + item rapi). */
    public function planData(): array
    {
        $data = $this->validated();

        $data['active'] = $this->boolean('active');
        $data['auto_send'] = $this->boolean('auto_send');
        $data['due_days'] = (int) $data['due_days'];
        $data['service_id'] = $data['service_id'] ?? null;
        $data['notes'] = $data['notes'] ?? null;

        // Total = jumlah item (server yang menghitung, bukan input klien).
        $data['total'] = collect($data['items'])->sum(
            fn ($item) => (int) $item['quantity'] * (int) $item['unit_price']
        );

        return $data;
    }

    /** Item ternormalisasi, urutan dijaga. */
    public function itemRows(): array
    {
        return collect($this->validated()['items'])
            ->values()
            ->map(fn ($item, $sort) => [
                'description' => $item['description'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (int) $item['unit_price'],
                'sort_order' => $sort,
            ])
            ->all();
    }
}