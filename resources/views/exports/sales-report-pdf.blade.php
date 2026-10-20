<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        h2 { margin-bottom: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
        th { background: #f1f5f9; }
        .summary { margin-top: 10px; }
        .summary td { border: none; padding: 2px 8px; }
    </style>
</head>
<body>
    <h2>Laporan Penjualan Voucher</h2>
    <p>Periode: {{ $filters['from'] ?? '-' }} s/d {{ $filters['to'] ?? '-' }}</p>

    <table class="summary">
        <tr><td>Total Transaksi</td><td>: {{ number_format($summary['total_transaksi']) }}</td></tr>
        <tr><td>Total Pendapatan Penjualan Voucher</td><td>: Rp{{ number_format($summary['total_pendapatan'], 0, ',', '.') }}</td></tr>
        <tr><td>Total Komisi Outlet/Reseller</td><td>: Rp{{ number_format($summary['total_komisi'], 0, ',', '.') }}</td></tr>
        <tr><td><strong>Profit</strong></td><td>: <strong>Rp{{ number_format($summary['hasil_setelah_komisi'], 0, ',', '.') }}</strong></td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>No. Transaksi</th>
                <th>Tanggal</th>
                <th>Outlet</th>
                <th>Voucher</th>
                <th>Total</th>
                <th>Metode</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->transaction_number }}</td>
                    <td>{{ $row->transaction_date->format('d-m-Y H:i') }}</td>
                    <td>{{ $row->outlet?->name ?? 'Stok Pusat' }}</td>
                    <td>{{ $row->voucher?->username }}</td>
                    <td>Rp{{ number_format($row->total_amount, 0, ',', '.') }}</td>
                    <td>{{ $row->payment_method->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
