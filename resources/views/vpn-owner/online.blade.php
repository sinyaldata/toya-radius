<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Online per Tenant — VPN Owner Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vpn-owner.partials.styles')
</head>
<body>
<div class="tya-app">
    @include('vpn-owner.partials.sidebar', ['active' => 'online'])
    <div class="tya-main">
    @include('vpn-owner.partials.topbar', ['active' => 'online'])

    @php
        // Satu sel angka: hijau kalau > 0, abu-abu kalau 0; "–" kalau tidak diketahui.
        $sel = function ($nilai, bool $galat = false) {
            if ($nilai === null || $galat) {
                return '<span class="tya-badge gray" title="Belum bisa dihitung">–</span>';
            }

            return '<span class="tya-badge '.($nilai > 0 ? 'green' : 'gray').'">'.number_format((int) $nilai, 0, ',', '.').'</span>';
        };
    @endphp

    @php
        // ===== Versi kartu (HP) -- data sama persis dengan tabel di bawah =====
        $tyaAngka = fn ($n, bool $galat = false) => ($n === null || $galat) ? '–' : number_format((int) $n, 0, ',', '.');
        $tyaIkon = [
            'hotspot' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.807 9.98-3.807 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12 18.75h.008v.008H12v-.008z"/></svg>',
            'pppoe' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2.3"/><circle cx="5" cy="19" r="2.3"/><circle cx="19" cy="19" r="2.3"/><path d="M12 7.3v4.4M12 11.7l-5.4 5.6M12 11.7l5.4 5.6"/></svg>',
            'static' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="1.6"/><path d="M8 20h8M12 16v4"/></svg>',
            'total' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="7.8" r="3.3"/><path d="M2.4 19.5c0-3.7 2.9-6.3 6.6-6.3s6.6 2.6 6.6 6.3z"/><circle cx="17.2" cy="8.8" r="2.6"/><path d="M16.4 13.2c3.2.2 5.2 2.5 5.2 6.3h-4.5c0-2.5-.3-4.6-.7-6.3z"/></svg>',
        ];
        $tyaUbin = function (array $r) use ($tyaAngka, $tyaIkon) {
            $isi = [
                ['hotspot', 'Hotspot', $tyaAngka($r['hotspot'] ?? null, $r['galat'] ?? false)],
                ['pppoe', 'PPPoE', $tyaAngka($r['pppoe'] ?? null, $r['galat'] ?? false)],
                ['static', 'Static IP', $tyaAngka($r['static'] ?? null)],
                ['total', 'Total', $tyaAngka($r['total'] ?? null)],
            ];
            $html = '<div class="tya-on-tiles">';
            foreach ($isi as [$k, $label, $nilai]) {
                $html .= '<div class="tya-on-tile"><span class="ik '.$k.'">'.$tyaIkon[$k].'</span><span class="tx"><small>'.$label.'</small><b>'.$nilai.'</b></span></div>';
            }

            return $html.'</div>';
        };
        $tyaBaris = collect([[
            'name' => $mainName, 'domain' => $mainDomain, 'status' => 'active',
        ] + $utama])->merge($tenants->map(fn ($t) => $t + ['domain' => $t['subdomain'].'.'.$mainDomain]));
    @endphp

    <div class="tya-card tya-on-card">
        <div class="tya-on-m">
            <div class="tya-on-head">
                <span class="tya-on-led"></span>
                <div class="tya-on-title">
                    <h2>User Online Sekarang</h2>
                    <small>Semua tenant</small>
                </div>
                <button type="button" class="tya-on-refresh" aria-label="Muat ulang" title="Muat ulang angka" onclick="this.classList.add('putar'); location.reload();">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 00-14.9-3.5M4 4v3.5h3.5M4 13a8 8 0 0014.9 3.5M20 20v-3.5h-3.5"/></svg>
                </button>
            </div>

            <div class="tya-on-list">
                @foreach ($tyaBaris as $b)
                    <a class="tya-on-item" href="https://{{ $b['domain'] }}/admin" target="_blank" rel="noopener" data-tya-cari>
                        <div class="tya-on-top">
                            <span class="tya-on-bld">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4.5A1.5 1.5 0 016.5 3h7A1.5 1.5 0 0115 4.5V21M15 9h3.5A1.5 1.5 0 0120 10.5V21M3 21h18M8.5 7h1M11 7h1M8.5 10.5h1M11 10.5h1M8.5 14h1M11 14h1M9.5 21v-3h1.5v3"/></svg>
                            </span>
                            <span class="tya-on-name">
                                <strong>{{ $b['name'] }}</strong>
                                <span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>
                                    {{ $b['domain'] }}
                                </span>
                            </span>
                            <span class="tya-on-chip {{ $b['status'] === 'active' ? 'green' : 'amber' }}"><i></i>{{ $b['status'] }}</span>
                            <span class="tya-on-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg></span>
                        </div>
                        {!! $tyaUbin($b) !!}
                    </a>
                @endforeach

                <div class="tya-on-item tya-on-sum">
                    <div class="tya-on-top">
                        <span class="tya-on-sumic">{!! $tyaIkon['total'] !!}</span>
                        <span class="tya-on-name"><strong>Jumlah semua</strong></span>
                    </div>
                    {!! $tyaUbin($jumlah) !!}
                </div>
            </div>
            <p class="tya-on-note">Tanda "–" = belum bisa dirinci (mis. tenant tidak aktif). Ketuk kartu untuk membuka panel tenant.</p>
        </div>

        <div class="tya-on-desk">
        <div class="tya-card-head">
            <h2>🟢 User Online Sekarang — Semua Tenant</h2>
        </div>
        <div class="tya-table-wrap">
            <table class="tya-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Subdomain</th>
                        <th>Status</th>
                        <th>Hotspot</th>
                        <th>PPPoE</th>
                        <th>Static IP</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-label="Tenant"><strong>{{ $mainName }}</strong></td>
                        <td data-label="Subdomain">{{ $mainDomain }}</td>
                        <td data-label="Status"><span class="tya-badge green">active</span></td>
                        <td data-label="Hotspot">{!! $sel($utama['hotspot'], $utama['galat']) !!}</td>
                        <td data-label="PPPoE">{!! $sel($utama['pppoe'], $utama['galat']) !!}</td>
                        <td data-label="Static IP">{!! $sel($utama['static']) !!}</td>
                        <td data-label="Total"><strong>{!! $sel($utama['total']) !!}</strong></td>
                    </tr>
                    @foreach ($tenants as $t)
                        <tr>
                            <td data-label="Tenant">{{ $t['name'] }}</td>
                            <td data-label="Subdomain">{{ $t['subdomain'] }}.{{ $mainDomain }}</td>
                            <td data-label="Status"><span class="tya-badge {{ $t['status'] === 'active' ? 'green' : 'amber' }}">{{ $t['status'] }}</span></td>
                            <td data-label="Hotspot">{!! $sel($t['hotspot'], $t['galat']) !!}</td>
                            <td data-label="PPPoE">{!! $sel($t['pppoe'], $t['galat']) !!}</td>
                            <td data-label="Static IP">{!! $sel($t['static']) !!}</td>
                            <td data-label="Total"><strong>{!! $sel($t['total']) !!}</strong></td>
                        </tr>
                    @endforeach
                    <tr style="font-weight:700;">
                        <td colspan="3">Jumlah semua</td>
                        <td data-label="Hotspot">{!! $sel($jumlah['hotspot']) !!}</td>
                        <td data-label="PPPoE">{!! $sel($jumlah['pppoe']) !!}</td>
                        <td data-label="Static IP">{!! $sel($jumlah['static']) !!}</td>
                        <td data-label="Total">{!! $sel($jumlah['total']) !!}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="tya-hint" style="margin-bottom:0;">
            Refresh halaman ini buat lihat angka terbaru — belum otomatis update sendiri.<br>
            <strong>Hotspot</strong> = voucher + member Hotspot. <strong>PPPoE</strong> = pelanggan bertipe PPPoE.
            <strong>Static IP</strong> = pelanggan Static IP yang lolos ARP-ping terakhir (dicek tiap 5 menit; yang belum dicek lebih dari 20 menit tidak dihitung).
            <strong>Total</strong> = ketiganya, ditambah sesi tenant yang belum bisa dirinci (tanda "–", mis. tenant tidak aktif).
        </p>
        </div> {{-- /.tya-on-desk --}}
    </div>
    </div> {{-- /.tya-content --}}
    </div> {{-- /.tya-main --}}
</div> {{-- /.tya-app --}}
@include('vpn-owner.partials.scripts')
</body>
</html>
