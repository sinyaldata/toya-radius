<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\GenieAcsService;
use App\Services\OnuWifiService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Daftar perangkat ONU dari GenieACS, plus penautan tiap ONU ke
 * pelanggan PPPoE.
 *
 * Datanya dibaca LANGSUNG dari GenieACS setiap halaman dibuka — lihat
 * catatan di GenieAcsService soal kenapa tidak disalin ke database.
 *
 * Pengaturan alamat NBI GenieACS & batas penilaian redaman (RX) DIPINDAH ke
 * sini (permintaan user) dari menu Pengaturan > Perusahaan -- lewat tombol
 * "Pengaturan" (getHeaderActions()), supaya semua yang berkaitan dengan
 * GenieACS ada dalam satu menu.
 */
class GenieAcsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'FTTH';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'GenieACS';

    protected static ?string $title = 'Perangkat ONU (GenieACS)';

    protected string $view = 'filament.pages.genieacs-page';

    public string $cari = '';

    public array $perangkat = [];

    public ?string $galat = null;

    public bool $sudahDimuat = false;

    // ---- Wi-Fi ONU (SSID & kata sandi) ----
    // Properti bertanda Locked TIDAK bisa diubah dari browser: perangkat yang
    // sedang dibuka, daftar antarmukanya, dan jalur dasarnya selalu berasal
    // dari server. Yang boleh diisi pengguna hanya wifiForm (teks SSID /
    // kata sandi), dan jalur parameter yang ditulis ditentukan ulang di
    // server oleh OnuWifiService::terapkan().
    #[Locked]
    public ?string $wifiPerangkat = null;

    #[Locked]
    public ?string $wifiJudul = null;

    #[Locked]
    public array $wifiDaftar = [];

    /** Kapan daftar client terakhir DIAMBIL dari ONU, sudah dalam teks siap tampil (null = belum pernah). */
    #[Locked]
    public ?string $wifiKlienWaktu = null;

    /** @var array<int, array{ssid:string, password:string}> */
    public array $wifiForm = [];

    public ?string $wifiGalat = null;

    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pengaturan')
                ->label('Pengaturan')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Pengaturan GenieACS')
                ->modalSubmitActionLabel('Simpan')
                ->fillForm(fn () => [
                    'genieacs_url' => Setting::get('genieacs_url'),
                    'genieacs_username' => Setting::get('genieacs_username'),
                    'genieacs_password' => Setting::get('genieacs_password'),
                    'redaman_batas_waspada' => Setting::get('redaman_batas_waspada', -22),
                    'redaman_batas_buruk' => Setting::get('redaman_batas_buruk', -24),
                    'redaman_batas_terlalu_kuat' => Setting::get('redaman_batas_terlalu_kuat', -8),
                ])
                ->schema([
                    \Filament\Schemas\Components\Section::make('GenieACS (Manajemen ONU)')
                        ->description('Alamat NBI (Northbound Interface) GenieACS — biasanya port 7557, BUKAN port tampilan web-nya (7567). Kosongkan kalau tidak memakai GenieACS.')
                        ->schema([
                            Forms\Components\TextInput::make('genieacs_url')
                                ->label('URL NBI GenieACS')
                                ->placeholder('mis. http://192.168.1.5:7557'),
                            Forms\Components\TextInput::make('genieacs_username')
                                ->label('Username')
                                ->placeholder('kosongkan kalau GenieACS tanpa login'),
                            Forms\Components\TextInput::make('genieacs_password')
                                ->label('Password')
                                ->password()
                                ->revealable(),
                        ]),
                    \Filament\Schemas\Components\Section::make('Penilaian Redaman ONU (RX)')
                        ->description('Batas untuk label Bagus / Waspada / Buruk di tabel ONU dan perintah /redaman di bot Telegram. Satuan dBm (angka negatif; makin kecil = makin lemah).')
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('redaman_batas_waspada')
                                ->label('Waspada di bawah')
                                ->numeric()
                                ->helperText('Bawaan -22. Lebih lemah dari ini = Waspada.'),
                            Forms\Components\TextInput::make('redaman_batas_buruk')
                                ->label('Buruk di bawah')
                                ->numeric()
                                ->helperText('Bawaan -24. Lebih lemah dari ini = Buruk.'),
                            Forms\Components\TextInput::make('redaman_batas_terlalu_kuat')
                                ->label('Terlalu kuat di atas')
                                ->numeric()
                                ->helperText('Bawaan -8. Lebih kuat dari ini = Terlalu kuat.'),
                        ]),
                ])
                ->action(function (array $data): void {
                    foreach ($data as $key => $value) {
                        Setting::set($key, $value, 'general');
                    }

                    Notification::make()->title('Pengaturan GenieACS disimpan')->success()->send();

                    // Muat ulang daftar perangkat sekarang juga -- kalau URL
                    // baru saja diisi/diubah, tidak perlu refresh halaman.
                    $this->muat();
                }),
        ];
    }

    public function mount(): void
    {
        $this->muat();
    }

    public function muat(): void
    {
        $svc = app(GenieAcsService::class);

        if (! $svc->terkonfigurasi()) {
            $this->galat = 'URL GenieACS belum diisi. Tekan tombol "Pengaturan" di kanan atas untuk mengisinya.';
            $this->perangkat = [];
            $this->sudahDimuat = true;

            return;
        }

        $hasil = $svc->devices($this->cari);

        $this->galat = $hasil['ok'] ? null : $hasil['pesan'];
        $this->perangkat = $hasil['data'];
        $this->sudahDimuat = true;
    }

    /**
     * Pelanggan yang sudah ditautkan, dipetakan acs_device_id => nama.
     * Diambil SEKALI untuk seluruh daftar, bukan per baris.
     */
    public function petaPelanggan(): array
    {
        $ids = array_filter(array_column($this->perangkat, 'id'));

        if (empty($ids)) {
            return [];
        }

        return Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('acs_device_id', $ids)
            ->get(['id', 'name', 'username', 'acs_device_id'])
            ->keyBy('acs_device_id')
            ->map(fn ($c) => ['id' => $c->id, 'nama' => $c->name, 'username' => $c->username])
            ->all();
    }

    /** Pilihan pelanggan untuk dropdown penautan. */
    public function pilihanPelanggan(): array
    {
        return Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', \App\Enums\CustomerType::Pppoe)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'username'])
            ->mapWithKeys(fn ($c) => [$c->id => $c->name.' ('.$c->username.')'])
            ->all();
    }

    public function tautkan(string $deviceId, $customerId): void
    {
        // Satu ONU hanya boleh dimiliki SATU pelanggan. Tanpa pembersihan
        // ini, memindahkan perangkat ke pelanggan lain akan menyisakan
        // dua pelanggan menunjuk ke perangkat yang sama, dan halaman ini
        // akan menampilkan pemilik yang salah tanpa ada yang sadar.
        Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('acs_device_id', $deviceId)
            ->update(['acs_device_id' => null]);

        if ($customerId) {
            Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->whereKey($customerId)
                ->update(['acs_device_id' => $deviceId]);

            Notification::make()->title('Perangkat ditautkan ke pelanggan')->success()->send();
        } else {
            Notification::make()->title('Tautan perangkat dilepas')->success()->send();
        }
    }

    /** Kosongkan kotak pencarian lalu muat ulang seluruh daftar. */
    public function hapusPencarian(): void
    {
        $this->cari = '';
        $this->muat();
    }

    public function bukaWifi(string $deviceId): void
    {
        $data = app(OnuWifiService::class)->muat($deviceId);

        $this->wifiPerangkat = $deviceId;
        $this->wifiGalat = $data['ok'] ? null : $data['pesan'];
        $this->wifiDaftar = $data['wlan'];

        $ringkas = $data['device'] ? app(GenieAcsService::class)->ringkasSatu($data['device']) : null;
        $this->wifiJudul = $ringkas
            ? trim(($ringkas['merek'] ?? '').' '.($ringkas['model'] ?? '')).' / '.($ringkas['serial'] ?? $deviceId)
            : $deviceId;

        $this->wifiKlienWaktu = null;

        if (! empty($data['klien_waktu'])) {
            try {
                $t = \Illuminate\Support\Carbon::parse($data['klien_waktu'])
                    ->timezone((string) (\App\Models\Setting::get('timezone') ?: 'Asia/Jakarta'));

                $this->wifiKlienWaktu = $t->copy()->locale('id')->diffForHumans().' ('.$t->format('d-m-Y H:i').')';
            } catch (\Throwable) {
                $this->wifiKlienWaktu = null;
            }
        }

        $this->wifiForm = [];

        foreach ($this->wifiDaftar as $i => $w) {
            $this->wifiForm[$i] = ['ssid' => $w['ssid'], 'password' => ''];
        }
    }

    public function tutupWifi(): void
    {
        $this->wifiPerangkat = null;
        $this->wifiJudul = null;
        $this->wifiDaftar = [];
        $this->wifiKlienWaktu = null;
        $this->wifiForm = [];
        $this->wifiGalat = null;
    }

    /** Baca ulang data Wi-Fi dari GenieACS (tanpa menutup jendela). */
    public function muatUlangWifi(): void
    {
        if ($this->wifiPerangkat) {
            $this->bukaWifi($this->wifiPerangkat);
        }
    }

    /** Minta ONU mengirim ulang data Wi-Fi-nya ke GenieACS (berlaku saat ONU inform). */
    public function muatDataWifi(): void
    {
        if (! $this->wifiPerangkat) {
            return;
        }

        $acs = app(GenieAcsService::class);
        $data = app(OnuWifiService::class)->muat($this->wifiPerangkat);
        $hasil = $acs->refreshBagian(
            $this->wifiPerangkat,
            $data['device'] ? $acs->objekWifi($data['device']) : 'InternetGatewayDevice.LANDevice'
        );

        $hasil['ok']
            ? Notification::make()->title('Permintaan data Wi-Fi dikirim')->body('Tunggu ONU menghubungi ACS (bisa 1-2 menit atau lebih), lalu tekan "Muat ulang".')->success()->send()
            : Notification::make()->title('Gagal meminta data Wi-Fi')->body((string) $hasil['pesan'])->danger()->send();
    }

    /**
     * Simpan perubahan satu antarmuka Wi-Fi (baris ke-$i pada daftar).
     * SSID hanya dikirim kalau berbeda dari yang sekarang; kata sandi hanya
     * kalau kolom "password baru" diisi.
     */
    public function simpanWifi(int $i): void
    {
        $w = $this->wifiDaftar[$i] ?? null;

        if (! $this->wifiPerangkat || ! $w) {
            return;
        }

        $ssid = trim((string) ($this->wifiForm[$i]['ssid'] ?? ''));
        $sandi = (string) ($this->wifiForm[$i]['password'] ?? '');

        $pelanggan = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('acs_device_id', $this->wifiPerangkat)
            ->first();

        $hasil = app(OnuWifiService::class)->terapkan(
            $this->wifiPerangkat,
            $w['dasar'],
            ($ssid !== '' && $ssid !== $w['ssid']) ? $ssid : null,
            $sandi !== '' ? $sandi : null,
            'aplikasi ('.(Auth::user()?->name ?? 'tidak diketahui').')',
            Auth::id(),
            $pelanggan
        );

        if (! $hasil['ok']) {
            Notification::make()->title('Wi-Fi tidak diubah')->body((string) $hasil['pesan'])->danger()->send();

            return;
        }

        Notification::make()
            ->title($hasil['langsung'] ? 'Wi-Fi ONU diubah' : 'Perubahan Wi-Fi dikirim')
            ->body($hasil['langsung']
                ? 'Sudah diterapkan ke ONU. Perangkat yang tersambung akan terputus dan perlu menyambung ulang.'
                : 'ONU belum bisa dihubungi langsung; perubahan berlaku saat ONU berikutnya menghubungi ACS.')
            ->success()
            ->send();

        $this->muatUlangWifi();
    }

    public function kirimTugas(string $deviceId, string $nama): void
    {
        $hasil = app(GenieAcsService::class)->tugas($deviceId, $nama);

        if ($hasil['ok']) {
            Notification::make()
                ->title($nama === 'reboot' ? 'Perintah reboot dikirim' : 'Permintaan data terbaru dikirim')
                ->body('Perangkat akan menanggapi beberapa saat lagi.')
                ->success()
                ->send();

            return;
        }

        Notification::make()->title('Gagal mengirim perintah')->body($hasil['pesan'])->danger()->send();
    }
}
