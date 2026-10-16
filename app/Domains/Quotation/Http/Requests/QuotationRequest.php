<?php

namespace App\Domains\Quotation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            // Penawaran tanpa item tidak punya makna — minimal satu baris.
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            // Split button simpan (UX-2): draf (default), send, confirm.
            // Nilai di luar daftar ditolak 422 (trust boundary — hanya tombol
            // native yang mengirim nilai ini).
            'save_action' => ['nullable', 'string', 'in:draft,send,confirm'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Penawaran minimal memiliki satu item.',
            'items.min' => 'Penawaran minimal memiliki satu item.',
            'items.*.description.required' => 'Deskripsi setiap item wajib diisi.',
            'items.*.quantity.min' => 'Jumlah item minimal 1.',
            'valid_until.after_or_equal' => 'Masa berlaku tidak boleh sebelum tanggal terbit.',
            'discount.min' => 'Diskon tidak boleh negatif.',
        ];
    }

    /**
     * Bersihkan input sebelum validasi: item dengan description kosong
     * dibuang, agar baris "tambah baris" yang tidak dipakai tidak menggagalkan
     * submit (meniru InvoiceRequest).
     */
    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['description'] ?? null))
            ->values()
            ->all();

        $this->merge([
            'items' => $items,
            'discount' => max(0, (int) $this->input('discount', 0)),
        ]);
    }

    /**
     * Aksi simpan dari split button (draft|send|confirm). Nilai kosong
     * dijamin 'draft'.
     */
    public function saveAction(): string
    {
        $value = $this->validated()['save_action'] ?? null;

        return in_array($value, ['send', 'confirm'], true) ? $value : 'draft';
    }

    /**
     * Item yang sudah dibersihkan & tervalidasi.
     *
     * @return array<int, array{description: string, quantity: int, unit_price: int}>
     */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (int) $item['unit_price'],
        ], $this->validated()['items']));
    }
}