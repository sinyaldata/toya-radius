<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rincian Pendapatan per Outlet</title>
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
        table.rows { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 11px; }
        table.rows th, table.rows td { border: 1px solid #d4d4d8; padding: 6px 8px; text-align: left; }
        table.rows th { background: #f4f4f5; }
        .text-end { text-align: right; }
        tr.total-row td { font-weight: bold; background: #fafafa; border-top: 2px solid #18181b; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <span>{{ $ispName }} — Rincian Pendapatan per Outlet</span>
        <button onclick="window.print()">Cetak Sekarang</button>
    </div>

    <div class="sheet">
        <h1>Rincian Pendapatan per Outlet</h1>
        <p class="sub">
            Periode: {{ $filters['from'] ?? '-' }} s/d {{ $filters['to'] ?? '-' }}
            &middot; Dicetak: {{ now()->format('d M Y H:i') }}
        </p>

        <table class="rows">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Outlet / Reseller</th>
                    <th class="text-end">Jml Transaksi</th>
                    <th class="text-end">Pendapatan Total</th>
                    <th class="text-end">Komisi</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($outlets as $i => $o)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $o['nama'] }}</td>
                        <td class="text-end">{{ number_format($o['total_transaksi']) }}</td>
                        <td class="text-end">Rp{{ number_format($o['pendapatan_total'], 0, ',', '.') }}</td>
                        <td class="text-end">Rp{{ number_format($o['komisi'], 0, ',', '.') }}</td>
                        <td class="text-end">Rp{{ number_format($o['pendapatan_bersih'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; color:#a1a1aa;">Tidak ada data pada periode ini.</td></tr>
                @endforelse
            </tbody>
            @if ($outlets->isNotEmpty())
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2">TOTAL</td>
                        <td class="text-end">{{ number_format($outlets->sum('total_transaksi')) }}</td>
                        <td class="text-end">Rp{{ number_format($outlets->sum('pendapatan_total'), 0, ',', '.') }}</td>
                        <td class="text-end">Rp{{ number_format($outlets->sum('komisi'), 0, ',', '.') }}</td>
                        <td class="text-end">Rp{{ number_format($outlets->sum('pendapatan_bersih'), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</body>
</html>
