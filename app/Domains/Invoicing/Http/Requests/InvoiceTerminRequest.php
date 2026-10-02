<?php

namespace App\Domains\Invoicing\Http\Requests;

use App\Domains\Invoicing\Services\InvoiceTerminSplitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validasi form "pecah invoice menjadi termin" (F4-10).
 *
 * Perhitungan nominal & validasi domain (total persentase tepat 100%, tanggal
 * jatuh tempo) dilakukan di InvoiceTerminSplitter — FormRequest ini hanya
 * menjaga bentuk payload & rentang dasar sebelum service dipanggil.
 */
class InvoiceTerminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'terms' => ['required', 'array', 'min:2', 'max:'.InvoiceTerminSplitter::MAX_TERMS],
            'terms.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'terms.*.due_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms.required' => 'Tambahkan minimal dua termin.',
            'terms.min' => 'Pecah invoice menjadi minimal dua termin.',
            'terms.max' => 'Jumlah termin melebihi batas yang diperbolehkan.',
            'terms.*.percent.required' => 'Persentase tiap termin wajib diisi.',
            'terms.*.percent.numeric' => 'Persentase termin harus berupa angka.',
            'terms.*.percent.gt' => 'Persentase termin harus lebih dari 0.',
            'terms.*.percent.max' => 'Persentase termin tidak boleh melebihi 100.',
            'terms.*.due_date.required' => 'Tanggal jatuh tempo tiap termin wajib diisi.',
            'terms.*.due_date.date' => 'Tanggal jatuh tempo termin tidak valid.',
        ];
    }

    /** Buang baris kosong dari input dinamis form sebelum validasi. */
    protected function prepareForValidation(): void
    {
        $terms = collect($this->input('terms', []))
            ->filter(fn ($term) => is_array($term)
                && filled($term['percent'] ?? null)
                && filled($term['due_date'] ?? null))
            ->values()
            ->all();

        $this->merge(['terms' => $terms]);
    }

    /**
     * Termin yang sudah tervalidasi, siap lolos ke InvoiceTerminSplitter.
     *
     * @return array<int, array{percent: float, due_date: string}>
     */
    public function terms(): array
    {
        return array_map(fn (array $term): array => [
            'percent' => (float) $term['percent'],
            'due_date' => $term['due_date'],
        ], $this->validated()['terms']);
    }

    /**
     * Total persentase harus tepat 100% — dicek di sini agar pesan error
     * menempel ke field (bukan exception yang lepas dari form).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $percents = array_column($this->terms(), 'percent');
                $total = array_sum($percents);

                if (abs($total - 100.0) > 0.0001) {
                    $validator->errors()->add(
                        'terms',
                        'Total persentase termin harus tepat 100% (kini '
                        .rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.').'%).'
                    );
                }

                // Tanggal jatuh tempo termin tidak boleh mendahului tanggal
                // terbit invoice kontrak. Dicek di sini juga (selain di
                // service) supaya pesan menempel ke baris yang salah, bukan
                // hanya banner error di halaman detail.
                $issueDate = $this->route('invoice')?->issue_date;

                if ($issueDate === null) {
                    return;
                }

                $issued = Carbon::parse($issueDate)->startOfDay();

                foreach ($this->input('terms', []) as $index => $term) {
                    if (blank($term['due_date'] ?? null) || ! is_array($term)) {
                        continue;
                    }

                    try {
                        $due = Carbon::parse($term['due_date'])->startOfDay();
                    } catch (Throwable) {
                        continue; // sudah ditangani aturan `date`
                    }

                    if ($due->lt($issued)) {
                        $validator->errors()->add(
                            "terms.{$index}.due_date",
                            'Tanggal jatuh tempo termin tidak boleh sebelum tanggal terbit invoice kontrak.'
                        );
                    }
                }
            },
        ];
    }
}
