<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pencapaian — {{ $project->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; margin: 28px; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 10px; margin-bottom: 16px; }
        .header .business { font-size: 15px; font-weight: bold; color: #4f46e5; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; font-size: 11px; text-transform: uppercase; color: #475569; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; white-space: pre-line; line-height: 1.5; }
        .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        <div class="business">{{ $business['name'] ?? 'MCI Media' }}</div>
        <div class="muted">{{ $business['address'] ?? '' }} · {{ $business['email'] ?? '' }}</div>
        <h1 style="margin-top:12px;">Laporan Pencapaian</h1>
    </div>

    <table>
        <tr><th style="width:30%">Project</th><td>{{ $project->title }}</td></tr>
        <tr><th>Klien</th><td>{{ $project->client?->name ?? '—' }}</td></tr>
        <tr><th>Periode</th><td>{{ $report->periodLabel() }}</td></tr>
        <tr><th>Status project</th><td>{{ $project->status->label() }}</td></tr>
        <tr><th>Progress</th><td>{{ $project->progressPercent() }}% ({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }} task)</td></tr>
        <tr><th>Dibuat</th><td>{{ tgl_id($report->generated_at ?? $report->created_at) }} — {{ $report->author?->name ?? 'Sistem' }}</td></tr>
    </table>

    <h2>Ringkasan otomatis</h2>
    <div class="box">{{ $report->summary ?: 'Ringkasan belum dihitung.' }}</div>

    <h2>Narasi</h2>
    <div class="box">{{ $report->narrative ?: 'Belum ada narasi manual.' }}</div>

    <div class="footer">
        Dokumen dibuat otomatis oleh CRM MCI Media. Ringkasan dihitung dari data task &amp; jurnal pada periode laporan.
    </div>
</body>
</html>
