@php
    // TATA LETAK LEMBAR ditentukan dari nomor template.
    //
    // Template 1-6  : 5 kolom x 20 baris = 100 voucher per lembar A4.
    // Template 7    : 4 kolom x 15 baris = 60 voucher per lembar A4
    //                 (permintaan user) -- kartunya jadi lebih besar
    //                 sehingga kode voucher lebih mudah dibaca &
    //                 digunting.
    //
    // Angka "per lembar" HARUS kelipatan jumlah kolom, kalau tidak
    // baris terakhir jadi tidak penuh dan potongan kertasnya meleset.
    $tyaKolom = $template === 7 ? 4 : 5;
    $tyaPerLembar = $template === 7 ? 60 : 100;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak Voucher — Template {{ $template }}</title>
    <style>
        @page {
            size: A4;
            margin: 6mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .toolbar {
            padding: 10px 14px;
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .toolbar button, .toolbar a.change-template {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            margin-inline-start: 8px;
        }

        .toolbar a.change-template {
            background: #52525b;
        }

        .toolbar span {
            font-size: 13px;
            color: #475569;
        }

        .sheet {
            display: grid;
            grid-template-columns: repeat({{ $tyaKolom }}, 1fr);
            grid-auto-rows: auto;
            gap: 0.6mm;
            width: 100%;
            page-break-after: always;
        }

        .sheet:last-child {
            page-break-after: auto;
        }

        .voucher-card {
            padding: 1mm;
            text-align: center;
            font-size: 5.6pt;
            line-height: 1.05;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            /* min-height:0 + break-inside:avoid -- jaring pengaman kalau
             * SESEKALI ada kartu yang kontennya sedikit lebih tinggi
             * dari biasanya (mis. "U:.../P:..." dua baris saat username
             * beda password): browser tidak memaksakan kartu itu tampil
             * terpotong setengah, tapi mendorongnya UTUH ke halaman
             * berikutnya. RIWAYAT lengkap kenapa akhirnya begini (bukan
             * dipaksa height presisi kayak versi awal) ada di komentar
             * @media print di bawah. */
            min-height: 0;
            break-inside: avoid;
            page-break-inside: avoid;
            /* Jangkar buat nomor urut yang diposisikan absolut di pojok. */
            position: relative;
        }

        /* ---- Nomor urut voucher ----
         * Ditaruh absolut di pojok kanan atas kartu supaya TIDAK ikut
         * mendorong isi kartu ke bawah -- kartunya sempit (sekitar 38x14mm
         * pada tata letak 5 kolom), satu baris tambahan saja sudah bikin
         * harga terpotong.
         *
         * Supaya tidak bertabrakan dengan nama outlet, baris outlet diberi
         * padding kanan seukuran nomor (lihat .v-outlet di bawah). */
        .v-no {
            position: absolute;
            top: 0.5mm;
            inset-inline-end: 0.9mm;
            font-size: 4.4pt;
            font-weight: bold;
            color: #a1a1aa;
            line-height: 1;
        }

        /* ---- Nama perusahaan (penanda penerbit voucher) ----
         * Dicetak di baris paling atas kartu, di ATAS nama outlet, supaya
         * jelas voucher ini terbitan perusahaan mana meski dijual lewat
         * outlet/mitra yang berbeda-beda.
         *
         * Sengaja dibuat huruf kecil renggang & tidak tebal: perannya
         * penanda, bukan informasi utama. Yang harus paling menonjol di
         * kartu tetap KODE voucher — kalau nama perusahaan dibuat besar,
         * kode jadi kalah menonjol dan pembeli kesulitan membacanya. */
        .v-isp {
            font-size: 4.2pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.35px;
            color: #ea580c;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.1;
            /* Ruang untuk nomor urut di pojok kanan atas. */
            padding-inline-end: 4mm;
        }

        .v-outlet {
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            /* Ruang untuk nomor urut di pojok kanan atas. */
            padding-inline-end: 4mm;
        }

        /* Kalau nama perusahaan tercetak, nomor urut TIDAK lagi menempel
         * di baris outlet — jadi padding kanan outlet boleh dilepas dan
         * ruangnya dipakai penuh untuk nama outlet. */
        .v-isp + .v-outlet {
            padding-inline-end: 0;
        }

        .v-kode {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 9pt;
            letter-spacing: 0.3px;
            margin-block: 0.8mm;
        }

        .v-meta {
            font-size: 4.6pt;
            color: #52525b;
        }

        .v-harga {
            font-weight: bold;
            color: #16a34a;
        }

        /* ============ TEMPLATE 1 — Klasik, garis putus-putus ============ */
        .tpl-1 .voucher-card {
            border: 1px dashed #71717a;
            border-radius: 1.5px;
        }

        /* ============ TEMPLATE 2 — Tiket, aksen warna kiri ============ */
        .tpl-2 .voucher-card {
            border: 1px solid #d4d4d8;
            border-left: 2px solid #f97316;
            border-radius: 1.5px;
            text-align: start;
            padding-inline-start: 1.8mm;
        }
        .tpl-2 .v-kode { text-align: center; }

        /* ============ TEMPLATE 3 — Banner ISP, kop warna solid ============ */
        .tpl-3 .voucher-card {
            border: 1px solid #ea580c;
            border-radius: 1.5px;
            padding: 0;
        }
        .tpl-3 .v-outlet {
            background: #f97316;
            color: #fff;
            padding: 0.5mm 0;
            margin-bottom: 0.3mm;
        }
        .tpl-3 .v-produk, .tpl-3 .v-kode, .tpl-3 .v-meta, .tpl-3 .v-harga {
            padding-inline: 1mm;
        }
        .tpl-3 .v-harga { padding-bottom: 0.4mm; display: block; }

        /* ============ TEMPLATE 4 — Kotak tegas, header gelap ============ */
        .tpl-4 .voucher-card {
            border: 1px solid #18181b;
            border-radius: 0;
            padding: 0;
        }
        .tpl-4 .v-outlet {
            background: #18181b;
            color: #fff;
            padding: 0.5mm 0;
            margin-bottom: 0.3mm;
        }
        .tpl-4 .v-produk, .tpl-4 .v-kode, .tpl-4 .v-meta, .tpl-4 .v-harga {
            padding-inline: 1mm;
        }
        .tpl-4 .v-harga { padding-bottom: 0.4mm; display: block; }

        /* Template 3 & 4: bagian atas kartu berupa kop berwarna solid,
         * jadi nomor urutnya jatuh DI ATAS kop itu -- warnanya dibuat
         * terang biar terbaca. Kopnya juga diberi padding kiri-kanan
         * sama besar supaya nama outlet tetap benar-benar di tengah,
         * tidak tergeser oleh ruang nomor. */
        .tpl-3 .v-no, .tpl-4 .v-no {
            color: rgba(255,255,255,0.85);
            top: 0.9mm;
        }
        .tpl-3 .v-outlet, .tpl-4 .v-outlet {
            padding-inline: 4mm;
        }

        /* Baris nama perusahaan diberi latar yang SAMA dengan kop, dan
         * jarak antar keduanya dinolkan — jadi dua elemen terpisah ini
         * terbaca sebagai satu kop utuh, bukan dua pita bertumpuk. */
        .tpl-3 .v-isp {
            background: #f97316;
            color: rgba(255,255,255,0.9);
            padding: 0.4mm 4mm 0;
            margin-bottom: 0;
        }
        .tpl-4 .v-isp {
            background: #18181b;
            color: rgba(255,255,255,0.75);
            padding: 0.4mm 4mm 0;
            margin-bottom: 0;
        }
        .tpl-3 .v-isp + .v-outlet,
        .tpl-4 .v-isp + .v-outlet {
            padding-top: 0.2mm;
            padding-inline: 4mm;
        }

        /* ============ TEMPLATE 5 — Minimalis, tanpa kotak ============ */
        .tpl-5 .voucher-card {
            border: none;
            border-bottom: 0.75pt solid #d4d4d8;
        }
        .tpl-5 .v-kode {
            font-size: 10pt;
        }
        .tpl-5 .v-kode::before, .tpl-5 .v-kode::after {
            content: '– ';
            color: #a1a1aa;
        }
        .tpl-5 .v-kode::after {
            content: ' –';
        }

        /* ============ TEMPLATE 6 — Detail dua kolom ============ */
        .tpl-6 .voucher-card {
            border: 1px solid #d4d4d8;
            border-radius: 1.5px;
            flex-direction: row;
            align-items: center;
            text-align: start;
            gap: 0.8mm;
            padding-inline: 0.8mm;
        }
        .tpl-6 .v-icon {
            flex: 0 0 auto;
            width: 6mm;
            height: 6mm;
            border-radius: 50%;
            background: #f97316;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6pt;
            font-weight: bold;
        }
        .tpl-6 .v-body { flex: 1 1 auto; overflow: hidden; }

        @media print {
            .toolbar { display: none; }

            /* RIWAYAT PERBAIKAN (2026-09-16) -- dibaca urut biar paham
             * kenapa akhirnya jadi seperti ini:
             *
             * 1) Awalnya .sheet dipaksa height:285mm + grid-auto-rows:1fr
             *    supaya 20 baris selalu pas membagi rata satu halaman
             *    A4 persis, tanpa perlu skala cetak dikecilkan manual.
             * 2) Itu bikin kartu yang isinya kebetulan 2 baris (mis.
             *    "U:.../P:...") kepotong ke halaman 2, karena grid item
             *    defaultnya tidak mau menyusut di bawah tinggi kontennya
             *    (makanya voucher-card lalu diberi min-height:0).
             * 3) Supaya kartu yang tidak muat itu pindah UTUH (bukan
             *    kepotong setengah), voucher-card diberi break-inside:
             *    avoid.
             * 4) TERNYATA kombinasi height:285mm (memaksa TINGGI PASTI)
             *    dengan break-inside:avoid (mendorong SEBAGIAN kartu ke
             *    halaman lain) saling tabrakan waktu dirender jadi PDF
             *    sungguhan (beda mesin render dari sekadar preview di
             *    layar) -- hasilnya konten baris pertama halaman
             *    berikutnya tercetak DOBEL/tumpang-tindih.
             *
             * SOLUSI FINAL: lepas height:285mm & grid-auto-rows:1fr
             * (lihat .sheet di atas, sudah diganti "auto") -- biarkan
             * setiap baris mengikuti tinggi kontennya sendiri secara
             * alami, TIDAK dipaksa. Karena kartunya sendiri sudah
             * dirapetkan habis-habisan (padding & line-height kecil),
             * 20 baris itu SECARA ALAMI sudah pas muat satu halaman
             * A4 tanpa perlu dipaksa lagi -- dan break-inside:avoid di
             * voucher-card tetap dipertahankan sebagai jaring pengaman
             * (kalau SESEKALI ada kartu yang sedikit lebih tinggi dari
             * biasanya, dia didorong UTUH ke halaman berikutnya dengan
             * rapi, tanpa membutuhkan height paksa yang tadi terbukti
             * bikin duplikat di PDF).
             */
        }

        /* ---- Template 7: 4 kolom / 60 per lembar ----
         * Ruang per kartu jauh lebih lega (sekitar 48mm x 18mm,
         * dibanding 38mm x 14mm pada tata letak 5 kolom), jadi ukuran
         * hurufnya dinaikkan supaya ruang itu terpakai dan kodenya
         * gampang dibaca dari jarak baca normal. Kartunya juga diberi
         * garis tipis + sudut TEGAS (tanpa lengkung) sebagai panduan
         * gunting. */
        .tpl-7 .voucher-card {
            border: 1px solid #18181b;
            border-radius: 0;
            padding: 2.4mm 2mm;
            font-size: 7pt;
        }

        .tpl-7 .v-outlet {
            font-size: 7.5pt;
            margin-block-end: 0.6mm;
        }

        .tpl-7 .v-kode {
            font-size: 12pt;
            letter-spacing: 0.5px;
            margin-block: 1.2mm;
        }

        .tpl-7 .v-meta {
            font-size: 6.5pt;
        }

        .tpl-7 .v-harga {
            font-size: 8.5pt;
            font-weight: bold;
            margin-block-start: 0.6mm;
        }

        /* Kartu template 7 jauh lebih lega, jadi nomornya ikut dibesarkan
         * supaya seimbang dan tetap gampang dibaca saat menghitung
         * lembaran hasil guntingan. */
        .tpl-7 .v-no {
            font-size: 6.5pt;
            top: 1mm;
            inset-inline-end: 1.4mm;
        }
        .tpl-7 .v-outlet {
            padding-inline-end: 6mm;
        }
        .tpl-7 .v-isp {
            font-size: 5.6pt;
            letter-spacing: 0.5px;
            padding-inline-end: 6mm;
        }
        .tpl-7 .v-isp + .v-outlet {
            padding-inline-end: 0;
        }

        @media screen {
            .sheet {
                max-width: 210mm;
                margin: 10px auto;
                background: #fff;
                box-shadow: 0 0 6px rgba(0,0,0,0.15);
                padding: 8mm;
            }
        }
    </style>
</head>
<body class="tpl-{{ $template }}">
    @php
        // Nomor urut pertama & terakhir yang benar-benar tercetak di
        // lembar ini -- ditampilkan di toolbar supaya user tahu persis
        // sampai nomor berapa sudah dicetak, dan berikutnya harus mulai
        // dari nomor berapa.
        $tyaNomorAwal = $nomorAwal ?? 1;
        $tyaNomorAkhir = $tyaNomorAwal + $vouchers->count() - 1;
        $tyaTotal = $totalTersedia ?? $vouchers->count();
    @endphp

    <div class="toolbar">
        <span>
            Mencetak voucher <strong>no. {{ $tyaNomorAwal }}–{{ $tyaNomorAkhir }}</strong>
            ({{ $vouchers->count() }} lembar) dari total {{ $tyaTotal }} voucher
            — {{ $ispName }} — Template {{ $template }}
            @if ($tyaNomorAkhir < $tyaTotal)
                &middot; sisanya mulai dari no. {{ $tyaNomorAkhir + 1 }}
            @endif
        </span>
        <span>
            <a class="change-template" href="javascript:history.back()">&larr; Ganti Template</a>
            <button onclick="window.print()">Cetak Sekarang</button>
        </span>
    </div>

    {{-- ->values() dipanggil SEBELUM chunk() supaya kuncinya pasti 0,1,2,...
         berurutan. chunk() mempertahankan kunci aslinya, jadi $urutan di
         bawah langsung jadi urutan GLOBAL (bukan urutan di dalam satu
         lembar) tanpa perlu menghitung lembar ke berapa. --}}
    @foreach ($vouchers->values()->chunk($tyaPerLembar) as $page)
        <div class="sheet">
            @foreach ($page as $urutan => $voucher)
                @php
                    $nomorUrut = $tyaNomorAwal + $urutan;

                    $durasiAktif = 'Unlimited';
                    if ($voucher->profile?->limit_type?->value === 'waktu' && $voucher->profile->time_limit_value) {
                        $durasiAktif = $voucher->profile->time_limit_value.' '.($voucher->profile->time_limit_unit?->label() ?? '');
                    }

                    $masaBerlaku = '-';
                    if ($voucher->profile?->validity_days) {
                        $masaBerlaku = $voucher->profile->validity_days.' '.($voucher->profile->validity_unit?->label() ?? 'Hari');
                    }

                    // Nama outlet/mitra penjual. Kalau voucher ini stok
                    // pusat (belum dilempar ke outlet manapun), yang tampil
                    // nama perusahaan itu sendiri.
                    $namaOutlet = $voucher->outlet?->name ?? $ispName;

                    // Baris nama perusahaan hanya dicetak kalau memang BEDA
                    // dengan baris outlet di bawahnya. Untuk voucher stok
                    // pusat keduanya sama persis, dan mencetak nama yang
                    // sama dua kali bertumpuk cuma memakan ruang kartu yang
                    // sudah sempit tanpa menambah keterangan apa pun.
                    $tampilkanIsp = $namaOutlet !== $ispName;
                @endphp

                <div class="voucher-card">
                    <span class="v-no">{{ $nomorUrut }}</span>

                    @if ($template === 6)
                        <div class="v-icon">{{ mb_substr($namaOutlet, 0, 1) }}</div>
                        <div class="v-body">
                            @if ($tampilkanIsp)
                                <div class="v-isp">{{ $ispName }}</div>
                            @endif
                            <div class="v-outlet">{{ $namaOutlet }}</div>
                            <div class="v-kode">
                                @if ($voucher->login_method === 'user_only' || $voucher->username === $voucher->password)
                                    {{ $voucher->username }}
                                @else
                                    {{ $voucher->username }}/{{ $voucher->password }}
                                @endif
                            </div>
                            <div class="v-meta">Aktif {{ $durasiAktif }} &middot; Berlaku {{ $masaBerlaku }}</div>
                            <div class="v-harga">Rp{{ number_format($voucher->sale_price, 0, ',', '.') }}</div>
                        </div>
                    @else
                        @if ($tampilkanIsp)
                            <div class="v-isp">{{ $ispName }}</div>
                        @endif
                        <div class="v-outlet">{{ $namaOutlet }}</div>
                        <div class="v-kode">
                            @if ($voucher->login_method === 'user_only' || $voucher->username === $voucher->password)
                                {{ $voucher->username }}
                            @else
                                U:{{ $voucher->username }}<br>P:{{ $voucher->password }}
                            @endif
                        </div>
                        <div class="v-meta">Aktif {{ $durasiAktif }} &middot; Berlaku {{ $masaBerlaku }}</div>
                        <div class="v-harga">Rp{{ number_format($voucher->sale_price, 0, ',', '.') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
