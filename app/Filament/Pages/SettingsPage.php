<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Perusahaan';

    // Halaman ini KHUSUS pemilik/admin — akun outlet tidak boleh melihat
    // (disembunyikan dari menu DAN diblokir kalau URL-nya diakses langsung).
    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected static ?string $title = 'Pengaturan Perusahaan';

    protected string $view = 'filament.pages.settings-page';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'isp_name' => Setting::get('isp_name'),
            'isp_address' => Setting::get('isp_address'),
            'isp_contact' => Setting::get('isp_contact'),
            'isp_email' => Setting::get('isp_email'),
            'radius_server_host' => Setting::get('radius_server_host'),
            'vpn_server_tunnel_ip' => Setting::get('vpn_server_tunnel_ip', '10.90.90.1'),
            'timezone' => Setting::get('timezone', 'Asia/Jakarta'),
            'logo_path' => Setting::get('logo_path'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Perusahaan')
                    ->description('Nama ISP/RT-RW Net, alamat, kontak, dan email ditampilkan di voucher, invoice, dan laporan.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('isp_name')->label('Nama ISP / RT-RW Net')->required(),
                        Forms\Components\TextInput::make('isp_contact')->label('Kontak (No. HP/WhatsApp)'),
                        Forms\Components\TextInput::make('isp_email')->label('Email')->email(),
                        Forms\Components\Select::make('timezone')
                            ->label('Zona Waktu Server')
                            ->options([
                                'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
                                'Asia/Makassar' => 'WITA (Asia/Makassar)',
                                'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('isp_address')->label('Alamat')->columnSpanFull(),
                    ]),
                Section::make('Alamat Server RADIUS (untuk Script MikroTik)')
                    ->description('Alamat/IP PUBLIC yang bisa dijangkau router pelanggan untuk terhubung ke server RADIUS aplikasi ini — dipakai di script setup MikroTik yang bisa di-copy-paste (menu NAS). PENTING: isi dengan IP Public/domain yang sebenarnya, BUKAN alamat lokal (mis. 192.168.x.x) yang mungkin kamu pakai sendiri untuk buka aplikasi ini dari jaringan kantor/rumah.')
                    ->schema([
                        Forms\Components\TextInput::make('radius_server_host')
                            ->label('IP Public / Domain Server RADIUS')
                            ->placeholder('mis. 103.154.76.124 atau radius.namamu.com'),
                        Forms\Components\TextInput::make('vpn_server_tunnel_ip')
                            ->label('IP Server di Dalam Tunnel VPN')
                            ->placeholder('mis. 10.90.90.1')
                            ->default('10.90.90.1'),
                    ]),
                // GenieACS (URL/login) & Penilaian Redaman ONU (RX) DIPINDAH
                // (permintaan user) ke menu FTTH > GenieACS sendiri --
                // lihat GenieAcsPage::getHeaderActions() ("Pengaturan").
                Section::make('Logo')
                    ->description('Logo ini akan tampil di halaman Login dan pojok kiri atas (Topbar) semua halaman. Kalau tidak diisi, logo bawaan Toya Radius yang dipakai.')
                    ->schema([
                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo Perusahaan')
                            ->image()
                            ->disk('public')
                            ->directory('logos')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->imagePreviewHeight('80'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ($state as $key => $value) {
            Setting::set($key, $value, 'general');
        }

        Notification::make()->title('Pengaturan berhasil disimpan')->success()->send();
    }
}
