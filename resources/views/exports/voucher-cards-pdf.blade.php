<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $batchLabel }}</title>
    <style>
        {{--
            Kartu voucher siap-cetak-gunting -- gayanya sama seperti
            template cetak yang ada di APLIKASI (menu cetak voucher,
            lihat resources/views/vouchers/print.blade.php), tapi
            ditulis ulang pakai TABLE (bukan CSS grid) karena PDF ini
            dirender lewat dompdf (buat dikirim bot Telegram), dan
            dompdf TIDAK MENDUKUNG "display: grid" -- kalau tetap
            dipaksa grid, semua kartu bakal numpuk jadi satu kolom.
            Table jauh lebih didukung penuh oleh dompdf.

            5 kolom x 10 baris = 50 voucher per lembar A4 (permintaan
            user). CATATAN (fix 2026-09-10): padding/margin/line-height
            di bawah ini sengaja dirapetin -- versi sebelumnya baris
            ke-10 suka kepental sendiri ke halaman berikutnya karena
            tinggi 10 baris kartu (padding+margin+line-height bawaan
            browser/dompdf) dikit lebih tinggi dari muatan satu
            halaman A4, walau logic pembagian per-lembarnya (chunk 50)
            sendiri sudah benar. Kalau nanti mau diubah lagi, jangan
            longgarkan terlalu banyak tanpa dites cetak asli -- gampang
            balik lagi jadi cuma muat 9 baris.
        --}}
        @page { margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 0; }

        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-after: always;
        }
        table.sheet:last-child { page-break-after: auto; }
        table.sheet td { width: 20%; padding: 0.6mm; vertical-align: top; }

        .card {
            border: 1px dashed #71717a;
            padding: 1.2mm 1.3mm;
            text-align: center;
            position: relative;
            line-height: 1.1;
        }
        .v-no {
            position: absolute;
            top: 0.4mm;
            right: 0.8mm;
            font-size: 5.5pt;
            font-weight: bold;
            color: #a1a1aa;
            line-height: 1;
        }
        .v-isp {
            font-size: 5.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #ea580c;
            padding-right: 4mm;
            line-height: 1.1;
        }
        .v-outlet {
            font-size: 7pt;
            font-weight: bold;
            margin-top: 0.2mm;
            padding-right: 4mm;
            line-height: 1.1;
        }
        .v-kode {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 12pt;
            letter-spacing: 0.4px;
            margin: 0.8mm 0;
            line-height: 1.05;
        }
        .v-meta { font-size: 6pt; color: #52525b; line-height: 1.1; }
        .v-harga { font-weight: bold; color: #16a34a; font-size: 8.5pt; margin-top: 0.4mm; line-height: 1.1; }
    </style>
</head>
<body>
    @php
        $kolom = 5;
        $perLembar = 50;
        // Nomor urut dihitung pakai PENCACAH manual ($nomorCounter),
        // BUKAN dari kunci koleksi -- chunk() (baik chunk luar per
        // lembar maupun chunk dalam per baris) mempertahankan kunci
        // ASLI tiap item, jadi begitu masuk lembar ke-2 dst kuncinya
        // sudah bukan 0,1,2,... lagi. Kalau tetap dipakai buat hitung
        // nomor urut, lembar ke-2 dan seterusnya jadi salah semua.
        $nomorCounter = 0;
    @endphp

    @foreach ($vouchers->values()->chunk($perLembar) as $halaman)
        <table class="sheet">
            <tbody>
                @foreach ($halaman->values()->chunk($kolom) as $baris)
                    <tr>
                        @foreach ($baris as $voucher)
                            @php $nomorCounter++; @endphp
                            <td>
                                <div class="card">
                                    <span class="v-no">{{ $nomorCounter }}</span>

                                    @if ($tampilkanIsp)
                                        <div class="v-isp">{{ $ispName }}</div>
                                    @endif
                                    <div class="v-outlet">{{ $outletName }}</div>
                                    <div class="v-kode">
                                        @if ($showPassword)
                                            U:{{ $voucher->username }}<br>P:{{ $voucher->password }}
                                        @else
                                            {{ $voucher->username }}
                                        @endif
                                    </div>
                                    <div class="v-meta">Aktif {{ $durasiAktif }} &middot; Berlaku {{ $masaBerlaku }}</div>
                                    <div class="v-harga">Rp{{ number_format($voucher->sale_price, 0, ',', '.') }}</div>
                                </div>
                            </td>
                        @endforeach
                        @for ($i = $baris->count(); $i < $kolom; $i++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
