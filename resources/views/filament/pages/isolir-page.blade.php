<x-filament-panels::page>
    @php
        $r = $this->ringkasan();
        $rows = $this->daftar();
        $rp = fn ($n) => 'Rp'.number_format((float) $n, 0, ',', '.');
    @endphp

    <style>
        .tya-iso-stat { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .75rem; }
        .tya-iso-stat > div { background: #fff; border: 1px solid #e4e4e7; padding: .8rem 1rem; position: relative; overflow: hidden; }
        .dark .tya-iso-stat > div { background: #27272a; border-color: rgba(255,255,255,.08); }
        .tya-iso-stat .aks { position: absolute; inset-inline-start: 0; top: 0; bottom: 0; width: 4px; }
        .tya-iso-stat .lbl { font-size: .65rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #a1a1aa; }
        .tya-iso-stat .val { font-size: 1.4rem; font-weight: 800; color: #1c1917; margin-top: .15rem; }
        .dark .tya-iso-stat .val { color: #fafafa; }

        .tya-iso-bar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: 1rem 0 .75rem; }
        .tya-iso-bar input, .tya-iso-bar select {
            padding: .5rem .7rem; border: 1px solid #d4d4d8; font-size: .8rem; outline: none; background: #fff;
        }
        .dark .tya-iso-bar input, .dark .tya-iso-bar select { background: #27272a; border-color: rgba(255,255,255,.12); color: #e4e4e7; }
        .tya-iso-bar input:focus, .tya-iso-bar select:focus { border-color: #f97316; }
        .tya-iso-bar input { flex: 1 1 220px; }

        .tya-iso-tbl { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .tya-iso-tbl th {
            text-align: start; font-size: .62rem; font-weight: 800; letter-spacing: .05em;
            text-transform: uppercase; color: #a1a1aa; padding: .5rem .6rem; border-bottom: 1px solid #e4e4e7;
        }
        .tya-iso-tbl td { padding: .55rem .6rem; border-bottom: 1px solid #f4f4f5; vertical-align: middle; }
        .dark .tya-iso-tbl th { border-color: rgba(255,255,255,.08); }
        .dark .tya-iso-tbl td { border-color: rgba(255,255,255,.05); }
        .tya-iso-tbl tr:hover td { background: rgba(249,115,22,.05); }

        .tya-tag { display: inline-block; font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: .18rem .45rem; }
        .tya-tag-isolir { background: rgba(154,52,18,.14); color: #9a3412; }
        .tya-tag-suspend { background: rgba(249,115,22,.14); color: #c2410c; }
        .tya-tag-nunggak { background: rgba(202,138,4,.14); color: #a16207; }

        .tya-btn-buka {
            font-size: .7rem; font-weight: 700; padding: .35rem .7rem; cursor: pointer;
            background: #f97316; color: #fff; border: none;
        }
        .tya-btn-buka:hover { background: #ea580c; }

        .tya-btn-isolir {
            font-size: .7rem; font-weight: 700; padding: .35rem .7rem; cursor: pointer;
            background: #9a3412; color: #fff; border: none;
        }
        .tya-btn-isolir:hover { background: #7c2d12; }
        .tya-kosong { padding: 2rem; text-align: center; color: #a1a1aa; font-size: .82rem; }
        .tya-scroll { overflow-x: auto; }
    </style>

    <div class="tya-iso-stat">
        <div><span class="aks" style="background:#9a3412"></span><div class="lbl">Isolir (Tunggakan)</div><div class="val">{{ number_format($r['isolir']) }}</div></div>
        <div><span class="aks" style="background:#f97316"></span><div class="lbl">Suspend Manual</div><div class="val">{{ number_format($r['suspend']) }}</div></div>
        <div><span class="aks" style="background:#ea580c"></span><div class="lbl">Total Ditutup</div><div class="val">{{ number_format($r['total']) }}</div></div>
        <div><span class="aks" style="background:#ca8a04"></span><div class="lbl">Menunggak, Belum Diisolir</div><div class="val">{{ number_format($r['menunggak']) }}</div></div>
    </div>

    <div class="tya-iso-bar">
        <input type="text" wire:model.live.debounce.400ms="cari" placeholder="Cari nama, username, atau no. HP...">
        <select wire:model.live="saring">
            <option value="semua">Semua (Isolir + Suspend)</option>
            <option value="isolir">Isolir saja</option>
            <option value="suspend">Suspend saja</option>
            <option value="nunggak">Menunggak (belum diisolir)</option>
        </select>
    </div>

    <div class="tya-scroll">
        <table class="tya-iso-tbl">
            <thead>
                <tr>
                    <th>Pelanggan</th>
                    <th>Sebab</th>
                    <th>Paket</th>
                    <th>ODP</th>
                    <th>Tunggakan</th>
                    <th>Outlet</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $c)
                    @php
                        // Tunggakan dihitung dari dua kolom hasil withSum
                        // (lihat catatan di IsolirPage::daftar()).
                        $tunggakan = (float) ($c->tagihan_total ?? 0) - (float) ($c->tagihan_dibayar ?? 0);
                        $isIsolir = $c->status === \App\Enums\CustomerStatus::Isolir;
                        $isSuspend = $c->status === \App\Enums\CustomerStatus::Suspended;
                        // Sisanya (bukan Isolir & bukan Suspend) berarti
                        // baris dari tab "Menunggak" -- MASIH Aktif.
                    @endphp
                    <tr>
                        <td>
                            <div style="font-weight:700;">{{ $c->name }}</div>
                            <div style="font-size:.68rem;color:#a1a1aa;">{{ $c->username }}{{ $c->phone ? ' · '.$c->phone : '' }}</div>
                        </td>
                        <td>
                            @if ($isIsolir)
                                <span class="tya-tag tya-tag-isolir">Isolir</span>
                            @elseif ($isSuspend)
                                <span class="tya-tag tya-tag-suspend">Suspend</span>
                            @else
                                <span class="tya-tag tya-tag-nunggak">Menunggak</span>
                            @endif
                        </td>
                        <td>{{ $c->servicePackage?->name ?? '-' }}</td>
                        <td>{{ $c->odp?->name ?? '-' }}</td>
                        <td style="font-weight:700;color:{{ $tunggakan > 0 ? '#9a3412' : '#a1a1aa' }};">
                            {{ $tunggakan > 0 ? $rp($tunggakan) : '-' }}
                        </td>
                        <td>{{ $c->outlet?->name ?? '-' }}</td>
                        <td style="text-align:end;">
                            @if ($isIsolir || $isSuspend)
                                <button type="button" class="tya-btn-buka"
                                    wire:click="bukaBlokir({{ $c->id }})"
                                    wire:confirm="Buka blokir {{ $c->name }}? Statusnya jadi Aktif dan blokir di RADIUS dilepas."
                                    wire:loading.attr="disabled">
                                    Buka Blokir
                                </button>
                            @else
                                <button type="button" class="tya-btn-isolir"
                                    wire:click="isolirManual({{ $c->id }})"
                                    wire:confirm="Isolir {{ $c->name }} sekarang? Akses internetnya langsung diputus lewat RADIUS."
                                    wire:loading.attr="disabled">
                                    Isolir Sekarang
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="tya-kosong">
                            @if ($saring === 'nunggak')
                                Tidak ada pelanggan yang menunggak dan belum diisolir.
                            @else
                                Tidak ada pelanggan yang sedang diisolir atau di-suspend.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($rows->count() >= 300)
        <p style="font-size:.7rem;color:#a1a1aa;margin-top:.75rem;">
            Ditampilkan 300 baris pertama. Persempit dengan kotak pencarian di atas.
        </p>
    @endif
</x-filament-panels::page>
