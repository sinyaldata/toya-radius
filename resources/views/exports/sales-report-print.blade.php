<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Penjualan</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; margin: 0; color: #18181b; }
        .toolbar {
            padding: 10px 14px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1;
            display: flex; justify-content: space-between; align-items: center;
        }
        .toolbar button {
            background: #f97316; color: #fff; border: none; padding: 8px 16px;
            border-radius: 6px; cursor: pointer; font-size: 13px;
        }
        .sheet { max-width: 190mm; margin: 16px auto; padding: 0 8mm; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        p.sub { color: #71717a; margin-top: 0; font-size: 12px; }
        .summary { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 12px; }
        .summary td { padding: 4px 8px; }
        .summary td.label { color: #71717a; width: 45%; }
        .summary td.value { font-weight: bold; text-align: right; }
        .summary tr.highlight td { font-size: 14px; border-top: 1.5px solid #18181b; padding-top: 8px; }
        table.rows { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 10.5px; }
        table.rows th, table.rows td { border: 1px solid #d4d4d8; padding: 5px 6px; text-align: left; }
        table.rows th { background: #f4f4f5; }
        .text-end { text-align: right; }
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <span>{{ $ispName }} — Laporan Penjualan</span>
        <button onclick="window.print()">Cetak Sekarang</button>
    </div>

    <div class="sheet">
        <h1>Laporan Penjualan</h1>
        <p class="sub">
            Periode: {{ $filters['from'] ?? '-' }} s/d {{ $filters['to'] ?? '-' }}
            &middot; Dicetak: {{ now()->format('d M Y H:i') }}
        </p>

        <table class="summary">
            <tr><td class="label">Total Transaksi</td><td class="value">{{ number_format($summary['total_transaksi']) }}</td></tr>
            <tr><td class="label">Total Pendapatan Penjualan Voucher</td><td class="value">Rp{{ number_format($summary['total_pendapatan'], 0, ',', '.') }}</td></tr>
            <tr><td class="label">Total Komisi Outlet/Reseller</td><td class="value">Rp{{ number_format($summary['total_komisi'], 0, ',', '.') }}</td></tr>
            <tr class="highlight"><td class="label">Profit</td><td class="value">Rp{{ number_format($summary['hasil_setelah_komisi'], 0, ',', '.') }}</td></tr>
        </table>

        <table class="rows">
            <thead>
                <tr>
                    <th>No. Transaksi</th>
                    <th>Tanggal</th>
                    <th>Outlet</th>
                    <th>Voucher</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Komisi</th>
                    <th>Metode</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $komisi = $row->outlet ? (float) $row->total_amount * ((float) $row->outlet->commission_percent / 100) : 0;
                    @endphp
                    <tr>
                        <td>{{ $row->transaction_number }}</td>
                        <td>{{ $row->transaction_date->format('d-m-Y H:i') }}</td>
                        <td>{{ $row->outlet?->name ?? 'Stok Pusat' }}</td>
                        <td>{{ $row->voucher?->username }}</td>
                        <td class="text-end">Rp{{ number_format($row->total_amount, 0, ',', '.') }}</td>
                        <td class="text-end">Rp{{ number_format($komisi, 0, ',', '.') }}</td>
                        <td>{{ $row->payment_method->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; color:#a1a1aa;">Tidak ada transaksi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
