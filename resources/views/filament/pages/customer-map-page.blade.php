{{-- Peta Pelanggan — Leaflet + ubin OpenStreetMap.

     Leaflet dimuat dari CDN. Yang butuh internet adalah BROWSER yang
     membuka halaman ini, bukan servernya. Kalau nanti aplikasi ini
     dipakai di jaringan tanpa internet, unduh leaflet.js/leaflet.css ke
     public/ dan ganti dua tautan di bawah — sisanya tidak perlu diubah. --}}
<x-filament-panels::page>
    @php
        $pelanggan = $this->titikPelanggan();
        $odp = $this->titikOdp();
        $belum = $this->belumBertitik();
        $r = $this->ringkasan();
    @endphp

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        .tya-map-stat { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .tya-map-stat > div { background: #fff; border: 1px solid #e4e4e7; padding: .75rem .9rem; }
        .dark .tya-map-stat > div { background: #27272a; border-color: rgba(255,255,255,.08); }
        .tya-map-stat .lbl { font-size: .65rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #a1a1aa; }
        .tya-map-stat .val { font-size: 1.25rem; font-weight: 800; color: #ea580c; margin-top: .15rem; }

        .tya-map-wrap { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 1024px) { .tya-map-wrap { grid-template-columns: 1fr 20rem; } }

        #tya-peta { height: 68vh; min-height: 420px; width: 100%; border: 1px solid #e4e4e7; }
        .dark #tya-peta { border-color: rgba(255,255,255,.08); }
        #tya-peta-wadah.memasang #tya-peta { cursor: crosshair; }

        /* Icon pin lokasi buat penanda pelanggan -- pastikan tidak ada
           kotak/border bawaan Leaflet di belakang SVG-nya. */
        .tya-pin { background: transparent; border: none; }
        .tya-pin svg { display: block; filter: drop-shadow(0 1px 1.5px rgba(0,0,0,.35)); }

        .tya-panel { border: 1px solid #e4e4e7; background: #fff; display: flex; flex-direction: column; max-height: 68vh; }
        .dark .tya-panel { background: #27272a; border-color: rgba(255,255,255,.08); }
        .tya-panel h3 { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #a1a1aa; padding: .7rem .8rem; border-bottom: 1px solid #f4f4f5; margin: 0; }
        .dark .tya-panel h3 { border-color: rgba(255,255,255,.06); }
        .tya-panel-isi { overflow-y: auto; padding: .35rem; }
        .tya-item { width: 100%; text-align: start; padding: .45rem .55rem; border: none; background: transparent; cursor: pointer; font-size: .76rem; display: block; }
        .tya-item:hover { background: rgba(249,115,22,.08); }
        .tya-item.aktif { background: #f97316; color: #fff; }
        .tya-item .sub { font-size: .66rem; color: #a1a1aa; }
        .tya-item.aktif .sub { color: rgba(255,255,255,.85); }

        .tya-petunjuk { font-size: .72rem; padding: .6rem .8rem; background: rgba(249,115,22,.12); color: #9a3412; font-weight: 600; }
        .tya-legend { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .75rem; font-size: .72rem; color: #52525b; }
        .dark .tya-legend { color: #a1a1aa; }
        .tya-legend span.dot { display: inline-block; width: .7rem; height: .7rem; border-radius: 999px; margin-inline-end: .35rem; vertical-align: middle; }

        .tya-pop b { font-size: .82rem; }
        .tya-pop div { font-size: .72rem; color: #52525b; }
        .tya-pop button { margin-top: .4rem; font-size: .68rem; font-weight: 700; padding: .25rem .5rem; border: 1px solid #fed7aa; background: transparent; color: #ea580c; cursor: pointer; }
    </style>

    <div class="tya-map-stat">
        <div><div class="lbl">Total Pelanggan</div><div class="val">{{ number_format($r['total']) }}</div></div>
        <div><div class="lbl">Sudah Bertitik</div><div class="val">{{ number_format($r['bertitik']) }}</div></div>
        <div><div class="lbl">Belum Bertitik</div><div class="val">{{ number_format($r['tanpaTitik']) }}</div></div>
        <div><div class="lbl">ODP Bertitik</div><div class="val">{{ number_format($r['odp']) }}</div></div>
        <div><div class="lbl">Isolir / Suspend</div><div class="val">{{ number_format($r['isolir']) }}</div></div>
    </div>

    <div class="tya-map-wrap">
        <div>
            @if ($pasangUntuk)
                <div class="tya-petunjuk">
                    Klik posisi rumah pelanggan di peta untuk menyimpan titiknya.
                    <button type="button" wire:click="pilihUntukDipasang(null)"
                            style="margin-inline-start:.5rem;border:none;background:transparent;color:#9a3412;text-decoration:underline;cursor:pointer;font-weight:700;">
                        batal
                    </button>
                </div>
            @endif

            {{-- data-pasang ada di wadah LUAR (ikut dirender ulang Livewire),
                 sedangkan peta di dalamnya wire:ignore supaya Leaflet tidak
                 dibongkar-pasang tiap kali komponen diperbarui. Kalau
                 data-pasang ditaruh di elemen yang wire:ignore, nilainya
                 tidak akan pernah berubah setelah render pertama. --}}
            <div id="tya-peta-wadah" data-pasang="{{ $pasangUntuk ?: '' }}" class="{{ $pasangUntuk ? 'memasang' : '' }}">
                <div id="tya-peta" wire:ignore></div>
            </div>

            <div class="tya-legend">
                <span><span class="dot" style="background:#f97316"></span>Pelanggan aktif</span>
                <span><span class="dot" style="background:#9a3412"></span>Isolir / suspend</span>
                <span><span class="dot" style="background:#18181b"></span>ODP</span>
            </div>
        </div>

        <div class="tya-panel">
            <h3>Belum Bertitik ({{ number_format($r['tanpaTitik']) }})</h3>
            <div class="tya-panel-isi">
                @forelse ($belum as $c)
                    <button type="button"
                            class="tya-item {{ $pasangUntuk === $c->id ? 'aktif' : '' }}"
                            wire:click="pilihUntukDipasang({{ $c->id }})">
                        <div style="font-weight:700;">{{ $c->name }}</div>
                        <div class="sub">{{ $c->username }}{{ $c->address ? ' · '.\Illuminate\Support\Str::limit($c->address, 28) : '' }}</div>
                    </button>
                @empty
                    <div style="padding:1.5rem;text-align:center;color:#a1a1aa;font-size:.78rem;">
                        Semua pelanggan sudah punya titik.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            const pelanggan = @json($pelanggan);
            const odp = @json($odp);
            function gambar() {
                const wadah = document.getElementById('tya-peta');
                if (!wadah || typeof L === 'undefined' || wadah.dataset.siap === '1') return;
                wadah.dataset.siap = '1';

                // Titik awal: rata-rata semua penanda yang ada. Kalau
                // belum ada satu pun, jatuh ke tengah Indonesia supaya
                // peta tidak terbuka di tengah Samudra Atlantik (titik
                // 0,0 — bawaan Leaflet kalau tidak diberi pusat).
                const semua = pelanggan.concat(odp);
                let pusat = [-2.5, 118], zoom = 5;

                if (semua.length) {
                    pusat = [
                        semua.reduce((a, b) => a + b.lat, 0) / semua.length,
                        semua.reduce((a, b) => a + b.lng, 0) / semua.length,
                    ];
                    zoom = 15;
                }

                const peta = L.map(wadah).setView(pusat, zoom);

                // Dua mode tampilan peta: "Jalan" (OpenStreetMap, seperti
                // sebelumnya) dan "Satelit" (citra Esri World Imagery,
                // gratis tanpa API key) -- permintaan user. Defaultnya
                // tetap "Jalan" seperti sebelumnya; pindah ke Satelit
                // lewat tombol lapisan (ikon kotak-kotak) di pojok kanan
                // atas peta.
                const layerJalan = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                });

                const layerSatelit = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                    maxZoom: 19,
                    attribution: 'Tiles &copy; Esri',
                });

                layerJalan.addTo(peta);

                L.control.layers({
                    'Jalan': layerJalan,
                    'Satelit': layerSatelit,
                }).addTo(peta);

                const batas = [];

                // Icon PIN LOKASI buat penanda pelanggan (permintaan
                // user: ganti dari titik/dot polos jadi icon lokasi).
                // SVG dibikin sendiri (bukan gambar dari internet) biar
                // warnanya bisa disesuaikan status pelanggan tanpa perlu
                // beberapa file gambar terpisah.
                function pinIcon(warna) {
                    return L.divIcon({
                        className: 'tya-pin',
                        html: '<svg viewBox="0 0 24 24" width="26" height="26" xmlns="http://www.w3.org/2000/svg">' +
                            '<path fill="' + warna + '" stroke="#fff" stroke-width="1.2" ' +
                            'd="M12 2C7.58 2 4 5.58 4 10c0 5.25 7.25 11.5 7.56 11.76a.75.75 0 0 0 .88 0C12.75 21.5 20 15.25 20 10c0-4.42-3.58-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>' +
                            '</svg>',
                        iconSize: [26, 26],
                        iconAnchor: [13, 26],
                        popupAnchor: [0, -24],
                    });
                }

                odp.forEach(function (o) {
                    L.circleMarker([o.lat, o.lng], {
                        radius: 8, color: '#18181b', weight: 2,
                        fillColor: '#18181b', fillOpacity: .85,
                    }).addTo(peta).bindPopup(
                        '<div class="tya-pop"><b>' + o.nama + '</b>' +
                        '<div>Port: ' + o.terpakai + ' / ' + o.total + ' (sisa ' + o.sisa + ')</div>' +
                        (o.alamat ? '<div>' + o.alamat + '</div>' : '') + '</div>'
                    );
                    batas.push([o.lat, o.lng]);
                });

                pelanggan.forEach(function (p) {
                    const warna = (p.status === 'isolir' || p.status === 'suspended') ? '#9a3412' : '#f97316';

                    L.marker([p.lat, p.lng], { icon: pinIcon(warna) }).addTo(peta).bindPopup(
                        '<div class="tya-pop"><b>' + p.nama + '</b>' +
                        '<div>' + (p.username || '-') + ' &middot; ' + (p.statusLabel || '-') + '</div>' +
                        (p.paket ? '<div>Paket: ' + p.paket + '</div>' : '') +
                        (p.odp ? '<div>ODP: ' + p.odp + '</div>' : '') +
                        (p.telepon ? '<div>HP: ' + p.telepon + '</div>' : '') +
                        (p.alamat ? '<div>' + p.alamat + '</div>' : '') +
                        '<button type="button" onclick="tyaHapusTitik(' + p.id + ')">Hapus titik</button>' +
                        '</div>'
                    );
                    batas.push([p.lat, p.lng]);
                });

                if (batas.length > 1) {
                    peta.fitBounds(batas, { padding: [30, 30] });
                }

                // Klik peta = pasang titik, TAPI hanya kalau sedang ada
                // pelanggan yang dipilih. Tanpa penjagaan ini, klik biasa
                // saat menggeser peta bisa memindahkan pelanggan tanpa
                // disengaja.
                peta.on('click', function (e) {
                    const wadah = document.getElementById('tya-peta-wadah');
                    const untuk = wadah && wadah.dataset.pasang;

                    // Hanya memasang kalau memang ada pelanggan yang
                    // dipilih. Tanpa penjagaan ini, klik biasa saat
                    // menggeser peta bisa memindahkan pelanggan tanpa
                    // disengaja.
                    if (!untuk) return;

                    const lat = Number(e.latlng.lat.toFixed(7));
                    const lng = Number(e.latlng.lng.toFixed(7));

                    // Penanda digambar SEKETIKA. Peta ini wire:ignore
                    // (supaya Leaflet tidak dibongkar tiap render), jadi
                    // Livewire tidak akan menggambar ulang penanda baru --
                    // tanpa baris ini titik yang baru dipasang seolah-olah
                    // tidak tersimpan sampai halaman dimuat ulang.
                    L.marker([lat, lng], { icon: pinIcon('#f97316') }).addTo(peta);

                    wadah.dataset.pasang = '';
                    wadah.classList.remove('memasang');

                    // toFixed(7) menyesuaikan kolom decimal(10,7) di
                    // database; mengirim angka berdesimal 15 cuma akan
                    // dipotong di sisi sana.
                    @this.call('simpanTitik', untuk, lat, lng);
                });
            }

            window.tyaHapusTitik = function (id) {
                @this.call('hapusTitik', id);
            };

            document.addEventListener('DOMContentLoaded', gambar);
            gambar();
        })();
    </script>
</x-filament-panels::page>
