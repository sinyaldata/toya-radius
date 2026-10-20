{{--
    Laporan pendapatan outlet (PDF bot Telegram).

    $versiGrup = false (bawaan, /laporanpdf): lengkap -- Pendapatan, Komisi, Profit.
    $versiGrup = true  (/laporanoutlet, untuk dibagikan ke grup WhatsApp outlet):
        LAPORAN PROFIT -- hanya Jumlah Transaksi dan Profit (pendapatan setelah
        komisi). Pendapatan kotor dan Komisi TIDAK ditampilkan: selisih
        keduanya membuka persentase komisi tiap outlet, dan persentase itu
        berbeda-beda per outlet. Urutan outlet diurutkan berdasarkan PROFIT
        (bukan pendapatan kotor) supaya urutannya tidak membocorkan angka kotor.
--}}
@php
    $versiGrup = $versiGrup ?? false;
    $baris = $versiGrup ? $outletBreakdown->sortByDesc('pendapatan_bersih')->values() : $outletBreakdown;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 13px; }
        h2 { margin-bottom: 0; }
        .sub { color: #555; margin-top: 2px; margin-bottom: 20px; }
        table.ringkasan { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.ringkasan td { border: 1px solid #ccc; padding: 10px 14px; }
        table.ringkasan td.label { width: 60%; color: #333; }
        table.ringkasan td.nilai { text-align: right; font-weight: bold; }
        tr.profit td { background: #f1f5f9; font-size: 15px; }
        h3 { margin-top: 28px; margin-bottom: 6px; font-size: 14px; }
        table.rincian { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 11px; }
        table.rincian th, table.rincian td { border: 1px solid #ccc; padding: 5px 8px; }
        table.rincian th { background: #f1f5f9; text-align: left; }
        table.rincian td.angka { text-align: right; }
        table.rincian tfoot td { font-weight: bold; background: #f8fafc; }
    </style>
</head>
<body>
    <h2>{{ $versiGrup ? 'Laporan Profit Outlet' : 'Laporan Pendapatan Outlet Keseluruhan' }}</h2>
    <p class="sub">
        @if ($versiGrup && ! empty($labelPeriode))
            Periode: <strong>{{ $labelPeriode }}</strong> ({{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d/m/Y') }} s/d {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d/m/Y') }})<br>
        @else
            Periode: {{ $filters['from'] ?? '-' }} s/d {{ $filters['to'] ?? '-' }}<br>
        @endif
        Wilayah: {{ $labelWilayah }}
    </p>

    <table class="ringkasan">
        <tr>
            <td class="label">Jumlah Transaksi</td>
            <td class="nilai">{{ number_format($summary['total_transaksi']) }}</td>
        </tr>
        @unless ($versiGrup)
            <tr>
                <td class="label">Pendapatan Total</td>
                <td class="nilai">Rp{{ number_format($summary['total_pendapatan'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Komisi Outlet/Reseller</td>
                <td class="nilai">Rp{{ number_format($summary['total_komisi'], 0, ',', '.') }}</td>
            </tr>
        @endunless
        <tr class="profit">
            <td class="label">Profit</td>
            <td class="nilai">Rp{{ number_format($summary['hasil_setelah_komisi'], 0, ',', '.') }}</td>
        </tr>
    </table>

    <h3>Rincian per Outlet — {{ $labelWilayah }}</h3>
    <table class="rincian">
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Outlet</th>
                <th style="text-align:right;">Jumlah Trx</th>
                @unless ($versiGrup)
                    <th style="text-align:right;">Pendapatan</th>
                    <th style="text-align:right;">Komisi</th>
                @endunless
                <th style="text-align:right;">Profit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $i => $o)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $o['nama'] }}</td>
                    <td class="angka">{{ number_format($o['total_transaksi']) }}</td>
                    @unless ($versiGrup)
                        <td class="angka">Rp{{ number_format($o['pendapatan_total'], 0, ',', '.') }}</td>
                        <td class="angka">Rp{{ number_format($o['komisi'], 0, ',', '.') }}</td>
                    @endunless
                    <td class="angka">Rp{{ number_format($o['pendapatan_bersih'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $versiGrup ? 4 : 6 }}" style="text-align:center; color:#777;">Tidak ada transaksi outlet pada periode &amp; wilayah ini.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($baris->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="2">TOTAL</td>
                    <td class="angka">{{ number_format($summary['total_transaksi']) }}</td>
                    @unless ($versiGrup)
                        <td class="angka">Rp{{ number_format($summary['total_pendapatan'], 0, ',', '.') }}</td>
                        <td class="angka">Rp{{ number_format($summary['total_komisi'], 0, ',', '.') }}</td>
                    @endunless
                    <td class="angka">Rp{{ number_format($summary['hasil_setelah_komisi'], 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
