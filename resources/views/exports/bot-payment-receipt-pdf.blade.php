{{--
    Struk pembayaran thermal (58mm / 80mm) untuk bot Telegram -- dirender
    dompdf (App\Http\Controllers\TelegramWebhookController::kirimStrukPembayaranBot).

    PERBAIKAN (2026-09-28): struk sebelumnya (1) TERPOTONG kanan -- lebar
    body 58mm DITAMBAH padding 2mm = 62mm > kertas 58mm (dompdf tidak
    mengenal box-sizing), dan kepala printer thermal memang tidak
    mencetak sampai tepi kertas; (2) TERBAGI 2 HALAMAN -- tinggi kertas
    ditebak dari rumus kasar, sekarang DIUKUR dari hasil render (lihat
    ukurTinggiStrukMm()). Elemen #struk dipakai pengukur itu -- jangan
    diganti id-nya.
--}}
@php
    $lebarMm = (float) ($lebarMm ?? 58);
    // Ruang aman kiri/kanan: 58mm -> isi 48mm, 80mm -> isi 72mm (lebar
    // cetak nyata kebanyakan printer thermal).
    $sisiMm = $lebarMm <= 60 ? 5 : 4;
    $kontenMm = $lebarMm - (2 * $sisiMm);
    // Berapa huruf Courier 9px muat dalam satu baris (1 huruf = 0.6em).
    $kapasitas = (int) floor($kontenMm / 1.43);
    // Nilai/label panjang tanpa spasi (mis. nama paket panjang) tidak bisa
    // dipatahkan dompdf -- dipotong manual supaya TIDAK menembus tepi.
    $potong = fn ($teks, $maks) => nl2br(e(wordwrap((string) $teks, max(8, $maks), "\n", true)));
    $maksNilai = $kapasitas - 12;   // kolom kanan (label kiri paling panjang "Pelanggan" + celah)
    $maksLabel = $kapasitas - 12;   // kolom kiri baris tagihan (nominal "Rp150.000" + celah)
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk Pembayaran</title>
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 9px;
            line-height: 1.35;
            color: #000;
        }
        #struk {
            width: {{ $kontenMm }}mm;
            margin-left: {{ $sisiMm }}mm;
            padding-top: 3mm;
            padding-bottom: 3mm;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .line { border-top: 1px dashed #000; margin: 1.5mm 0; height: 0; }
        .company-name { font-size: 11px; font-weight: 700; }
        .small { font-size: 7.5px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0.3mm 0; vertical-align: top; }
        .kiri { text-align: left; }
        .kanan { text-align: right; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
<div id="struk">
    <div class="center">
        <div class="company-name">{{ \App\Models\Setting::get('isp_name', config('app.name')) }}</div>
        <div class="small">{{ \App\Models\Setting::get('isp_address') }}</div>
        <div class="small">{{ \App\Models\Setting::get('isp_contact') }}</div>
    </div>

    <div class="line"></div>

    <div class="center bold">BUKTI PEMBAYARAN</div>
    <div class="center small">{{ $noStruk }}</div>
    <div class="center small">{{ $tanggal }}</div>

    <div class="line"></div>

    <table>
        <tr><td class="kiri nowrap">Pelanggan</td><td class="bold kanan">{!! $potong($pelanggan->name, $maksNilai) !!}</td></tr>
        @if($pelanggan->servicePackage)
            <tr><td class="kiri nowrap">Paket</td><td class="kanan">{!! $potong($pelanggan->servicePackage->name, $maksNilai) !!}</td></tr>
        @endif
        @if($outletName)
            <tr><td class="kiri nowrap">Outlet</td><td class="kanan">{!! $potong($outletName, $maksNilai) !!}</td></tr>
        @endif
    </table>

    <div class="line"></div>

    <table>
        @foreach ($items as $item)
            <tr>
                <td class="kiri">{!! $potong($item['label'], $maksLabel) !!}</td>
                <td class="kanan nowrap">Rp{{ number_format($item['jumlah'], 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </table>

    <div class="line"></div>

    <table>
        <tr><td class="bold kiri nowrap">TOTAL BAYAR</td><td class="bold kanan nowrap">Rp{{ number_format($total, 0, ',', '.') }}</td></tr>
        <tr><td class="kiri nowrap">Metode</td><td class="kanan">{{ $metode }}</td></tr>
    </table>

    @if($sisaTagihan > 0 || $saldoLebih > 0)
        <div class="line"></div>
        <table>
            @if($sisaTagihan > 0)
                <tr><td class="kiri nowrap">Sisa Tunggakan</td><td class="kanan nowrap">Rp{{ number_format($sisaTagihan, 0, ',', '.') }}</td></tr>
            @endif
            @if($saldoLebih > 0)
                <tr><td class="kiri nowrap">Saldo Di Muka</td><td class="kanan nowrap">Rp{{ number_format($saldoLebih, 0, ',', '.') }}</td></tr>
            @endif
        </table>
    @endif

    <div class="line"></div>

    <div class="center small">Terima kasih atas pembayarannya</div>
    <div class="center small">Dicetak otomatis lewat bot Telegram</div>
</div>
</body>
</html>
