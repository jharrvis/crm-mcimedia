<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Penawaran {{ $quotation->number }}</title>
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
            <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Penawaran</p>
            <p style="margin:0 0 16px;font-size:22px;font-weight:bold;">{{ $quotation->number }}</p>

            <p style="margin:0 0 16px;">Halo {{ $quotation->client?->contact_name ?? $quotation->client?->name ?? 'Klien' }},</p>
            <p style="margin:0 0 16px;">
                Berikut kami sampaikan penawaran harga dari {{ $business['name'] }}. Ringkasan penawaran dan
                dokumen PDF resmi kami lampirkan pada email ini.
            </p>

            <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Perihal</td>
                    <td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $quotation->title ?: '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Tanggal terbit</td>
                    <td style="padding:6px 0;text-align:right;">{{ tgl_id($quotation->issue_date) }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#64748b;">Berlaku hingga</td>
                    <td style="padding:6px 0;text-align:right;">{{ tgl_id($quotation->valid_until) }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#64748b;border-top:2px solid #0f172a;">Total</td>
                    <td style="padding:6px 0;text-align:right;font-weight:bold;border-top:2px solid #0f172a;">{{ rupiah($quotation->total) }}</td>
                </tr>
            </table>

            @if ($publicUrl)
                <p style="margin:0 0 8px;">Lihat detail penawaran dan berikan tanggapan Anda melalui tautan berikut (tanpa login):</p>
                <p style="margin:0 0 24px;">
                    <a href="{{ $publicUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold;">Lihat &amp; tanggapi penawaran</a>
                </p>
                <p style="margin:0 0 16px;font-size:12px;color:#64748b;word-break:break-all;">{{ $publicUrl }}</p>
            @endif

            <p style="margin:0;font-size:13px;color:#64748b;">
                Dokumen PDF resmi penawaran terlampir pada email ini. Bila ada pertanyaan, hubungi
                <strong>{{ $business['email'] }}</strong>.
            </p>
        </div>

        <div style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">
            {{ $business['name'] }} · {{ $business['email'] }}
        </div>
    </div>
</body>
</html>
