<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Pengaturan halaman "walled garden" isolir (yang dilihat PELANGGAN --
 * lihat App\Http\Controllers\IsolirLandingController) + saklar mode
 * isolir PPPoE/Hotspot (App\Services\CustomerRadiusService::suspend()).
 *
 * DUA MODE ISOLIR:
 *  - "Tolak Total" (bawaan, SAMA seperti sebelum fitur ini ada): pelanggan
 *    yang diisolir SAMA SEKALI tidak bisa login/connect. Tidak butuh
 *    apa pun di router selain yang sudah ada.
 *  - "Halaman Isolir" (BARU): pelanggan TETAP BISA connect (dapat IP dari
 *    pool isolir khusus), tapi semua trafik web-nya diarahkan ke halaman
 *    ini. Pelanggan jadi TAHU alasannya (bukan sekadar "gagal connect"),
 *    dan bisa langsung membayar/menghubungi CS dari situ.
 *
 * Mode "Halaman Isolir" BUTUH setup satu kali di tiap router -- lihat
 * scriptMikrotik() di bawah, ditampilkan langsung di halaman ini begitu
 * 3 isian (nama profile, pool, IP server) sudah diisi.
 */
class IsolirSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Halaman Isolir';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Pengaturan Halaman Isolir';

    protected string $view = 'filament.pages.isolir-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'isolir_mode' => Setting::get('isolir_mode', 'reject'),
            'isolir_judul' => Setting::get('isolir_judul') ?: 'Layanan Internet Anda Sedang Tidak Aktif',
            'isolir_pesan' => Setting::get('isolir_pesan') ?: 'Mohon maaf, layanan internet Anda saat ini dihentikan sementara karena tagihan yang belum dibayar. Silakan segera lakukan pembayaran agar internet Anda kembali aktif.',
            'isolir_wa' => Setting::get('isolir_wa'),
            'isolir_rekening' => Setting::get('isolir_rekening'),
            'isolir_profile_mikrotik' => Setting::get('isolir_profile_mikrotik', 'ISOLIR'),
            'isolir_pool_awal' => Setting::get('isolir_pool_awal', '10.99.99.2'),
            'isolir_pool_akhir' => Setting::get('isolir_pool_akhir', '10.99.99.254'),
            'isolir_server_ip' => Setting::get('isolir_server_ip'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mode Isolir')
                ->description('Menentukan apa yang terjadi ke pelanggan PPPoE/Hotspot yang diisolir (manual maupun otomatis karena tunggakan). Pelanggan Static IP tidak terpengaruh pengaturan ini -- caranya beda (lewat address-list per pelanggan di router), lihat catatan di bawah.')
                ->schema([
                    Forms\Components\Radio::make('isolir_mode')
                        ->hiddenLabel()
                        ->options([
                            'reject' => 'Tolak Total (bawaan) -- pelanggan tidak bisa connect sama sekali',
                            'walled_garden' => 'Halaman Isolir -- pelanggan tetap bisa connect, tapi trafik webnya diarahkan ke halaman "mohon bayar tagihan"',
                        ])
                        ->descriptions([
                            'reject' => 'Tidak perlu setup apa pun di router. Ini yang berjalan sekarang.',
                            'walled_garden' => 'Butuh setup SATU KALI di tiap router (profile + pool + NAT) -- isi bagian di bawah, lalu salin script-nya ke router.',
                        ])
                        ->live()
                        ->default('reject'),
                ]),

            Section::make('Isi Halaman Isolir')
                ->description('Yang dilihat pelanggan saat membuka browser dalam kondisi diisolir. Berlaku untuk mode "Halaman Isolir" di atas.')
                ->schema([
                    Forms\Components\TextInput::make('isolir_judul')
                        ->label('Judul')
                        ->maxLength(100),
                    Forms\Components\Textarea::make('isolir_pesan')
                        ->label('Pesan')
                        ->rows(4),
                    Forms\Components\TextInput::make('isolir_wa')
                        ->label('Nomor WhatsApp CS')
                        ->placeholder('mis. 0812-3456-7890 (kosongkan = pakai Kontak ISP di Pengaturan Perusahaan)')
                        ->tel(),
                    Forms\Components\Textarea::make('isolir_rekening')
                        ->label('Info Rekening/Pembayaran (opsional)')
                        ->placeholder('mis. BCA 1234567890 a.n. Nama ISP')
                        ->rows(2),
                ]),

            Section::make('Setup Router (untuk mode "Halaman Isolir")')
                ->description('Isi tiga kolom ini, lalu salin script di bawah ke Terminal MikroTik SATU KALI per router. Setelah itu, mode "Halaman Isolir" di atas siap dipakai untuk router itu.')
                ->schema([
                    Forms\Components\TextInput::make('isolir_profile_mikrotik')
                        ->label('Nama PPP Profile Isolir')
                        ->helperText('Bebas, asal belum dipakai profile lain. Ini yang ditulis aplikasi ke RADIUS (Mikrotik-Group) untuk pelanggan yang diisolir.')
                        ->live()
                        ->default('ISOLIR'),
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('isolir_pool_awal')
                            ->label('IP Pool Isolir -- Awal')
                            ->live()
                            ->default('10.99.99.2'),
                        Forms\Components\TextInput::make('isolir_pool_akhir')
                            ->label('IP Pool Isolir -- Akhir')
                            ->live()
                            ->default('10.99.99.254'),
                    ]),
                    Forms\Components\TextInput::make('isolir_server_ip')
                        ->label('IP Server Aplikasi Ini')
                        ->helperText('IP yang BISA DIJANGKAU router (biasanya IP tunnel VPN-nya, sama seperti yang dipakai isian RADIUS Server di router) -- BUKAN domain. Trafik HTTP pelanggan isolir diarahkan ke sini.')
                        ->placeholder('mis. 10.90.90.1')
                        ->live(),
                ]),

            Section::make('Script Setup MikroTik')
                ->description('Tempel ini di Terminal (New Terminal) router, satu router satu kali. Aman dijalankan berkali-kali (tidak membuat duplikat).')
                ->visible(fn (callable $get) => filled($get('isolir_profile_mikrotik')) && filled($get('isolir_pool_awal')) && filled($get('isolir_pool_akhir')) && filled($get('isolir_server_ip')))
                ->schema([
                    Forms\Components\Placeholder::make('script_preview')
                        ->hiddenLabel()
                        ->content(fn (callable $get) => new \Illuminate\Support\HtmlString(
                            view('filament.resources.nas-resource.script-preview', [
                                'script' => static::scriptMikrotik(
                                    (string) $get('isolir_profile_mikrotik'),
                                    (string) $get('isolir_pool_awal'),
                                    (string) $get('isolir_pool_akhir'),
                                    (string) $get('isolir_server_ip'),
                                ),
                            ])->render()
                        )),
                ]),
        ])->statePath('data');
    }

    /**
     * IP sisi router untuk profile isolir (dipakai sebagai local-address
     * DAN dns-server di PPP Profile -- dns-server di sini cuma memberi tahu
     * pelanggan isolir memakai router ini sebagai resolver BIASA, TANPA
     * jawaban palsu apa pun) -- dihitung otomatis SATU angka di
     * bawah awal pool, konvensi umum jaringan (mis. pool mulai
     * 10.99.99.2, IP router-nya 10.99.99.1). Admin tidak perlu isi field
     * terpisah untuk ini.
     *
     * KENAPA BUKAN IP server aplikasi (KOREKSI 2026-09-27): versi
     * sebelumnya SALAH memakai IP server Laravel di sini -- local-address
     * itu IP milik ROUTER ITU SENDIRI untuk sisi PPP link, bukan server
     * aplikasi yang terpisah. Sudah diperbaiki sebelum sempat dipakai
     * user manapun.
     */
    public static function ipRouterDariPool(string $poolAwal): string
    {
        $bagian = explode('.', trim($poolAwal));

        if (count($bagian) !== 4 || ! ctype_digit($bagian[3])) {
            return $poolAwal;
        }

        $bagian[3] = (string) max(1, ((int) $bagian[3]) - 1);

        return implode('.', $bagian);
    }

    /**
     * Script RouterOS siap-tempel untuk mode "Halaman Isolir": pool khusus,
     * PPP profile isolir, address-list mencakup seluruh pool, dan NAT yang
     * mengarahkan trafik web (port 80) dari pool itu ke server ini.
     *
     * CATATAN PENTING (dijelaskan juga ke user di percakapan, bukan cuma di
     * komentar ini):
     *  - HTTP (port 80) diarahkan LANGSUNG ke halaman ini. HTTPS (mayoritas
     *    situs modern) TIDAK BISA diarahkan ke halaman lain tanpa
     *    sertifikat SSL yang cocok -- ini batasan universal semua walled
     *    garden, bukan cuma di sini. Trafik 443 SENGAJA di-drop, bukan
     *    diarahkan, supaya browser pelanggan gagal cepat.
     *  - TIDAK ADA pengalihan/pembajakan DNS (ditarik 2026-09-28): entri
     *    DNS static "jawab semua domain dengan IP server" berlaku untuk
     *    SEMUA pengguna DNS router itu, bukan cuma pelanggan isolir --
     *    sempat membuat semua pelanggan Hotspot kehilangan internet.
     *    Pelanggan isolir hanya diberi router sebagai resolver DNS biasa.
     *  - "Aman dijalankan berkali-kali": tiap baris pakai pengecekan/
     *    penggantian (bukan "add" polos) supaya tidak membuat duplikat
     *    kalau script ini ditempel ulang.
     */
    public static function scriptMikrotik(string $profil, string $poolAwal, string $poolAkhir, string $serverIp): string
    {
        $namaPool = 'toya-isolir-pool';
        $namaList = 'toya-isolir';
        $ipRouter = static::ipRouterDariPool($poolAwal);

        return <<<SCRIPT
        # ==== Setup Halaman Isolir — Toya Radius ====
        # Aman ditempel ulang -- baris yang sudah ada dilewati/diganti, tidak dobel.

        # 1) Pool IP khusus pelanggan isolir
        /ip pool remove [find name="{$namaPool}"]
        /ip pool add name={$namaPool} ranges={$poolAwal}-{$poolAkhir}

        # 2) PPP Profile isolir -- namanya HARUS SAMA PERSIS dengan isian
        #    "Nama PPP Profile Isolir" di Pengaturan > Halaman Isolir.
        #    local-address & dns-server DIISI IP ROUTER SENDIRI ({$ipRouter},
        #    dihitung otomatis dari awal pool). DNS-nya resolver BIASA --
        #    tidak ada jawaban palsu. (Butuh /ip dns allow-remote-requests=yes
        #    di router; kalau Hotspot Anda sudah memakai DNS router, itu
        #    sudah aktif -- cek dengan: /ip dns print)
        /ppp profile remove [find name="{$profil}"]
        /ppp profile add name={$profil} remote-address={$namaPool} local-address={$ipRouter} dns-server={$ipRouter}

        # (Pembersihan) hapus entri DNS wildcard dari versi script LAMA kalau
        # masih ada -- entri itu bikin semua pengguna DNS router kehilangan internet.
        /ip dns static remove [find comment="toya-isolir-dns"]

        # 3) Address-list mencakup SELURUH pool isolir sekaligus (satu baris,
        #    bukan per-pelanggan -- lebih ringan dan otomatis mencakup
        #    pelanggan baru yang masuk pool ini)
        /ip firewall address-list remove [find list="{$namaList}"]
        /ip firewall address-list add list={$namaList} address={$poolAwal}-{$poolAkhir} comment="Toya Radius - pelanggan isolir"

        # 4) NAT: alihkan trafik HTTP (port 80) pelanggan isolir ke halaman ini.
        #    Ini terjadi DI AWAL alur paket (chain dstnat, sebelum forward),
        #    jadi begitu sampai ke aturan langkah 5, dst-address paketnya
        #    SUDAH menjadi {$serverIp} -- itu sebabnya langkah 5 cukup SATU
        #    aturan saja (tidak perlu dua aturan accept+drop yang urutannya
        #    gampang salah kalau script ini ditempel ulang berkali-kali).
        /ip firewall nat remove [find comment="toya-isolir-nat"]
        /ip firewall nat add chain=dstnat src-address-list={$namaList} protocol=tcp dst-port=80 \\
            action=dst-nat to-addresses={$serverIp} to-ports=80 comment="toya-isolir-nat"

        # 5) Putus SEMUA trafik pelanggan isolir, KECUALI yang barusan
        #    diarahkan ke halaman isolir (dst-address={$serverIp}) --
        #    termasuk HTTPS/443 ikut putus (lihat catatan di aplikasi kenapa
        #    443 tidak bisa diarahkan, cuma bisa diputus). SATU aturan
        #    dengan pengecualian, BUKAN dua aturan terpisah -- supaya tidak
        #    ada risiko urutan tertukar kalau script ini ditempel ulang.
        /ip firewall filter remove [find comment="toya-isolir-drop"]
        /ip firewall filter add chain=forward src-address-list={$namaList} dst-address=!{$serverIp} \\
            action=drop comment="toya-isolir-drop" place-before=0
        SCRIPT;
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ($state as $key => $value) {
            Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : $value, 'isolir');
        }

        Notification::make()->title('Pengaturan Halaman Isolir disimpan')->success()->send();
    }
}
