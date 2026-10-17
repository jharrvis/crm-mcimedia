<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pembayaran Diterima — {{ $invoice->number }}</title>
</head>
<body style="font-family: sans-serif; color: #1e293b; line-height: 1.6;">
    <h2>Pembayaran Diterima ✅</h2>
    <p>Halo {{ $invoice->client?->name ?? 'Bapak/Ibu' }},</p>
    <p>
        Pembayaran untuk invoice <strong>{{ $invoice->number }}</strong>
        sebesar <strong>Rp {{ number_format((int) $invoice->total, 0, ',', '.') }}</strong>
        telah kami terima. Invoice dinyatakan <strong>LUNAS</strong>.
    </p>
    <p>Terima kasih atas pembayaran Anda.</p>
    <p>
        Hormat kami,<br>
        <strong>{{ $business['name'] ?? 'MCI Media' }}</strong>
    </p>
</body>
</html>
