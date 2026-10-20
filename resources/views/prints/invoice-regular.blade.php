<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
            color: #18181b;
            padding: 2.5rem;
            font-size: 13px;
            line-height: 1.5;
        }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 2px solid #18181b; }
        .company-name { font-size: 1.4rem; font-weight: 800; }
        .company-detail { color: #71717a; font-size: 12px; margin-top: 0.25rem; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { font-size: 1.6rem; letter-spacing: 0.05em; color: #f97316; }
        .invoice-number { color: #71717a; font-size: 12px; margin-top: 0.25rem; }
        .status-badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 4px; font-weight: 700; font-size: 11px; text-transform: uppercase; margin-top: 0.5rem; }
        .status-paid { background: #dcfce7; color: #16a34a; }
        .status-unpaid { background: #fee2e2; color: #b91c1c; }
        .status-overdue { background: #fef3c7; color: #92400e; }
        .info-grid { display: flex; justify-content: space-between; margin-bottom: 2rem; }
        .info-block h3 { font-size: 11px; text-transform: uppercase; color: #a1a1aa; margin-bottom: 0.4rem; letter-spacing: 0.03em; }
        .info-block p { font-size: 13px; margin-bottom: 0.15rem; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        table.items th { text-align: left; background: #fafafa; padding: 0.7rem 1rem; font-size: 11px; text-transform: uppercase; color: #71717a; border-bottom: 2px solid #e4e4e7; }
        table.items td { padding: 0.9rem 1rem; border-bottom: 1px solid #f4f4f5; }
        table.items th:last-child, table.items td:last-child { text-align: right; }
        .totals { margin-left: auto; width: 280px; }
        .totals-row { display: flex; justify-content: space-between; padding: 0.5rem 0; font-size: 13px; }
        .totals-row.grand { border-top: 2px solid #18181b; margin-top: 0.5rem; padding-top: 0.8rem; font-weight: 800; font-size: 1.1rem; }
        .footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #e4e4e7; color: #a1a1aa; font-size: 11px; text-align: center; }
        @media print {
            body { padding: 1cm; }
            .no-print { display: none; }
        }
        .print-btn { position: fixed; top: 1rem; right: 1rem; background: #f97316; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 6px; font-size: 13px; cursor: pointer; }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Cetak</button>

    <div class="header">
        <div>
            <div class="company-name">{{ \App\Models\Setting::get('isp_name', config('app.name')) }}</div>
            <div class="company-detail">{{ \App\Models\Setting::get('isp_address') }}</div>
            <div class="company-detail">{{ \App\Models\Setting::get('isp_contact') }}</div>
        </div>
        <div class="invoice-title">
            <h1>INVOICE</h1>
            <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            <div class="status-badge status-{{ $invoice->status->value }}">{{ $invoice->status->label() }}</div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h3>Ditagihkan Kepada</h3>
            <p style="font-weight:700;">{{ $invoice->customer?->name }}</p>
            <p>{{ $invoice->customer?->address }}</p>
            <p>{{ $invoice->customer?->phone }}</p>
        </div>
        <div class="info-block" style="text-align:right;">
            <h3>Detail Tagihan</h3>
            <p>Periode: {{ \Carbon\Carbon::create()->month($invoice->period_month)->translatedFormat('F') }} {{ $invoice->period_year }}</p>
            <p>Jatuh Tempo: {{ $invoice->due_date?->format('d M Y') }}</p>
            @if($invoice->paid_at)
                <p>Dibayar: {{ $invoice->paid_at?->format('d M Y H:i') }}</p>
            @endif
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Deskripsi</th>
                <th>Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Layanan Internet — {{ $invoice->customer?->servicePackage?->name ?? 'Paket Langganan' }}<br>
                    <span style="color:#a1a1aa;font-size:11px;">Periode {{ \Carbon\Carbon::create()->month($invoice->period_month)->translatedFormat('F') }} {{ $invoice->period_year }}</span>
                </td>
                <td>Rp{{ number_format($invoice->amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row">
            <span>Total Tagihan</span>
            <span>Rp{{ number_format($invoice->amount, 0, ',', '.') }}</span>
        </div>
        <div class="totals-row">
            <span>Sudah Dibayar</span>
            <span>Rp{{ number_format($invoice->amount_paid, 0, ',', '.') }}</span>
        </div>
        <div class="totals-row grand">
            <span>Sisa Tagihan</span>
            <span>Rp{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</span>
        </div>
    </div>

    <div class="footer">
        Invoice ini dibuat otomatis oleh sistem — {{ now()->format('d M Y H:i') }}
    </div>

    <script>window.onload = () => setTimeout(() => window.print(), 300);</script>
</body>
</html>
