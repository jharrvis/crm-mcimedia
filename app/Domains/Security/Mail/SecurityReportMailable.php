<?php

namespace App\Domains\Security\Mail;

use App\Domains\Security\Models\SecurityReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email laporan keamanan ke klien (F4-3): melampirkan berkas PDF yang diunggah
 * admin. Greeting sengaja generik (tanpa nama klien) karena laporan bersifat
 * rahasia dan tidak boleh membocorkan identitas klien bila email diteruskan.
 */
class SecurityReportMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public SecurityReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Laporan Keamanan Periode '.$this->report->period.' — '.config('crm.business.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-report',
            with: [
                'report' => $this->report,
                'business' => config('crm.business'),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $disk = config('crm.security.report_disk', 'local');

        return [
            Attachment::fromStorageDisk($disk, $this->report->file_path)
                ->as($this->report->downloadName())
                ->withMime('application/pdf'),
        ];
    }
}
