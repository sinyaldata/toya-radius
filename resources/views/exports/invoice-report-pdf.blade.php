<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 9px; }
        h2 { margin-bottom: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 3px 5px; }
        th { background: #f1f5f9; text-align: center; }
        td { text-align: right; }
        td.nama { text-align: left; }
        tfoot td { font-weight: bold; background: #f1f5f9; }
    </style>
</head>
<body>
    <h2>Laporan Invoice</h2>
    <p>Tahun: {{ $tahun }}</p>

    <table>
        <thead>
            <tr>
                <th>No.</th>
                <th style="text-align:left">Nama Pelanggan</th>
                <th style="text-align:left">Outlet</th>
                @foreach ($bulanLabel as $label)
                    <th>{{ $label }}</th>
                @endforeach
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($matrix as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="nama">{{ $row['customer']->name }}</td>
                    <td class="nama">{{ $row['customer']->outlet->name ?? '-' }}</td>
                    @foreach ($row['bulanan'] as $nominal)
                        <td>{{ $nominal !== null ? number_format($nominal, 0, ',', '.') : '-' }}</td>
                    @endforeach
                    <td>{{ number_format($row['total'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="16" style="text-align:center">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">TOTAL</td>
                @foreach ($monthlyTotals as $total)
                    <td>{{ number_format($total, 0, ',', '.') }}</td>
                @endforeach
                <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
