<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #18181b; }
        h2 { margin-bottom: 2px; }
        .sub { color: #52525b; margin-top: 0; margin-bottom: 14px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { border: none; padding: 1px 8px 1px 0; font-size: 11px; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th, table.list td { border: 1px solid #d4d4d8; padding: 4px 8px; text-align: left; }
        table.list th { background: #f4f4f5; font-size: 10px; text-transform: uppercase; }
        table.list td.no { width: 34px; text-align: right; color: #71717a; }
        table.list td.kode { font-family: monospace; font-size: 11px; }
    </style>
</head>
<body>
    <h2>{{ $batchLabel }}</h2>
    <p class="sub">Daftar Kode Voucher — {{ $ispName }}</p>

    <table class="info">
        <tr>
            <td><strong>Paket</strong></td><td>: {{ $packageName }}</td>
            <td style="padding-left:24px;"><strong>Outlet</strong></td><td>: {{ $outletName }}</td>
        </tr>
        <tr>
            <td><strong>Jumlah</strong></td><td>: {{ $vouchers->count() }} voucher</td>
            <td style="padding-left:24px;"><strong>Dibuat</strong></td><td>: {{ $generatedAt }}</td>
        </tr>
        <tr>
            <td><strong>Kode Batch</strong></td><td colspan="3">: {{ $batchCode }}</td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                <th class="no">No</th>
                <th>Username</th>
                @if ($showPassword)
                    <th>Password</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($vouchers as $i => $v)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td class="kode">{{ $v->username }}</td>
                    @if ($showPassword)
                        <td class="kode">{{ $v->password }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
