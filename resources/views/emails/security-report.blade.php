<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Keamanan {{ $report->period }}</title>
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
            <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Laporan Keamanan</p>
            <p style="margin:0 0 16px;font-size:22px;font-weight:bold;">Periode {{ $report->period }}</p>

            <p style="margin:0 0 16px;">Dengan hormat,</p>
            <p style="margin:0 0 16px;">
                Bersama email ini kami sampaikan laporan keamanan periode <strong>{{ $report->period }}</strong>
                dari {{ $business['name'] }}. Dokumen PDF resmi kami lampirkan pada email ini.
            </p>

            <div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;background:#f8fafc;margin-bottom:16px;">
                <p style="margin:0;font-size:13px;">
                    Laporan ini bersifat <strong>rahasia</strong> dan hanya ditujukan untuk penerima yang berhak.
                    Mohon tidak meneruskan dokumen maupun tautan pada email ini kepada pihak lain.
                </p>
            </div>

            <p style="margin:0 0 8px;">
                Apabila ada pertanyaan mengenai isi laporan, silakan hubungi kami di
                <a href="mailto:{{ $business['email'] }}" style="color:#4f46e5;text-decoration:none;">{{ $business['email'] }}</a>.
            </p>
            <p style="margin:0;">Terima kasih atas kepercayaan Anda.</p>
        </div>

        <div style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;">
            {{ $business['name'] }} · {{ $business['email'] }}
        </div>
    </div>
</body>
</html>
