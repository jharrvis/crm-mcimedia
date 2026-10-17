<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }}</title>
    @php
        $business = config('crm.business');
        $badge = [
            'draft'     => ['bg' => '#fffbeb', 'tx' => '#b45309', 'bd' => '#fde68a', 'label' => 'DRAF'],
            'sent'      => ['bg' => '#eff6ff', 'tx' => '#1d4ed8', 'bd' => '#bfdbfe', 'label' => 'TERKIRIM'],
            'accepted'  => ['bg' => '#ecfdf5', 'tx' => '#047857', 'bd' => '#a7f3d0', 'label' => 'DISETUJUI'],
            'rejected'  => ['bg' => '#fff1f2', 'tx' => '#e11d48', 'bd' => '#fecdd3', 'label' => 'DITOLAK'],
            'expired'   => ['bg' => '#f1f5f9', 'tx' => '#64748b', 'bd' => '#e2e8f0', 'label' => 'KEDALUWARSA'],
            'converted' => ['bg' => '#eef2ff', 'tx' => '#4338ca', 'bd' => '#c7d2fe', 'label' => 'JADI INVOICE'],
        ][$quotation->status->value] ?? ['bg' => '#f1f5f9', 'tx' => '#64748b', 'bd' => '#e2e8f0', 'label' => strtoupper($quotation->status->label())];
    @endphp
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #0f172a;
            line-height: 1.5;
            background: #ffffff;
        }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        table { border-collapse: collapse; }
        /* Accent bar */
        table.accent { width: 100%; }
        table.accent td { height: 8px; padding: 0; }
        /* Content wrapper */
        .content { padding: 36px 44px 60px 44px; }
        /* Header */
        table.header { width: 100%; margin-bottom: 26px; }
        table.header td { vertical-align: top; }
        .badge-logo {
            width: 46px; height: 46px; background: #0f172a; border-radius: 9px;
            text-align: center; vertical-align: middle;
        }
        .badge-logo .mci { color: #ffffff; font-size: 13px; font-weight: bold; letter-spacing: -0.5px; }
        .badge-logo .media { color: #60a5fa; font-size: 6px; font-weight: bold; letter-spacing: 2px; }
        .biz-name { font-size: 17px; font-weight: bold; color: #0f172a; }
        .biz-addr { font-size: 10px; color: #64748b; margin-top: 2px; line-height: 1.5; }
        .biz-contact { font-size: 10px; color: #475569; margin-top: 4px; }
        .doc-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #2563eb; }
        .doc-title { font-size: 23px; font-weight: bold; color: #0f172a; margin-top: 2px; }
        .doc-chips { margin-top: 8px; text-align: right; }
        .num-chip {
            font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; font-weight: bold; color: #334155;
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 4px 9px;
        }
        .status-chip {
            font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;
            border-radius: 4px; padding: 4px 9px; border: 1px solid;
            background: {{ $badge['bg'] }}; color: {{ $badge['tx'] }}; border-color: {{ $badge['bd'] }};
        }
        /* Cards */
        table.cards { width: 100%; margin-bottom: 22px; }
        table.cards > tbody > tr > td { vertical-align: top; }
        .card {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px;
        }
        .card-title { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; margin-bottom: 8px; }
        .client-name { font-size: 12px; font-weight: bold; color: #0f172a; }
        .client-meta { font-size: 10px; color: #475569; margin-top: 4px; line-height: 1.55; }
        .kv { width: 100%; margin-top: 8px; border-top: 1px solid #e2e8f0; }
        .kv td { padding: 4px 0; font-size: 10px; vertical-align: top; }
        .kv .k { color: #94a3b8; width: 44px; }
        .kv .v { color: #334155; }
        .detail-row { padding: 5px 0; border-bottom: 1px solid #e2e8f0; }
        .detail-row td { padding: 5px 0; font-size: 10px; vertical-align: top; }
        .detail-row .k { color: #64748b; }
        .detail-row .v { text-align: right; font-weight: bold; color: #0f172a; }
        .detail-row .v-red { text-align: right; font-weight: bold; color: #e11d48; }
        /* Items table */
        table.items { width: 100%; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        table.items th {
            background: #0f172a; color: #ffffff; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px; text-align: left;
        }
        table.items th.ctr, table.items td.ctr { text-align: center; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 10px 12px; font-size: 10px; border-top: 1px solid #e2e8f0; vertical-align: top; }
        table.items td.desc strong { color: #0f172a; }
        table.items td.desc .sub { color: #64748b; margin-top: 3px; line-height: 1.5; }
        /* Bottom: notes + totals */
        table.bottom { width: 100%; margin-bottom: 30px; }
        table.bottom > tbody > tr > td { vertical-align: top; }
        .notes-card {
            background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 14px 16px;
        }
        .notes-card .card-title { color: #1e3a8a; }
        .notes-card p { font-size: 10px; color: #334155; line-height: 1.6; }
        .notes-card .fine { font-size: 9px; color: #64748b; font-style: italic; border-top: 1px solid #bfdbfe; margin-top: 8px; padding-top: 8px; }
        .totals-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; }
        .totals-card table { width: 100%; }
        .totals-card td { padding: 4px 0; font-size: 10px; }
        .totals-card .t-label { color: #64748b; }
        .totals-card .t-val { text-align: right; font-weight: bold; color: #0f172a; }
        .totals-card .grand td { border-top: 1px solid #e2e8f0; padding-top: 9px; }
        .totals-card .grand .t-label { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #0f172a; }
        .totals-card .grand .t-val { font-size: 15px; font-weight: bold; color: #1d4ed8; }
        /* Terms */
        .terms-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; margin-bottom: 20px; }
        .terms-card .card-title { color: #475569; }
        .terms-card p { font-size: 10px; color: #334155; line-height: 1.6; white-space: pre-line; }
        /* Signature */
        table.sign { width: 100%; margin-top: 10px; }
        table.sign td { font-size: 10px; color: #64748b; vertical-align: top; }
        table.sign .spacer { height: 52px; }
        table.sign .name { font-weight: bold; color: #0f172a; border-top: 1px solid #cbd5e1; display: inline-block; padding-top: 6px; margin-top: 4px; }
        /* Footer */
        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #0f172a; color: #94a3b8; font-size: 9px;
            padding: 11px 44px;
        }
        .footer table { width: 100%; }
        .footer .docnum { text-align: right; font-family: 'DejaVu Sans Mono', monospace; color: #64748b; }
        .pre-line { white-space: pre-line; }
    </style>
</head>
<body>

<!-- Accent bar -->
<table class="accent"><tr>
    <td style="width: 34%; background: #0f172a;"></td>
    <td style="width: 33%; background: #1d4ed8;"></td>
    <td style="width: 33%; background: #3b82f6;"></td>
</tr></table>

<div class="content">

    <!-- Header -->
    <table class="header">
        <tr>
            <td style="width: 60%;">
                <table><tr>
                    <td style="vertical-align: top; padding-right: 12px;">
                        <div class="badge-logo">
                            <div class="mci">MCI</div>
                            <div class="media">MEDIA</div>
                        </div>
                    </td>
                    <td style="vertical-align: top;">
                        <div class="biz-name">{{ $business['name'] }}</div>
                        <div class="biz-addr">{{ $business['address'] }}</div>
                        <div class="biz-contact">{{ $business['email'] }} &nbsp;•&nbsp; <span class="mono">{{ $business['phone'] }}</span></div>
                    </td>
                </tr></table>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="doc-label">Penawaran Harga</div>
                <div class="doc-title">QUOTATION</div>
                <div class="doc-chips">
                    <span class="num-chip">{{ $quotation->number }}</span>&nbsp;
                    <span class="status-chip">{{ $badge['label'] }}</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Client & detail cards -->
    <table class="cards">
        <tr>
            <td style="width: 50%; padding-right: 10px;">
                <div class="card">
                    <div class="card-title">Ditujukan Kepada</div>
                    <div class="client-name">{{ $quotation->client?->name ?? '—' }}</div>
                    @if ($quotation->client?->address)
                        <div class="client-meta">{{ $quotation->client->address }}</div>
                    @endif
                    <table class="kv">
                        @if ($quotation->client?->email)
                        <tr><td class="k">Email</td><td class="v">: {{ $quotation->client->email }}</td></tr>
                        @endif
                        @if ($quotation->client?->whatsapp)
                        <tr><td class="k">WA</td><td class="v mono">: {{ $quotation->client->whatsapp }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td style="width: 50%; padding-left: 10px;">
                <div class="card">
                    <div class="card-title">Detail Penawaran</div>
                    <table style="width: 100%;">
                        <tr class="detail-row">
                            <td class="k">Tanggal terbit</td>
                            <td class="v mono">{{ tgl_id($quotation->issue_date) }}</td>
                        </tr>
                        <tr class="detail-row">
                            <td class="k">Masa berlaku</td>
                            <td class="v-red mono">{{ tgl_id($quotation->valid_until) }}</td>
                        </tr>
                        @if ($quotation->title)
                        <tr>
                            <td class="k" style="padding-top: 6px;">Perihal</td>
                            <td class="v" style="padding-top: 6px;">{{ $quotation->title }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Items -->
    <table class="items">
        <thead>
            <tr>
                <th class="ctr" style="width: 36px;">No</th>
                <th>Deskripsi</th>
                <th class="ctr" style="width: 44px;">Qty</th>
                <th class="num" style="width: 110px;">Harga Satuan</th>
                <th class="num" style="width: 110px;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quotation->items as $i => $item)
            <tr>
                <td class="ctr mono" style="color: #94a3b8;">{{ $i + 1 }}</td>
                <td class="desc">{{ $item->description }}</td>
                <td class="ctr mono">{{ $item->quantity }}</td>
                <td class="num mono">{{ rupiah($item->unit_price) }}</td>
                <td class="num mono"><strong>{{ rupiah($item->amount) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Notes + totals -->
    <table class="bottom">
        <tr>
            <td style="width: 58%; padding-right: 10px;">
                @if ($quotation->notes)
                <div class="notes-card">
                    <div class="card-title">Catatan</div>
                    <p class="pre-line">{{ $quotation->notes }}</p>
                    <p class="fine">Penawaran ini mengikat hingga masa berlaku berakhir. Harga dapat berubah setelah masa berlaku lewat tanpa konfirmasi tertulis.</p>
                </div>
                @endif
            </td>
            <td style="width: 42%; padding-left: 10px;">
                <div class="totals-card">
                    <table>
                        <tr>
                            <td class="t-label">Subtotal</td>
                            <td class="t-val mono">{{ rupiah($quotation->subtotal) }}</td>
                        </tr>
                        @if ($quotation->discount > 0)
                        <tr>
                            <td class="t-label">Diskon</td>
                            <td class="t-val mono">−{{ rupiah($quotation->discount) }}</td>
                        </tr>
                        @endif
                        <tr class="grand">
                            <td class="t-label">Total</td>
                            <td class="t-val mono">{{ rupiah($quotation->total) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Terms -->
    @if ($quotation->terms)
    <div class="terms-card">
        <div class="card-title">Syarat &amp; Ketentuan</div>
        <p>{{ $quotation->terms }}</p>
    </div>
    @endif

    <!-- Signature -->
    <table class="sign">
        <tr>
            <td style="width: 50%;">
                Disetujui Oleh,
                <div class="spacer"></div>
                <span class="name">{{ $quotation->client?->name ?? '—' }}</span>
            </td>
            <td style="width: 50%; text-align: right;">
                Hormat Kami,
                <div class="spacer"></div>
                <span class="name">{{ $business['name'] }}</span>
            </td>
        </tr>
    </table>

</div>

<!-- Footer -->
<div class="footer">
    <table><tr>
        <td>{{ $business['name'] }} • Salatiga, Jawa Tengah</td>
        <td class="docnum">{{ $quotation->number }}</td>
    </tr></table>
</div>

</body>
</html>
