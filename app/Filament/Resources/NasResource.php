<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NasResource\Pages;
use App\Models\Nas;
use App\Models\Router;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class NasResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Nas::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'NAS';

    public static function getModelLabel(): string
    {
        return 'NAS';
    }

    public static function getPluralModelLabel(): string
    {
        return 'NAS';
    }

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Wizard::make([

                Step::make('Tipe Koneksi')
                    ->icon('heroicon-o-signal')
                    ->schema([
                        Forms\Components\Select::make('connection_type')
                            ->label('Bagaimana aplikasi ini menjangkau NAS?')
                            ->prefixIcon('heroicon-o-signal')
                            ->options([
                                'public_ip' => 'Akses Langsung — tanpa VPN (IP Public atau satu jaringan lokal)',
                                'vpn' => 'VPN — router di jaringan lain, dijangkau lewat tunnel',
                            ])
                            ->default('public_ip')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        // --- Cabang Akses Langsung (IP Public ATAU satu LAN dengan server) ---
                        Forms\Components\TextInput::make('router_ip_address')
                            ->label('IP / Host untuk Akses API MikroTik')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->placeholder('mis. 103.xx.xx.xx (IP Public) atau 192.168.x.x (kalau satu jaringan lokal)')
                            ->ip()
                            ->required(fn (callable $get) => $get('connection_type') === 'public_ip')
                            ->visible(fn (callable $get) => $get('connection_type') === 'public_ip')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (callable $set, ?string $state) => $set('nasname', $state))
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Nas $record) {
                                if ($record?->router && $record->connection_type === 'public_ip') {
                                    $component->state($record->router->ip_address);
                                }
                            })
                            ->columnSpanFull(),

                        // --- Cabang VPN ---
                        Forms\Components\Select::make('vpn_account_id')
                            ->label('Pilih Akun VPN')
                            ->prefixIcon('heroicon-o-key')
                            ->placeholder('Pilih dari akun VPN yang sudah disiapkan...')
                            ->options(function () {
                                return \App\Models\VpnAccount::where('is_active', true)
                                    // VpnAccount TIDAK auto-filter tenant (beda dari Nas/Router)
                                    // — difilter manual di sini, khusus buat tampilan form ini.
                                    ->when(app()->bound('currentTenant'), function ($q) {
                                        $q->where('tenant_id', app('currentTenant')?->id);
                                    })
                                    ->with('nas')
                                    ->get()
                                    ->mapWithKeys(fn ($a) => [
                                        $a->id => "{$a->username} : {$a->tunnel_ip} (".strtoupper($a->vpn_type).')'.($a->nas_id ? ' — dipakai NAS '.($a->nas?->shortname ?? '') : ''),
                                    ]);
                            })
                            // Akun yang sudah dipakai NAS LAIN jadi abu-abu &
                            // tidak bisa dipilih — tapi kalau ini akun yang
                            // SUDAH terpasang di NAS yang lagi diedit, tetap
                            // bisa dipilih (biar tidak "ke-lock" pas edit).
                            ->disableOptionWhen(function (string $value, ?Nas $record) {
                                $account = \App\Models\VpnAccount::find($value);

                                if (! $account || ! $account->nas_id) {
                                    return false;
                                }

                                return ! $record || $account->nas_id !== $record->id;
                            })
                            ->searchable()
                            ->required(fn (callable $get) => $get('connection_type') === 'vpn')
                            ->visible(fn (callable $get) => $get('connection_type') === 'vpn')
                            // Menggantikan placeholder "vpn_script_hint" yang
                            // dulu jadi baris kosong tersendiri (permintaan
                            // user: hapus ruang kosong yang tidak perlu) --
                            // pesannya cukup di sini, hilang sendiri begitu
                            // akun sudah dipilih (skrip langsung tampil).
                            ->helperText(fn (callable $get) => filled($get('vpn_account_id'))
                                ? null
                                : 'Pilih akun VPN untuk melihat script setup MikroTik-nya di bawah.')
                            ->live()
                            ->afterStateUpdated(function (callable $set, ?string $state) {
                                $account = $state ? \App\Models\VpnAccount::find($state) : null;
                                $set('vpn_type', $account?->vpn_type);
                                $set('vpn_tunnel_ip', $account?->tunnel_ip);
                                $set('nasname', $account?->tunnel_ip);
                            })
                            ->afterStateHydrated(function (Forms\Components\Select $component, ?Nas $record) {
                                if ($record) {
                                    $account = \App\Models\VpnAccount::where('nas_id', $record->id)->first();
                                    $component->state($account?->id);
                                }
                            })
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('vpn_type')
                            ->afterStateHydrated(function (Forms\Components\Hidden $component, ?Nas $record) {
                                if ($record?->router && $record->connection_type === 'vpn') {
                                    $component->state($record->vpn_type);
                                }
                            }),
                        Forms\Components\Hidden::make('vpn_tunnel_ip')
                            ->afterStateHydrated(function (Forms\Components\Hidden $component, ?Nas $record) {
                                if ($record?->router && $record->connection_type === 'vpn') {
                                    $component->state($record->router->ip_address);
                                }
                            }),

                        // Script LANGSUNG muncul begitu Akun VPN dipilih — sudah
                        // terisi username/password asli, siap copy-paste, tidak
                        // perlu simpan NAS dulu atau isi apapun secara manual lagi.
                        Forms\Components\Placeholder::make('vpn_script_preview')
                            ->label('Script Setup MikroTik (siap copy-paste)')
                            ->visible(fn (callable $get) => $get('connection_type') === 'vpn' && filled($get('vpn_account_id')))
                            ->content(function (callable $get) {
                                $account = \App\Models\VpnAccount::find($get('vpn_account_id'));

                                if (! $account) {
                                    return '';
                                }

                                $script = static::buildVpnScriptFromAccount($account);

                                return view('filament.resources.nas-resource.script-preview', ['script' => $script]);
                            })
                            ->columnSpanFull(),

                        \Filament\Schemas\Components\Actions::make([
                            \Filament\Actions\Action::make('test_vpn_connection')
                                ->label('Tes Koneksi VPN')
                                ->icon('lucide-radio')
                                ->color('info')
                                ->visible(fn (callable $get) => $get('connection_type') === 'vpn' && filled($get('vpn_account_id')))
                                ->action(function (callable $get) {
                                    $account = \App\Models\VpnAccount::find($get('vpn_account_id'));

                                    if (! $account) {
                                        Notification::make()->title('Pilih Akun VPN dulu')->warning()->send();

                                        return;
                                    }

                                    $ip = escapeshellarg($account->tunnel_ip);
                                    exec("ping -c 2 -W 2 {$ip} 2>&1", $output, $exitCode);

                                    Notification::make()
                                        ->title($exitCode === 0 ? "Berhasil — {$account->tunnel_ip} merespons" : "Tidak ada balasan dari {$account->tunnel_ip}")
                                        ->body($exitCode === 0 ? 'MikroTik sudah connect & tunnel-nya aktif.' : 'Pastikan script sudah di-paste ke MikroTik dan statusnya connected.')
                                        ->status($exitCode === 0 ? 'success' : 'danger')
                                        ->send();
                                }),
                        ])->columnSpanFull(),
                    ]),

                Step::make('Detail NAS')
                    ->icon('heroicon-o-server')
                    ->schema([
                        Forms\Components\TextInput::make('auth_port')
                            ->label('Authentication Port')
                            ->prefixIcon('heroicon-o-hashtag')
                            ->numeric()->default(1812)->required(),
                        Forms\Components\TextInput::make('acct_port')
                            ->label('Accounting Port')
                            ->prefixIcon('heroicon-o-hashtag')
                            ->numeric()->default(1813)->required(),
                        Forms\Components\Select::make('type')
                            ->label('Tipe NAS')
                            ->prefixIcon('heroicon-o-server')
                            ->options(['mikrotik' => 'MikroTik', 'other' => 'Other (MikroTik - Linux)'])
                            ->default('mikrotik')
                            ->required(),
                        Forms\Components\TextInput::make('shortname')
                            ->label('Nama Singkat')
                            ->placeholder('mis. RB-UTAMA')
                            ->prefixIcon('heroicon-o-tag')->required()->maxLength(32),
                        Forms\Components\Select::make('timezone')
                            ->label('Zona Waktu')
                            ->prefixIcon('heroicon-o-clock')
                            ->options([
                                'Asia/Jakarta' => 'Asia/Jakarta (WIB, UTC+7)',
                                'Asia/Makassar' => 'Asia/Makassar (WITA, UTC+8)',
                                'Asia/Jayapura' => 'Asia/Jayapura (WIT, UTC+9)',
                            ])
                            ->default('Asia/Makassar')
                            ->native(false),
                        // Cuma tampil kalau BUKAN VPN — kalau VPN, IP ini
                        // sudah otomatis kesalin dari Akun VPN yang dipilih
                        // di Langkah 1, tidak perlu ditampilkan/diedit lagi.
                        Forms\Components\TextInput::make('nasname')
                            ->label('IP / Host NAS')
                            ->placeholder('mis. 103.154.76.124')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->required(fn (callable $get) => $get('connection_type') !== 'vpn')
                            ->visible(fn (callable $get) => $get('connection_type') !== 'vpn')
                            ->dehydratedWhenHidden()
                            ->maxLength(128),
                        Forms\Components\TextInput::make('secret')
                            ->label('RADIUS Secret (otomatis)')
                            ->prefixIcon('heroicon-o-key')
                            ->maxLength(60)
                            ->disabled(fn (string $operation) => $operation === 'create')
                            ->dehydrated(),

                        Forms\Components\Select::make('router_api_mode')
                            ->label('Mode API')
                            ->prefixIcon('heroicon-o-code-bracket')
                            ->options([
                                'legacy' => 'Legacy API (RouterOS v6 & v7)',
                                'rest' => 'REST API (Hanya ROS7)',
                            ])
                            ->default('legacy')
                            ->required()
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Select $component, ?Nas $record) {
                                if ($record?->router) {
                                    $component->state($record->router->api_mode ?? 'legacy');
                                }
                            }),
                        Forms\Components\TextInput::make('router_api_port')
                            ->label('Port API')
                            ->prefixIcon('heroicon-o-hashtag')
                            ->numeric()
                            ->default(8728)
                            ->required()
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Nas $record) {
                                if ($record?->router) {
                                    $component->state($record->router->api_port);
                                }
                            }),
                        Forms\Components\TextInput::make('router_www_port')
                            ->label('Port WWW (SSL)')
                            ->prefixIcon('heroicon-o-hashtag')
                            ->numeric()
                            ->default(8443)
                            ->visible(fn (callable $get) => $get('router_api_mode') === 'rest')
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Nas $record) {
                                if ($record?->router) {
                                    $component->state($record->router->www_port);
                                }
                            }),
                        Forms\Components\Toggle::make('router_use_ssl')
                            ->label('Gunakan SSL (API-SSL, port 8729)')
                            ->visible(fn (callable $get) => $get('router_api_mode') === 'legacy'),

                        Forms\Components\TextInput::make('api_username')
                            ->label('Username API (otomatis)')
                            ->prefixIcon('heroicon-o-user')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?Nas $record) => $record !== null),
                        Forms\Components\TextInput::make('api_password')
                            ->label('Password API (otomatis)')
                            ->prefixIcon('heroicon-o-key')
                            ->password()
                            ->revealable()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?Nas $record) => $record !== null),
                    ])
                    ->columns(2),

            ])
                // Kelas ini SATU-SATUNYA yang disasar CSS pemadat form
                // "Ubah NAS" (permintaan user: minimalis, tanpa ruang
                // kosong yang tidak perlu, ukuran popup tidak melompat-
                // lompat) -- lihat resources/views/filament/global-ui-
                // polish.blade.php, bagian "Form Ubah NAS dipadatkan".
                // Cuma memengaruhi wizard INI: wizard resource lain
                // tidak punya kelas ini, jadi tidak ikut berubah.
                ->extraAttributes(['class' => 'tya-nas-wizard'])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('shortname')->label('Nama'),
                Tables\Columns\TextColumn::make('type')->label('Type')->badge(),
                Tables\Columns\TextColumn::make('connection_type')
                    ->label('Koneksi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'vpn' => 'VPN',
                        'public_ip' => 'Akses Langsung',
                        default => '-',
                    })
                    ->color(fn (?string $state) => $state === 'vpn' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('nasname')->label('IP / Host')->searchable(),
                Tables\Columns\TextColumn::make('router.connection_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah')
                    ->modalDescription('Tambahkan NAS baru ke sistem')
                    ->using(fn (array $data) => static::createNasWithRouter($data)),
            ])
            ->recordActions([
                Actions\Action::make('setup_script')
                    ->label('Panduan Setup MikroTik')
                    ->icon('lucide-terminal')
                    ->color('gray')
                    ->iconButton()
                    ->modalHeading('Panduan Setup MikroTik')
                    ->modalContent(fn (Nas $record) => view(
                        'filament.resources.nas-resource.setup-script',
                        ['script' => static::buildSetupScript($record)],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),
                Actions\Action::make('test_connection')
                    ->label('Test Koneksi')
                    ->icon('lucide-radio')
                    ->color('info')
                    ->iconButton()
                    ->visible(fn (Nas $record) => $record->router !== null)
                    ->action(function (Nas $record) {
                        $router = $record->router;
                        $result = app(\App\Services\MikrotikService::class)->testConnection($router);

                        app(\App\Repositories\Contracts\RouterRepositoryInterface::class)->updateConnectionStatus(
                            $router,
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
                Actions\Action::make('sync_profiles')
                    ->label('Sinkronisasi Profile')
                    ->icon('lucide-refresh-cw')
                    ->color('warning')
                    ->iconButton()
                    ->visible(fn (Nas $record) => $record->router !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Semua paket/profile aktif milik router NAS ini akan dikirim ulang ke MikroTik.')
                    ->action(function (Nas $record) {
                        $mikrotikService = app(\App\Services\MikrotikService::class);

                        $profiles = \App\Models\ServicePackage::query()
                            ->where('router_id', $record->router_id)
                            ->where('is_active', true)
                            ->get();

                        if ($profiles->isEmpty()) {
                            Notification::make()->title('Tidak ada profile aktif untuk router NAS ini')->warning()->send();

                            return;
                        }

                        $success = 0;
                        $failed = [];

                        foreach ($profiles as $profile) {
                            try {
                                $result = $mikrotikService->syncProfile($record->router, $profile);
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
                Actions\EditAction::make()->iconButton()->modalWidth('2xl')->modalHeading('Ubah NAS'),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->before(function (Nas $record) {
                        // Hapus juga data Router yang terhubung — kalau
                        // tidak, dia jadi "hantu" (masih ada di database,
                        // masih muncul di dropdown lain kayak Group User)
                        // walau NAS-nya sendiri sudah dihapus.
                        $record->router?->delete();
                    }),
            ])
            ->recordActionsColumnLabel('Aksi')
            ->recordActionsAlignment('center');
    }

    /**
     * Form gabungan NAS+Router: pisahkan field "router_*" dari $data,
     * bikin/update Router-nya duluan, baru simpan NAS dengan router_id
     * yang sudah nyambung.
     */
    protected static function createNasWithRouter(array $data): Nas
    {
        $vpnAccountId = $data['vpn_account_id'] ?? null;
        unset($data['vpn_account_id']);

        [$nasData, $routerData] = static::splitRouterFields($data);

        // Kredensial (username/password API, RADIUS secret) di-generate
        // di SINI dulu (bukan cuma diserahkan ke Nas::boot()) — supaya
        // Router (dibuat duluan di bawah) bisa langsung dikasih
        // username/password yang SAMA PERSIS, tidak perlu isi manual
        // terpisah lagi (dulu ada 2 pasang kredensial, sekarang cuma 1).
        $apiUsername = 'toya_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
        $apiPassword = \Illuminate\Support\Str::random(12);

        $nasData['api_username'] = $apiUsername;
        $nasData['api_password'] = $apiPassword;
        $nasData['secret'] = $nasData['secret'] ?? \Illuminate\Support\Str::random(20);

        $routerData['username'] = $apiUsername;
        $routerData['password'] = $apiPassword;

        $router = Router::create(array_merge($routerData, [
            'name' => $nasData['shortname'].' (MikroTik)',
            'connection_status' => \App\Enums\RouterConnectionStatus::Disconnected,
        ]));

        $nasData['router_id'] = $router->id;

        $nas = Nas::create($nasData);

        if ($vpnAccountId) {
            \App\Models\VpnAccount::where('id', $vpnAccountId)->update(['nas_id' => $nas->id]);
        }

        return $nas;
    }

    public static function updateNasWithRouter(Nas $record, array $data): Nas
    {
        $vpnAccountId = $data['vpn_account_id'] ?? null;
        unset($data['vpn_account_id']);

        [$nasData, $routerData] = static::splitRouterFields($data);

        // Kredensial API tetap dipakai yang SUDAH ADA (auto-generated
        // waktu NAS ini pertama dibuat) — tidak diganti-ganti waktu
        // edit, cuma disinkronkan lagi ke Router (jaga-jaga kalau
        // sebelumnya belum sempat sinkron).
        unset($nasData['api_username'], $nasData['api_password']);
        $routerData['username'] = $record->api_username;

        try {
            $routerData['password'] = $record->api_password;
        } catch (\Throwable $e) {
            unset($routerData['password']); // Tidak bisa didekripsi ulang — biarkan password Router yang lama.
        }

        if ($record->router) {
            $record->router->update($routerData);
            $nasData['router_id'] = $record->router->id;
        } else {
            $router = Router::create(array_merge($routerData, [
                'name' => $nasData['shortname'].' (MikroTik)',
                'connection_status' => \App\Enums\RouterConnectionStatus::Disconnected,
            ]));
            $nasData['router_id'] = $router->id;
        }

        $record->update($nasData);

        // Lepas akun VPN lama (kalau ganti pilihan atau dihapus), lalu
        // tandai akun VPN yang baru dipilih sebagai "dipakai" NAS ini.
        \App\Models\VpnAccount::where('nas_id', $record->id)->update(['nas_id' => null]);
        if ($vpnAccountId) {
            \App\Models\VpnAccount::where('id', $vpnAccountId)->update(['nas_id' => $record->id]);
        }

        return $record;
    }

    protected static function splitRouterFields(array $data): array
    {
        $ipAddress = ($data['connection_type'] ?? 'public_ip') === 'vpn'
            ? ($data['vpn_tunnel_ip'] ?? null)
            : ($data['router_ip_address'] ?? null);

        $routerData = [
            'ip_address' => $ipAddress,
            'api_port' => $data['router_api_port'] ?? 8728,
            'api_mode' => $data['router_api_mode'] ?? 'legacy',
            'www_port' => $data['router_www_port'] ?? null,
            'use_ssl' => $data['router_use_ssl'] ?? false,
            'is_active' => true,
        ];

        unset(
            $data['router_ip_address'],
            $data['vpn_tunnel_ip'],
            $data['router_api_port'],
            $data['router_api_mode'],
            $data['router_www_port'],
            $data['router_use_ssl'],
            $data['api_username'],
            $data['api_password'],
        );

        return [$data, $routerData];
    }

    protected static function buildSetupScript(Nas $nas): string
    {
        // PENTING: server fisiknya SAMA buat semua tenant (satu VPS),
        // jadi alamat IP Public-nya juga harus SAMA — TIDAK BOLEH ikut
        // domain/subdomain tenant yang lagi login (`request()->getHost()`
        // salah di sini, soalnya beda-beda per tenant padahal server-nya
        // sama). Kalau setting "radius_server_host" belum diisi manual,
        // pakai IP Public server yang sebenarnya sebagai cadangan.
        $serverHost = \App\Models\Setting::get('radius_server_host')
            ?: env('RADIUS_SERVER_PUBLIC_IP', '103.180.165.245');
        $secret = $nas->secret ?: 'GANTI_DENGAN_RADIUS_SHARED_SECRET';
        $tunnelIp = $nas->router?->ip_address ?: 'ISI_IP_TUNNEL_VPN';

        try {
            $apiPassword = $nas->api_password;
        } catch (\Throwable $e) {
            $apiPassword = null;
        }

        $apiUsername = $nas->api_username ?: 'toya_api';
        $userLine = $apiPassword
            ? "/user add name={$apiUsername} password=\"{$apiPassword}\" group=full"
            : "# Password tidak terbaca — cek halaman Edit NAS\n".
              "/user add name={$apiUsername} password=\"ISI_SESUAI_HALAMAN_EDIT\" group=full";

        // CATATAN: skrip ini SENGAJA cuma isi pendaftaran RADIUS saja —
        // pengaturan koneksi VPN (interface, IP tunnel, dst) SUDAH ADA
        // skrip tersendiri (dari halaman Akun VPN), tidak diulang di sini
        // biar tidak dobel/bingung mana yang harus dijalankan duluan.
        $connectionNote = $nas->connection_type === 'vpn'
            ? "# Router ini terhubung lewat VPN — pastikan tunnel VPN-nya sudah\n".
              "# aktif dulu (pakai skrip dari halaman Akun VPN) SEBELUM baris di bawah.\n"
            : "# Pastikan router bisa akses {$serverHost}, port 1812/1813 tidak diblokir\n";

        // Tanda pengenal Toya Radius — SAMA buat semua NAS (dipakai buat
        // cari & hapus pengaturan LAMA sebelum pasang yang baru, supaya
        // skrip ini AMAN dijalankan berkali-kali, tidak numpuk dobel).
        $radiusComment = 'toya-radius';

        // Untuk NAS lewat VPN, RADIUS HARUS diarahkan ke IP server di
        // DALAM tunnel + src-address IP tunnel router — kalau pakai IP
        // Public, trafiknya keluar lewat internet biasa (bukan tunnel)
        // dan ditolak FreeRADIUS.
        if ($nas->connection_type === 'vpn') {
            $tunnelServerIp = \App\Models\Setting::get('vpn_server_tunnel_ip', '10.90.90.1');
            $radiusAddLine = "/radius add address={$tunnelServerIp} src-address={$tunnelIp} secret={$secret} service=hotspot,ppp authentication-port=1812 accounting-port=1813 timeout=3s comment=\"{$radiusComment}\"";
        } else {
            $radiusAddLine = "/radius add address={$serverHost} secret={$secret} service=hotspot,ppp authentication-port=1812 accounting-port=1813 timeout=3s comment=\"{$radiusComment}\"";
        }

        // WAJIB dijalankan SEBELUM /radius add di atas — hapus dulu
        // pengaturan RADIUS lama punya Toya Radius (dikenali dari
        // komentarnya), baru pasang yang baru. Kalau tidak, jalanin
        // skrip ini dua kali bakal bikin 2 entri RADIUS yang bentrok.
        $radiusRemoveLine = "/radius remove [find comment=\"{$radiusComment}\"]";

        return <<<SCRIPT
# ============================================
#  Setup RADIUS — Toya Radius
#  NAS "{$nas->nasname}" ({$nas->shortname})
#  Digenerate oleh Toya Radius — aman dijalankan ulang kapan saja,
#  pengaturan lama otomatis dibersihkan dulu sebelum yang baru dipasang.
# ============================================

{$connectionNote}
# ---- RADIUS (Hotspot & PPPoE) — Toya Radius ----
{$radiusRemoveLine}
{$radiusAddLine}
/ip hotspot profile set [find] use-radius=yes
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
{$userLine}
/ip service set api disabled=no
SCRIPT;
    }

    protected static function buildVpnScript(Nas $nas, string $tunnelIp): string
    {
        $interfaceName = 'toya-'.\Illuminate\Support\Str::slug($nas->shortname);

        return match ($nas->vpn_type) {
            'wireguard' => <<<SCRIPT
# WireGuard — RouterOS generate key otomatis
/interface wireguard remove [find name="{$interfaceName}"]
/interface wireguard add name={$interfaceName} listen-port=51820
/interface wireguard print
# Catat public key di atas, kirim ke admin server VPN
/ip address remove [find interface="{$interfaceName}"]
/ip address add address={$tunnelIp}/32 interface={$interfaceName}
# Setelah dapat info peer dari admin server:
# /interface wireguard peers add interface={$interfaceName} public-key="PUBLIC_KEY_SERVER_VPN" endpoint-address=ALAMAT_SERVER_VPN endpoint-port=51820 allowed-address=0.0.0.0/0

SCRIPT,
            'sstp' => <<<SCRIPT
# SSTP — ganti ALAMAT_SERVER_VPN, USER_VPN, PASSWORD_VPN
# Kalau gagal auth, coba format user "USER_VPN@NAMA_HUB"
/interface sstp-client remove [find name="{$interfaceName}"]
/interface sstp-client add name={$interfaceName} connect-to=ALAMAT_SERVER_VPN user=USER_VPN password=PASSWORD_VPN verify-server-certificate=no disabled=no
/interface sstp-client set {$interfaceName} add-default-route=no
/ip address remove [find interface="{$interfaceName}"]
/ip address add address={$tunnelIp}/24 interface={$interfaceName}

SCRIPT,
            'pptp' => <<<SCRIPT
# PPTP — ganti ALAMAT_SERVER_VPN, USER_VPN, PASSWORD_VPN
/interface pptp-client remove [find name="{$interfaceName}"]
/interface pptp-client add name={$interfaceName} connect-to=ALAMAT_SERVER_VPN user=USER_VPN password=PASSWORD_VPN disabled=no
# /ip address add address={$tunnelIp}/32 interface={$interfaceName}

SCRIPT,
            'l2tp' => <<<SCRIPT
# L2TP (xl2tpd, tanpa IPsec) — ganti ALAMAT_SERVER_VPN, USER_VPN, PASSWORD_VPN
# Wajib ada baris ini di /etc/ppp/chap-secrets server:
#   USER_VPN  l2tp-server  PASSWORD_VPN  {$tunnelIp}
/ppp profile add name=toya-vpn-profile use-encryption=yes
/interface l2tp-client remove [find name="{$interfaceName}"]
/interface l2tp-client add name={$interfaceName} connect-to=ALAMAT_SERVER_VPN user=USER_VPN password=PASSWORD_VPN use-ipsec=no profile=toya-vpn-profile disabled=no

SCRIPT,
            default => "# Jenis VPN belum dipilih — buka Edit NAS ini, isi \"Jenis VPN\" dulu.\n",
        };
    }

    /**
     * Versi "siap tempel" — dipakai buat preview LANGSUNG di Wizard
     * begitu admin pilih Akun VPN, SEBELUM NAS-nya disimpan. Beda dari
     * buildVpnScript() di atas (yang masih ada placeholder "GANTI..."
     * karena dulu belum ada Akun VPN terpusat), versi ini langsung isi
     * username/password ASLI dari akun yang dipilih — tidak perlu edit
     * manual lagi sama sekali, sesuai permintaan.
     */
    public static function buildVpnScriptFromAccount(\App\Models\VpnAccount $account, string $interfaceName = 'toya-vpn'): string
    {
        // Sama seperti skrip IP Public — server VPN-nya SAMA buat semua
        // tenant (satu VPS), jadi TIDAK BOLEH ikut domain tenant yang
        // lagi login.
        $serverHost = \App\Models\Setting::get('radius_server_host')
            ?: env('RADIUS_SERVER_PUBLIC_IP', '103.180.165.245');

        try {
            $password = $account->password;
        } catch (\Throwable $e) {
            $password = 'ISI_SESUAI_HALAMAN_AKUN_VPN';
        }

        $user = "{$account->username}@ToyaHub";

        // Alamat RADIUS untuk NAS lewat VPN HARUS IP server di dalam
        // tunnel — kalau diisi IP Public, trafiknya keluar lewat internet
        // biasa (bukan tunnel) dan ditolak FreeRADIUS. Ini yang akhirnya
        // bikin login voucher berhasil.
        $tunnelServerIp = \App\Models\Setting::get('vpn_server_tunnel_ip', '10.90.90.1');

        $radiusLine = "/radius set [find] address={$tunnelServerIp} src-address={$account->tunnel_ip}";

        return match ($account->vpn_type) {
            'sstp' => <<<SCRIPT
# ==== Setup VPN (SSTP) — Toya Radius ====
/interface sstp-client remove [find name="{$interfaceName}"]
/interface sstp-client add name={$interfaceName} connect-to={$serverHost} user="{$user}" password="{$password}" verify-server-certificate=no disabled=no
:delay 5s
# Arahkan RADIUS ke IP server DI DALAM tunnel (JANGAN pakai IP Public,
# dan JANGAN tambah /ip route ke server VPN lewat tunnel — itu bikin loop):
{$radiusLine}
# Setup sekali di vpncmd server VPN:
#   Hub ToyaHub
#   UserCreate {$account->username} /GROUP:none /REALNAME:none /NOTE:none
#   UserRadiusSet {$account->username}
SCRIPT,
            'pptp' => <<<SCRIPT
# ==== Setup VPN (PPTP) — Toya Radius ====
/interface pptp-client remove [find name="{$interfaceName}"]
/interface pptp-client add name={$interfaceName} connect-to={$serverHost} user="{$user}" password="{$password}" disabled=no
:delay 5s
{$radiusLine}
# Setup sekali di vpncmd server VPN:
#   Hub ToyaHub
#   UserCreate {$account->username} /GROUP:none /REALNAME:none /NOTE:none
#   UserRadiusSet {$account->username}
SCRIPT,
            'l2tp' => <<<SCRIPT
# ==== Setup VPN (L2TP) — Toya Radius ====
# IP tunnel otomatis tetap sesuai yang kamu tentukan (dari chap-secrets server)
/ppp profile add name=toya-vpn-profile use-encryption=yes
/interface l2tp-client remove [find name="{$interfaceName}"]
/interface l2tp-client add name={$interfaceName} connect-to={$serverHost} user="{$account->username}" password="{$password}" use-ipsec=no profile=toya-vpn-profile disabled=no
:delay 5s
# Arahkan RADIUS ke IP server DI DALAM tunnel (JANGAN pakai IP Public,
# dan JANGAN tambah /ip route ke server VPN lewat tunnel — itu bikin loop):
{$radiusLine}
SCRIPT,
            'wireguard' => <<<SCRIPT
# ==== Setup VPN (WireGuard) — Toya Radius ====
# RouterOS generate key sendiri, catat public key, kirim ke admin server VPN
/interface wireguard add name={$interfaceName} listen-port=51820
/interface wireguard print
/ip address add address={$account->tunnel_ip}/32 interface={$interfaceName}
SCRIPT,
            default => '# Jenis VPN tidak dikenali.',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNas::route('/'),
            // Halaman Edit TERPISAH SENGAJA DIHAPUS (permintaan user):
            // selama route 'edit' masih terdaftar di sini, EditAction akan
            // BERPINDAH HALAMAN ke situ dan mengabaikan pengaturan modal
            // (->modalWidth dsb) -- itu sebabnya form wizard dulu selalu
            // terbuka satu halaman penuh. Begitu barisnya dilepas,
            // Filament otomatis membuka form yang sama sebagai POPUP.
        ];
    }
}
