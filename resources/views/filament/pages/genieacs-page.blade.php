<x-filament-panels::page>
    <style>
        .tya-acs-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .9rem; }
        .tya-acs-bar input {
            flex: 1 1 240px; padding: .5rem .7rem; border: 1px solid #d4d4d8; font-size: .8rem; outline: none; background: #fff;
        }
        .dark .tya-acs-bar input { background: #27272a; border-color: rgba(255,255,255,.12); color: #e4e4e7; }
        .tya-acs-bar input:focus { border-color: #f97316; }
        .tya-acs-btn {
            font-size: .75rem; font-weight: 700; padding: .5rem .9rem; cursor: pointer;
            background: #f97316; color: #fff; border: none;
        }
        .tya-acs-btn:hover { background: #ea580c; }
        .tya-acs-btn.ghost { background: transparent; color: #ea580c; border: 1px solid #fed7aa; }

        .tya-acs-galat { font-size: .76rem; color: #9a3412; background: rgba(220,38,38,.08); padding: .7rem .9rem; margin-bottom: .9rem; }

        .tya-acs-tbl { width: 100%; border-collapse: collapse; font-size: .76rem; }
        .tya-acs-tbl th {
            text-align: start; font-size: .6rem; font-weight: 800; letter-spacing: .05em;
            text-transform: uppercase; color: #a1a1aa; padding: .5rem .55rem; border-bottom: 1px solid #e4e4e7;
        }
        .tya-acs-tbl td { padding: .5rem .55rem; border-bottom: 1px solid #f4f4f5; vertical-align: middle; }
        .dark .tya-acs-tbl th { border-color: rgba(255,255,255,.08); }
        .dark .tya-acs-tbl td { border-color: rgba(255,255,255,.05); }
        .tya-acs-tbl tr:hover td { background: rgba(249,115,22,.05); }
        .tya-acs-tbl select { padding: .3rem .4rem; font-size: .72rem; border: 1px solid #d4d4d8; background: #fff; max-width: 190px; }
        .dark .tya-acs-tbl select { background: #27272a; border-color: rgba(255,255,255,.12); color: #e4e4e7; }

        .tya-acs-aksi { font-size: .68rem; font-weight: 700; padding: .28rem .55rem; cursor: pointer; border: 1px solid #fed7aa; background: transparent; color: #ea580c; }
        .tya-acs-aksi:hover { background: rgba(249,115,22,.1); }
        .tya-acs-kosong { padding: 2rem; text-align: center; color: #a1a1aa; font-size: .82rem; }
        .tya-scroll { overflow-x: auto; }
        .tya-mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .7rem; }

        /* Jendela Wi-Fi ONU */
        .tya-wf-latar { position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,.55); display: flex; align-items: flex-start; justify-content: center; padding: 3rem 1rem; overflow-y: auto; }
        .tya-wf-kotak { width: 100%; max-width: 560px; background: #fff; border: 1px solid #e4e4e7; box-shadow: 0 20px 50px rgba(0,0,0,.35); }
        .dark .tya-wf-kotak { background: #18181b; border-color: rgba(255,255,255,.1); }
        .tya-wf-kepala { display: flex; align-items: center; justify-content: space-between; gap: .6rem; padding: .8rem 1rem; border-bottom: 1px solid #e4e4e7; font-size: .8rem; }
        .dark .tya-wf-kepala { border-color: rgba(255,255,255,.08); }
        .tya-wf-kartu { padding: .9rem 1rem; border-bottom: 1px solid #f4f4f5; display: grid; gap: .35rem; }
        .dark .tya-wf-kartu { border-color: rgba(255,255,255,.06); }
        .tya-wf-judul { font-weight: 800; font-size: .78rem; }
        .tya-wf-kartu label { font-size: .64rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #a1a1aa; margin-top: .3rem; }
        .tya-wf-kartu input { padding: .5rem .7rem; border: 1px solid #d4d4d8; font-size: .8rem; outline: none; background: #fff; width: 100%; }
        .dark .tya-wf-kartu input { background: #27272a; border-color: rgba(255,255,255,.12); color: #e4e4e7; }
        .tya-wf-kartu input:focus { border-color: #f97316; }
        .tya-wf-pw { display: flex; align-items: center; gap: .6rem; font-size: .8rem; }
        .tya-wf-pw code { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; }
        .tya-wf-nonaktif { font-size: .6rem; font-weight: 700; color: #a1a1aa; border: 1px solid #d4d4d8; padding: .05rem .35rem; margin-inline-start: .4rem; }
        .tya-wf-kaki { display: flex; gap: .5rem; padding: .8rem 1rem; }
        .tya-wf-cat { font-size: .68rem; color: #a1a1aa; line-height: 1.5; padding: 0 1rem .9rem; }
        .tya-wf-klien { width: 100%; border-collapse: collapse; font-size: .72rem; }
        .tya-wf-klien th { text-align: start; font-size: .58rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #a1a1aa; padding: .3rem .4rem; border-bottom: 1px solid #e4e4e7; }
        .tya-wf-klien td { padding: .3rem .4rem; border-bottom: 1px solid #f4f4f5; }
        .dark .tya-wf-klien th { border-color: rgba(255,255,255,.08); }
        .dark .tya-wf-klien td { border-color: rgba(255,255,255,.05); }
    </style>

    <div class="tya-acs-bar">
        <input type="text" wire:model="cari" wire:keydown.enter="muat"
               placeholder="Cari serial number atau ID perangkat, lalu tekan Enter...">
        <button type="button" class="tya-acs-btn" wire:click="muat" wire:loading.attr="disabled">Cari</button>
        <button type="button" class="tya-acs-btn ghost" wire:click="hapusPencarian">Semua</button>
    </div>

    @if ($galat)
        <div class="tya-acs-galat">{{ $galat }}</div>
    @endif

    @php
        $peta = $this->petaPelanggan();
        $pilihan = $this->pilihanPelanggan();
    @endphp

    <div class="tya-scroll">
        <table class="tya-acs-tbl">
            <thead>
                <tr>
                    <th>Serial / Perangkat</th>
                    <th>Merek &amp; Model</th>
                    <th>IP</th>
                    <th>Redaman (RX)</th>
                    <th>User PPPoE</th>
                    <th>Terakhir Online</th>
                    <th>Pelanggan</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($perangkat as $d)
                    @php
                        $terkait = $peta[$d['id']] ?? null;
                        // Warna + label redaman memakai penilaian YANG SAMA dengan
                        // bot Telegram (GenieAcsService::klasifikasiRx) --
                        // batasnya bisa diubah lewat tombol "Pengaturan" di kanan atas.
                        $rx = is_numeric($d['rx_power'] ?? null) ? (float) $d['rx_power'] : null;
                        $rxNilai = app(\App\Services\GenieAcsService::class)->klasifikasiRx($rx);
                        $rxWarna = $rxNilai['warna'];
                    @endphp
                    <tr>
                        <td>
                            <div style="font-weight:700;">{{ $d['serial'] }}</div>
                            <div class="tya-mono" style="color:#a1a1aa;">{{ \Illuminate\Support\Str::limit($d['id'], 34) }}</div>
                        </td>
                        <td>{{ trim(($d['merek'] ?? '-').' '.($d['model'] ?? '')) }}</td>
                        <td class="tya-mono">{{ $d['ip'] ?? '-' }}</td>
                        <td style="font-weight:700;color:{{ $rxWarna }};">
                            {{ $rx !== null ? number_format($rx, 2, ',', '.').' dBm' : '-' }}
                            @if ($rx !== null)
                                <div style="font-size:.72rem;font-weight:600;">{{ $rxNilai['label'] }}</div>
                            @endif
                        </td>
                        <td class="tya-mono">{{ $d['pppoe_user'] ?? '-' }}</td>
                        <td>
                            {{ $d['terakhir'] ? \Illuminate\Support\Carbon::parse($d['terakhir'])->diffForHumans() : '-' }}
                        </td>
                        <td>
                            <select wire:change="tautkan('{{ $d['id'] }}', $event.target.value)">
                                <option value="">— belum ditautkan —</option>
                                @foreach ($pilihan as $id => $label)
                                    <option value="{{ $id }}" @selected($terkait && $terkait['id'] == $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="text-align:end;white-space:nowrap;">
                            <button type="button" class="tya-acs-aksi"
                                wire:click="bukaWifi('{{ $d['id'] }}')">Wi-Fi</button>
                            <button type="button" class="tya-acs-aksi"
                                wire:click="kirimTugas('{{ $d['id'] }}', 'refresh')">Refresh</button>
                            <button type="button" class="tya-acs-aksi"
                                wire:click="kirimTugas('{{ $d['id'] }}', 'reboot')"
                                wire:confirm="Reboot perangkat ini? Sambungan pelanggan akan putus sekitar 1-2 menit.">Reboot</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="tya-acs-kosong">
                            @if ($galat)
                                Data tidak bisa dibaca — lihat pesan di atas.
                            @else
                                Tidak ada perangkat yang cocok.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p style="font-size:.68rem;color:#a1a1aa;margin-top:.9rem;line-height:1.5;">
        Data dibaca langsung dari GenieACS setiap halaman dibuka — tidak ada salinan yang disimpan di aplikasi ini,
        jadi angkanya selalu yang terbaru. Yang tersimpan hanya tautan perangkat ke pelanggan.
        Jalur parameter TR-069 berbeda antar merek ONU; kolom yang kosong berarti merek itu memakai jalur lain
        yang belum dikenali.
    </p>

    @if ($wifiPerangkat)
        <div class="tya-wf-latar" wire:click.self="tutupWifi">
            <div class="tya-wf-kotak">
                <div class="tya-wf-kepala">
                    <div><strong>Wi-Fi ONU</strong> <span class="tya-mono" style="color:#a1a1aa;">{{ $wifiJudul }}</span></div>
                    <button type="button" class="tya-acs-aksi" wire:click="tutupWifi">Tutup</button>
                </div>

                @if ($wifiGalat)
                    <div class="tya-acs-galat" style="margin:.8rem 1rem 0;">{{ $wifiGalat }}</div>
                @endif

                @forelse ($wifiDaftar as $i => $w)
                    <div class="tya-wf-kartu" wire:key="wf-{{ $i }}">
                        <div class="tya-wf-judul">
                            WLAN {{ $w['indeks'] }}@if ($w['band']) &middot; {{ $w['band'] }}@endif
                            @if ($w['aktif'] === false)<span class="tya-wf-nonaktif">nonaktif</span>@endif
                        </div>

                        <label>SSID</label>
                        <input type="text" maxlength="32" autocomplete="off" wire:model="wifiForm.{{ $i }}.ssid">

                        <label>Password saat ini</label>
                        @if ($w['password'] !== null)
                            <div class="tya-wf-pw" x-data="{ tampil: false }">
                                <code x-text="tampil ? @js($w['password']) : '••••••••'"></code>
                                <button type="button" class="tya-acs-aksi" x-on:click="tampil = ! tampil" x-text="tampil ? 'Sembunyikan' : 'Tampilkan'"></button>
                            </div>
                        @else
                            <div class="tya-wf-pw" style="color:#a1a1aa;">
                                Tidak terbaca (disembunyikan ONU){{ $w['password_ditebak'] ? ' — jalur kata sandi belum dikenal, muat data Wi-Fi dulu' : '' }}
                            </div>
                        @endif

                        <label>Password baru <span style="text-transform:none;font-weight:600;">(kosongkan kalau tidak diganti; 8-63 karakter)</span></label>
                        <input type="text" autocomplete="off" wire:model="wifiForm.{{ $i }}.password" placeholder="password Wi-Fi baru">

                        <div>
                            <button type="button" class="tya-acs-btn" wire:click="simpanWifi({{ $i }})" wire:loading.attr="disabled"
                                wire:confirm="Terapkan perubahan Wi-Fi ini? Perangkat yang tersambung akan terputus dan perlu menyambung ulang.">Simpan</button>
                        </div>
                        <label>Client terhubung ({{ count($w['klien'] ?? []) }})</label>
                        @if (! empty($w['klien']))
                            <div class="tya-scroll">
                                <table class="tya-wf-klien">
                                    <thead><tr><th>Nama</th><th>IP</th><th>MAC</th><th>Sinyal</th></tr></thead>
                                    <tbody>
                                        @foreach ($w['klien'] as $k)
                                            <tr>
                                                <td>{{ $k['nama'] ?? '—' }}</td>
                                                <td class="tya-mono">{{ $k['ip'] ?? '-' }}</td>
                                                <td class="tya-mono">{{ $k['mac'] }}</td>
                                                <td>{{ $k['sinyal'] ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="tya-wf-pw" style="color:#a1a1aa;">Tidak ada client, atau datanya belum diambil dari ONU.</div>
                        @endif
                    </div>
                @empty
                    <div class="tya-acs-kosong">
                        @if ($wifiGalat)
                            Data Wi-Fi tidak bisa dibaca.
                        @else
                            GenieACS belum punya data Wi-Fi ONU ini. Tekan &quot;Muat data Wi-Fi dari ONU&quot;, tunggu ONU menghubungi ACS, lalu &quot;Muat ulang&quot;.
                        @endif
                    </div>
                @endforelse

                <div class="tya-wf-kaki">
                    <button type="button" class="tya-acs-btn ghost" wire:click="muatDataWifi">Muat data Wi-Fi dari ONU</button>
                    <button type="button" class="tya-acs-btn ghost" wire:click="muatUlangWifi">Muat ulang</button>
                </div>
                <div class="tya-wf-cat">
                    @if ($wifiKlienWaktu)
                        Daftar client diambil dari ONU {{ $wifiKlienWaktu }} &mdash; tidak real-time; tekan &quot;Muat data Wi-Fi dari ONU&quot; lalu &quot;Muat ulang&quot; untuk memperbarui.
                    @else
                        Data client belum pernah diambil dari ONU &mdash; tekan &quot;Muat data Wi-Fi dari ONU&quot;.
                    @endif
                    <br>
                    Perubahan dikirim ke ONU lewat GenieACS. Kalau ONU tidak bisa dihubungi langsung, perubahan menunggu sampai ONU berikutnya menghubungi ACS.
                    Beberapa ONU sengaja menyembunyikan kata sandi saat dibaca; mengganti kata sandi tetap bisa.
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
