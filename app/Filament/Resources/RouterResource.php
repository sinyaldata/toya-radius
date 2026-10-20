<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RouterResource\Pages;
use App\Models\Router;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class RouterResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Router::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'MikroTik';

    protected static ?int $navigationSort = 1;

    /**
     * Menu "MikroTik" terpisah SUDAH DIGABUNG ke menu "NAS" (lihat
     * NasResource) — disembunyikan dari sidebar di sini, tapi class ini
     * tetap ada (dipakai NasResource untuk create/update Router lewat
     * relasinya, dan halaman edit-nya masih valid kalau ada link lama).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'MikroTik';
    }

    public static function getPluralModelLabel(): string
    {
        return 'MikroTik';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Informasi Router')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Router')
                        ->prefixIcon('heroicon-o-server')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('ip_address')
                        ->label('IP Address')
                        ->prefixIcon('heroicon-o-globe-alt')
                        ->required()
                        ->ip(),
                    Forms\Components\TextInput::make('api_port')
                        ->label('API Port')
                        ->prefixIcon('heroicon-o-hashtag')
                        ->numeric()
                        ->default(8728)
                        ->required(),
                    Forms\Components\Toggle::make('use_ssl')
                        ->label('Gunakan SSL (API-SSL, port 8729)'),
                    Forms\Components\TextInput::make('username')
                        ->prefixIcon('heroicon-o-user')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('password')
                        ->prefixIcon('heroicon-o-lock-closed')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state)),
                    Forms\Components\TextInput::make('secret')
                        ->label('RADIUS Shared Secret')
                        ->prefixIcon('heroicon-o-key')
                        ->password()
                        ->revealable(),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                ]),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->searchable(),
                Tables\Columns\TextColumn::make('api_port')->label('Port'),
                Tables\Columns\TextColumn::make('connection_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => $state?->color()),
                Tables\Columns\TextColumn::make('routeros_version')->label('RouterOS')->placeholder('-'),
                Tables\Columns\TextColumn::make('last_checked_at')->label('Cek Terakhir')->dateTime('d M Y H:i')->placeholder('-'),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
                        ->headerActions([
                Actions\CreateAction::make()->label('Tambah')->modalDescription('Tambahkan router MikroTik baru ke sistem'),
            ])
            ->recordActions([
                Actions\Action::make('setup_script')
                    ->label('Panduan Setup')
                    ->icon('lucide-scroll-text')
                    ->color('gray')
                    ->iconButton()
                    ->modalHeading('Panduan Setup MikroTik')
                    ->modalContent(fn (Router $record) => view(
                        'filament.resources.router-resource.setup-script',
                        ['script' => static::buildSetupScript($record)],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),
                Actions\Action::make('sync_profiles')
                    ->label('Sinkronisasi Profile')
                    ->icon('lucide-refresh-cw')
                    ->color('warning')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalDescription('Semua paket/profile aktif milik router ini akan dikirim ulang ke MikroTik.')
                    ->action(function (Router $record) {
                        $mikrotikService = app(\App\Services\MikrotikService::class);

                        $profiles = \App\Models\ServicePackage::query()
                            ->where('router_id', $record->id)
                            ->where('is_active', true)
                            ->get();

                        if ($profiles->isEmpty()) {
                            Notification::make()->title('Tidak ada profile aktif untuk router ini')->warning()->send();

                            return;
                        }

                        $success = 0;
                        $failed = [];

                        foreach ($profiles as $profile) {
                            // Dibungkus try/catch PER PROFILE — kalau satu
                            // profile gagal dengan cara yang tidak terduga
                            // (di luar penanganan normal syncProfile()), yang
                            // lain tetap lanjut disinkron, dan halaman tidak
                            // ikut down jadi error 500 penuh.
                            try {
                                $result = $mikrotikService->syncProfile($record, $profile);
                            } catch (\Throwable $e) {
                                $result = ['success' => false, 'error' => $e->getMessage()];
                            }

                            if ($result['success']) {
                                $success++;
                            } else {
                                $failed[] = $profile->name.' ('.($result['error'] ?? 'error tidak diketahui').')';
                            }
                        }

                        Notification::make()
                            ->title("Sinkronisasi selesai: {$success}/{$profiles->count()} profile berhasil")
                            ->body($failed ? 'Gagal: '.implode(', ', $failed) : null)
                            ->status(empty($failed) ? 'success' : 'warning')
                            ->send();
                    }),
                Actions\Action::make('test_connection')
                    ->label('Test Koneksi')
                    ->icon('lucide-radio')
                    ->color('info')
                    ->action(function (Router $record) {
                        $result = app(\App\Services\MikrotikService::class)->testConnection($record);

                        app(\App\Repositories\Contracts\RouterRepositoryInterface::class)->updateConnectionStatus(
                            $record,
                            $result['success']
                                ? \App\Enums\RouterConnectionStatus::Connected
                                : \App\Enums\RouterConnectionStatus::Disconnected,
                            $result['version'] ?? null,
                            $result['identity'] ?? null,
                        );

                        Notification::make()
                            ->title($result['success'] ? 'Koneksi berhasil' : 'Koneksi gagal')
                            ->body($result['success'] ? "Identity: {$result['identity']}, versi {$result['version']}" : $result['error'])
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRouters::route('/'),
            'edit' => Pages\EditRouter::route('/{record}/edit'),
        ];
    }

    /**
     * Script RouterOS siap-tempel untuk orang awam: daftarkan server RADIUS
     * (server aplikasi ini) ke router, aktifkan RADIUS utk hotspot & PPPoE,
     * dan pastikan API aktif dengan user yang sama seperti diisi di form.
     * IP server RADIUS diambil dari Pengaturan > Perusahaan
     * (radius_server_host) — JANGAN dari request()->getHost() polos,
     * itu bisa IP lokal/LAN kalau admin lagi akses dari jaringan
     * kantor, bukan IP Public yang beneran bisa dijangkau router.
     */
    protected static function buildSetupScript(Router $router): string
    {
        $serverHost = \App\Models\Setting::get('radius_server_host')
            ?: request()?->getHost()
            ?: '192.168.221.254';
        $secret = $router->secret ?: 'GANTI_DENGAN_RADIUS_SHARED_SECRET';

        // Password disimpan terenkripsi dan tidak selalu bisa didekripsi ulang
        // (mis. field dikosongkan saat edit karena memang tidak wajib diisi
        // ulang). Jangan sampai gagal baca password bikin seluruh halaman error.
        try {
            $plainPassword = $router->password;
        } catch (\Throwable $e) {
            $plainPassword = null;
        }

        $userLine = $plainPassword
            ? "/user add name={$router->username} password=\"{$plainPassword}\" group=full"
            : "# Password tersimpan tidak bisa dibaca ulang otomatis — isi manual sesuai\n".
              "# password yang kamu masukkan di form Router:\n".
              "/user add name={$router->username} password=\"ISI_PASSWORD_SESUAI_FORM\" group=full";

        return <<<SCRIPT
# ============================================
#  Panduan Setup MikroTik untuk "{$router->name}"
#  Tempel semua baris ini di New Terminal Winbox
# ============================================

# 1. Daftarkan server RADIUS (aplikasi Toya Radius) untuk Hotspot & PPPoE
/radius add address={$serverHost} secret={$secret} service=hotspot,ppp authentication-port=1812 accounting-port=1813 timeout=3s

# 2. Aktifkan penggunaan RADIUS untuk Hotspot (kalau sudah ada server hotspot)
/ip hotspot profile set [find] use-radius=yes

# 3. Aktifkan penggunaan RADIUS untuk PPPoE
/ppp aaa set use-radius=yes accounting=yes interim-update=5m

# 4. Pastikan API aktif (dipakai aplikasi untuk sinkronisasi & disconnect user)
#    Username & password HARUS SAMA PERSIS dengan yang diisi di form Router
#    tadi ("{$router->username}"). Kalau user sudah ada, lewati baris ini.
{$userLine}
/ip service set api port={$router->api_port} disabled=no

# Selesai. Kembali ke aplikasi, klik "Test Koneksi" untuk memastikan berhasil,
# lalu "Sinkronisasi Profile" untuk mengirim semua paket ke router ini.
SCRIPT;
    }
}
