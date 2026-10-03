<?php

namespace App\Domains\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            // Semua layanan harus milik klien yang dipilih (filter dropdown diperkuat di server).
            'service_ids' => ['nullable', 'array', 'max:100'],
            'service_ids.*' => [
                'integer',
                Rule::exists('services', 'id')->where('client_id', $this->input('client_id')),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            // Split button simpan (UX-2): draf (default), send (lanjut ke alur
            // kirim), confirm (tandai terkirim). Nilai di luar daftar ditolak 422
            // (trust boundary — hanya tombol native yang mengirim nilai ini).
            'save_action' => ['nullable', 'string', 'in:draft,send,confirm'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Invoice minimal memiliki satu item.',
            'items.min' => 'Invoice minimal memiliki satu item.',
            'items.*.description.required' => 'Deskripsi setiap item wajib diisi.',
            'due_date.after_or_equal' => 'Tanggal jatuh tempo tidak boleh sebelum tanggal terbit.',
            'service_ids.array' => 'Layanan terkait harus berupa daftar.',
            'service_ids.*.exists' => 'Layanan yang dipilih tidak milik klien ini.',
        ];
    }

    /**
     * Aksi simpan dari split button (draft|send|confirm). Nilai kosong
     * (submit lama / tombol utama tanpa menu) dijamin 'draft'.
     */
    public function saveAction(): string
    {
        $value = $this->validated()['save_action'] ?? null;

        return in_array($value, ['send', 'confirm'], true) ? $value : 'draft';
    }

    /**
     * Bersihkan input sebelum validasi:
     * - item dengan description kosong dibuang agar tombol "tambah baris" yang
     *   tidak dipakai tidak menggagalkan submit;
     * - service_ids dinormalisasi (nilai kosong & duplikat dibuang) karena
     *   checkbox multi-select mengirim nilai kosong saat tidak ada yang dicentang.
     */
    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['description'] ?? null))
            ->values()
            ->all();

        $serviceIds = collect($this->input('service_ids', []))
            ->filter(fn ($id) => filled($id))
            ->unique()
            ->values()
            ->all();

        $this->merge(['items' => $items, 'service_ids' => $serviceIds]);
    }

    /**
     * ID layanan yang tervalidasi. Dipisah dari validated() agar controller
     * tidak perlu mengambilnya dari payload mentah.
     *
     * @return array<int, int>
     */
    public function serviceIds(): array
    {
        return array_map('intval', $this->validated()['service_ids'] ?? []);
    }
}
