<?php

namespace App\Domains\Security\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SecurityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Batas ukuran PDF laporan (KB) — dikonfigurasi lewat SECURITY_REPORT_MAX_KB. */
    private function maxKb(): int
    {
        return max(1, (int) config('crm.security.report_max_kb', 10240));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.$this->maxKb()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Klien wajib dipilih.',
            'period.required' => 'Periode laporan wajib diisi.',
            'period.regex' => 'Format periode harus YYYY-MM (contoh 2026-09).',
            'file.required' => 'Berkas PDF laporan wajib diunggah.',
            'file.file' => 'Berkas laporan tidak valid.',
            'file.mimes' => 'Berkas laporan harus berformat PDF.',
            'file.max' => 'Ukuran berkas laporan melebihi batas '.$this->maxKb().' KB.',
        ];
    }
}
