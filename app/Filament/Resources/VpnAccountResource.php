<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnAccountResource\Pages;
use App\Models\VpnAccount;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class VpnAccountResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = VpnAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Akun VPN';

    protected static ?int $navigationSort = 5;

    /**
     * Menu ini SENGAJA disembunyikan dari Toya Radius — pengelolaan Akun
     * VPN dipindah ke halaman terpisah khusus owner (lihat routes/web.php
     * & VpnOwnerController), TIDAK lewat panel Filament staff/admin biasa
     * lagi. Class & tabel ini tetap ada (dipakai dropdown "Pilih Akun
     * VPN" di Wizard NAS), cuma tidak bisa diakses/di-manage dari sini.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'Akun VPN';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Akun VPN';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Forms\Components\TextInput::make('username')->required()->maxLength(60),
            Forms\Components\TextInput::make('password')->password()->revealable()->required(),
            Forms\Components\TextInput::make('tunnel_ip')->label('IP Tunnel Tetap')->ip()->required(),
            Forms\Components\Select::make('vpn_type')
                ->label('Jenis VPN')
                ->options([
                    'wireguard' => 'WireGuard',
                    'pptp' => 'PPTP',
                    'l2tp' => 'L2TP/IPsec',
                ])
                ->required(),
            Forms\Components\TextInput::make('hub_name')
                ->label('Nama Hub (khusus SoftEther)')
                ->maxLength(60),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            Forms\Components\Textarea::make('notes')->label('Catatan')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('username')->searchable(),
                Tables\Columns\TextColumn::make('tunnel_ip')->label('IP Tunnel'),
                Tables\Columns\TextColumn::make('vpn_type')->label('Jenis VPN')->badge(),
                Tables\Columns\TextColumn::make('nas.shortname')
                    ->label('Dipakai Oleh')
                    ->placeholder('— Belum dipakai —')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'success'),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Tambah Satu'),
                Actions\Action::make('generate_bulk')
                    ->label('Generate Banyak Sekaligus')
                    ->icon('lucide-sparkles')
                    ->color('primary')
                    ->modalHeading('Generate Akun VPN Massal')
                    ->modalDescription('Isi ini SEKALI, otomatis bikin banyak akun VPN sekaligus (username & password digenerate otomatis) — cocok buat nyiapin "stok" akun VPN siap pakai, tinggal pilih pas nambah NAS baru nanti.')
                    ->schema([
                        Forms\Components\TextInput::make('prefix')
                            ->label('Prefix Username')
                            ->default('toyavpn')
                            ->required(),
                        Forms\Components\TextInput::make('jumlah')
                            ->label('Jumlah Akun')
                            ->numeric()->minValue(1)->maxValue(200)->default(10)->required(),
                        Forms\Components\TextInput::make('ip_awal')
                            ->label('IP Tunnel Awal')
                            ->placeholder('mis. 192.168.30.10')
                            ->ip()->required(),
                        Forms\Components\Select::make('vpn_type')
                            ->label('Jenis VPN')
                            ->options([
                                'wireguard' => 'WireGuard',
                                'pptp' => 'PPTP',
                                'l2tp' => 'L2TP/IPsec',
                            ])
                            ->default('l2tp')
                            ->required(),
                        Forms\Components\TextInput::make('hub_name')
                            ->label('Nama Hub (khusus SoftEther, opsional)')
                            ->maxLength(60),
                    ])
                    ->action(function (array $data) {
                        $ipParts = explode('.', $data['ip_awal']);
                        $lastOctet = (int) array_pop($ipParts);
                        $ipBase = implode('.', $ipParts);

                        $created = 0;
                        $skipped = [];

                        for ($i = 0; $i < (int) $data['jumlah']; $i++) {
                            $username = $data['prefix'].str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                            $ip = $ipBase.'.'.($lastOctet + $i);

                            if (VpnAccount::where('username', $username)->exists()) {
                                $skipped[] = $username;

                                continue;
                            }

                            VpnAccount::create([
                                'username' => $username,
                                'password' => \Illuminate\Support\Str::random(12),
                                'tunnel_ip' => $ip,
                                'vpn_type' => $data['vpn_type'],
                                'hub_name' => $data['hub_name'] ?: null,
                                'is_active' => true,
                            ]);
                            $created++;
                        }

                        Notification::make()
                            ->title("{$created} akun VPN berhasil dibuat")
                            ->body($skipped ? 'Dilewati (username sudah ada): '.implode(', ', $skipped) : 'Catatan: akun ini baru tersimpan di APLIKASI. Kamu tetap perlu buat akun yang sama persis (username/password) di server VPN kamu sendiri (via vpncmd untuk SoftEther) — aplikasi ini belum otomatis membuat akun di server VPN-nya.')
                            ->status($skipped ? 'warning' : 'success')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton()->visible(fn (VpnAccount $record) => ! $record->isUsed()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVpnAccounts::route('/'),
        ];
    }
}
