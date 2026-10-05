<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pengingat Perpanjangan Layanan {{ $service->name }}</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;font-size:14px;line-height:1.5;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e2e8f0;">
            <p style="margin:0;font-size:18px;font-weight:bold;color:#4f46e5;">{{ $business['name'] }}</p>
            <p style="margin:4px 0 0;font-size:12px;color:#64748b;">
                {{ $business['address'] }} · {{ $business['email'] }} · {{ $business['phone'] }}
            </p>
        </div>

        <div style="padding:24px;">
            <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Pengingat perpanjangan</p>
            <p style="margin:0 0 16px;font-size:22px;font-weight:bold;">{{ $service->name }}</p>

            <p style="margin:0 0 16px;">Halo {{ $service->client?->contact_name ?? $service->client?->name ?? 'Klien' }},</p>
            @if ($kind === \App\Domains\Services\Enums\ServiceReminderKind::Overdue)
                <p style="margin:0 0 16px;">
                    Kami ingin mengingatkan dengan hormat bahwa layanan berikut telah melewati jatuh tempo
                    <strong>{{ abs($daysRemaining ?? 0) }} hari</strong> yang lalu. Mohon segera diperpanjang agar layanan tidak terputus.
                </p>
            @else
                <p style="margin:0 0 16px;">
                    Kami ingin mengingatkan dengan hormat bahwa layanan berikut akan segera berakhir
                    ({{ $kind->label() }}). Silakan lakukan perpanjangan agar layanan tetap berjalan.
                </p>
            @endif

            <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Layanan</td>
                    <td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $service->name }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Jenis</td>
                    <td style="padding:6px 0;text-align:right;">{{ $service->type->label() }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Berakhir</td>
                    <td style="padding:6px 0;text-align:right;">{{ tgl_id($service->end_date) }} ({{ $kind->label() }})</td>
                </tr>
                @if ($kind === \App\Domains\Services\Enums\ServiceReminderKind::Overdue)
                    <tr>
                        <td style="padding:6px 0;color:#64748b;">Keterlambatan</td>
                        <td style="padding:6px 0;text-align:right;">{{ abs($daysRemaining ?? 0) }} hari</td>
                    </tr>
                @elseif ($daysRemaining !== null)
                    <tr>
                        <td style="padding:6px 0;color:#64748b;">Sisa waktu</td>
                        <td style="padding:6px 0;text-align:right;">{{ $daysRemaining }} hari</td>
                    </tr>
                @endif
                @if ($service->price > 0)
                    <tr>
                        <td style="padding:6px 0;color:#64748b;border-top:2px solid #0f172a;">Perpanjangan</td>
                        <td style="padding:6px 0;text-align:right;font-weight:bold;border-top:2px solid #0f172a;">{{ rupiah($service->price) }}</td>
                    </tr>
                @endif
            </table>

            <p style="margin:0 0 16px;">
                Untuk perpanjangan atau pertanyaan, silakan balas email ini atau hubungi kami.
                Terima kasih.
            </p>
        </div>

        <div style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">
            {{ $business['name'] }} · {{ $business['email'] }}
        </div>
    </div>
</body>
</html>
