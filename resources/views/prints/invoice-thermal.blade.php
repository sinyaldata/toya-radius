<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { margin: 0; size: 58mm auto; }
        body {
            font-family: 'Courier New', monospace;
            width: 58mm;
            padding: 3mm;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .line { border-top: 1px dashed #000; margin: 2mm 0; }
        .row { display: flex; justify-content: space-between; }
        .company-name { font-size: 13px; font-weight: 700; }
        .small { font-size: 9px; }
        table { width: 100%; }
        td { padding: 0.5mm 0; vertical-align: top; }
        .no-print { display: none; }
        .print-btn {
            display: block; width: 100%; margin-bottom: 3mm;
            background: #f97316; color: #fff; border: none;
            padding: 2mm; font-size: 11px; cursor: pointer;
        }
        @media print { .print-btn { display: none; } }
        @media screen {
            body { border: 1px solid #ccc; margin: 1rem auto; }
            .print-btn { display: block; }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">🖨️ Cetak</button>

    <div class="center">
        <div class="company-name">{{ \App\Models\Setting::get('isp_name', config('app.name')) }}</div>
        <div class="small">{{ \App\Models\Setting::get('isp_address') }}</div>
        <div class="small">{{ \App\Models\Setting::get('isp_contact') }}</div>
    </div>

    <div class="line"></div>

    <div class="center bold">INVOICE</div>
    <div class="center small">{{ $invoice->invoice_number }}</div>

    <div class="line"></div>

    <table>
        <tr><td>Pelanggan</td><td class="bold" style="text-align:right;">{{ $invoice->customer?->name }}</td></tr>
        <tr><td>Periode</td><td style="text-align:right;">{{ \Carbon\Carbon::create()->month($invoice->period_month)->translatedFormat('M') }} {{ $invoice->period_year }}</td></tr>
        <tr><td>Jatuh Tempo</td><td style="text-align:right;">{{ $invoice->due_date?->format('d/m/Y') }}</td></tr>
    </table>

    <div class="line"></div>

    <table>
        <tr><td>{{ $invoice->customer?->servicePackage?->name ?? 'Paket Internet' }}</td></tr>
    </table>

    <div class="line"></div>

    <table>
        <tr><td>Total</td><td class="bold" style="text-align:right;">Rp{{ number_format($invoice->amount, 0, ',', '.') }}</td></tr>
        <tr><td>Dibayar</td><td style="text-align:right;">Rp{{ number_format($invoice->amount_paid, 0, ',', '.') }}</td></tr>
    </table>

    <div class="line"></div>

    <table>
        <tr><td class="bold">SISA</td><td class="bold" style="text-align:right;">Rp{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</td></tr>
    </table>

    <div class="line"></div>

    <div class="center bold" style="text-transform:uppercase;">{{ $invoice->status->label() }}</div>

    <div class="line"></div>

    <div class="center small">{{ now()->format('d/m/Y H:i') }}</div>
    <div class="center small">Terima Kasih</div>

    <script>window.onload = () => setTimeout(() => window.print(), 300);</script>
</body>
</html>
