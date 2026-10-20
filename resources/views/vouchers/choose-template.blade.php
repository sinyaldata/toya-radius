<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pilih Template Cetak Voucher</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            background: #f4f4f5;
            margin: 0;
            padding: 24px;
            color: #18181b;
        }
        h1 { font-size: 20px; margin-bottom: 4px; }
        p.sub { color: #71717a; margin-top: 0; margin-bottom: 24px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            max-width: 1100px;
        }
        .card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 12px;
            padding: 16px;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
        }
        .card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            transform: translateY(-2px);
            border-color: #f97316;
        }
        .preview {
            height: 70px;
            border-radius: 6px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 11px;
            color: #52525b;
        }
        .p1 { border: 2px dashed #a1a1aa; background: #fafafa; }
        .p2 { border-left: 5px solid #f97316; background: #fafafa; }
        .p3 { background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; }
        .p4 { border: 2px solid #18181b; background: #fafafa; }
        .p5 { background: #fff; }
        .p6 { background: #fafafa; border: 1px solid #d4d4d8; }
        /* Pratinjau template 7: digambar sebagai kisi 4 kolom supaya
         * langsung kelihatan bedanya dengan template lain (yang 5
         * kolom). */
        .p7 {
            background: #fff;
            border: 1px solid #18181b;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2px;
            padding: 4px;
        }
        .p7 i {
            background: #f4f4f5;
            border: 1px solid #d4d4d8;
            display: block;
            height: 100%;
        }
        /* ---- Panel jumlah cetak ---- */
        .opsi {
            max-width: 1100px;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .opsi h2 { font-size: 14px; margin: 0 0 2px; }
        .opsi .ket { font-size: 12px; color: #71717a; margin: 0 0 14px; }
        .opsi-baris {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .opsi-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #71717a;
            margin-bottom: 5px;
        }
        .opsi-field input {
            width: 130px;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid #d4d4d8;
            border-radius: 6px;
            outline: none;
        }
        .opsi-field input:focus { border-color: #f97316; }
        .preset { display: flex; flex-wrap: wrap; gap: 6px; }
        .preset button {
            background: #fafafa;
            border: 1px solid #d4d4d8;
            border-radius: 6px;
            padding: 7px 11px;
            font-size: 12px;
            font-weight: 600;
            color: #3f3f46;
            cursor: pointer;
        }
        .preset button:hover { border-color: #f97316; color: #ea580c; }
        .rangkuman {
            margin-top: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #ea580c;
            background: rgba(249,115,22,.1);
            padding: 8px 11px;
            border-radius: 6px;
        }
        .rangkuman.bahaya { color: #b91c1c; background: rgba(220,38,38,.1); }

        .card-title { font-weight: 700; font-size: 14px; margin-bottom: 2px; }
        .card-desc { font-size: 12px; color: #71717a; }
        .info {
            max-width: 1100px;
            margin-top: 20px;
            font-size: 13px;
            color: #71717a;
        }
    </style>
</head>
<body>
    <h1>Pilih Template Voucher</h1>
    <p class="sub">{{ $count }} voucher akan dicetak. Template 1-6 memuat 100 voucher per lembar A4 (5 kolom), template 7 memuat 60 voucher per lembar (4 kolom, kartu lebih besar). Klik salah satu template untuk langsung cetak.</p>

    {{-- Panel jumlah cetak. Nilainya TIDAK dikirim lewat form, tapi
         ditempelkan ke alamat tiap kartu template saat diklik (lihat
         skrip di bawah) -- supaya kartu template tetap berupa tautan
         biasa yang membuka tab baru, sama seperti sebelumnya. --}}
    <div class="opsi">
        <h2>Jumlah yang Dicetak</h2>
        <p class="ket">
            Tersedia <strong>{{ $count }}</strong> voucher. Kosongkan "Jumlah" kalau mau
            mencetak semuanya. Setiap voucher yang tercetak diberi nomor urut di pojok
            kanan atas kartu.
        </p>

        <div class="opsi-baris">
            <div class="opsi-field">
                <label for="mulai">Mulai dari nomor</label>
                <input type="number" id="mulai" min="1" max="{{ $count }}" value="1" placeholder="1">
            </div>

            <div class="opsi-field">
                <label for="jumlah">Jumlah (pcs)</label>
                <input type="number" id="jumlah" min="1" max="{{ $count }}" placeholder="semua">
            </div>

            <div class="opsi-field">
                <label>Pilihan cepat</label>
                <div class="preset">
                    @foreach ([50, 60, 70, 80, 100, 200] as $n)
                        <button type="button" data-jumlah="{{ $n }}">{{ $n }} pcs</button>
                    @endforeach
                    <button type="button" data-jumlah="">Semua</button>
                </div>
            </div>
        </div>

        <div class="rangkuman" id="rangkuman"></div>
    </div>

    <div class="grid">
        @foreach ($templates as $id => $label)
            <a class="card" href="{{ route('vouchers.print', array_merge($params, ['template' => $id])) }}" target="_blank">
                <div class="preview p{{ $id }}">
                    @if ($id === 3)
                        VOUCHER
                    @elseif ($id === 5)
                        —— TYA1234 ——
                    @elseif ($id === 7)
                        @for ($i = 0; $i < 12; $i++)<i></i>@endfor
                    @else
                        TYA1234
                    @endif
                </div>
                <div class="card-title">Template {{ $id }}</div>
                <div class="card-desc">{{ $label }}</div>
            </a>
        @endforeach
    </div>

    <p class="info">Setiap template menampilkan: nama Outlet/Mitra, kode voucher, waktu dibuat, dan masa aktif voucher. Halaman cetak akan terbuka di tab baru.</p>

    <script>
        (function () {
            var TOTAL = {{ $count }};
            var elMulai = document.getElementById('mulai');
            var elJumlah = document.getElementById('jumlah');
            var elRangkuman = document.getElementById('rangkuman');

            function angka(el, bawaan) {
                var n = parseInt(el.value, 10);
                return (isNaN(n) || n < 1) ? bawaan : n;
            }

            // Menghitung rentang yang benar-benar akan tercetak. Dibuat
            // satu fungsi supaya keterangan di layar dan alamat yang
            // dibuka SELALU memakai perhitungan yang sama -- kalau
            // dipisah, keduanya gampang jadi tidak sinkron.
            function hitung() {
                var mulai = Math.min(angka(elMulai, 1), TOTAL);
                var sisa = TOTAL - mulai + 1;
                var diminta = angka(elJumlah, 0);
                var jumlah = (diminta > 0) ? Math.min(diminta, sisa) : sisa;

                return {
                    mulai: mulai,
                    jumlah: jumlah,
                    akhir: mulai + jumlah - 1,
                    dipotong: diminta > 0 && diminta > sisa,
                };
            }

            function perbarui() {
                var h = hitung();

                elRangkuman.className = 'rangkuman' + (h.dipotong ? ' bahaya' : '');
                elRangkuman.textContent = h.dipotong
                    ? 'Sisa voucher dari nomor ' + h.mulai + ' cuma ' + h.jumlah +
                      ' lembar, jadi yang tercetak nomor ' + h.mulai + '–' + h.akhir + '.'
                    : 'Akan tercetak ' + h.jumlah + ' lembar, bernomor ' +
                      h.mulai + '–' + h.akhir + ' dari ' + TOTAL + ' voucher.';
            }

            elMulai.addEventListener('input', perbarui);
            elJumlah.addEventListener('input', perbarui);

            document.querySelectorAll('.preset button').forEach(function (b) {
                b.addEventListener('click', function () {
                    elJumlah.value = b.dataset.jumlah;
                    perbarui();
                });
            });

            // Tempelkan pilihan jumlah ke alamat template SAAT DIKLIK,
            // bukan saat halaman dimuat -- supaya user masih bisa
            // mengubah angkanya berkali-kali tanpa alamatnya jadi
            // menumpuk parameter lama.
            document.querySelectorAll('a.card').forEach(function (a) {
                var dasar = a.getAttribute('href');

                a.addEventListener('click', function () {
                    var h = hitung();
                    var pemisah = dasar.indexOf('?') === -1 ? '?' : '&';

                    a.setAttribute(
                        'href',
                        dasar + pemisah + 'mulai=' + h.mulai + '&jumlah=' + h.jumlah
                    );
                });
            });

            perbarui();
        })();
    </script>
</body>
</html>
