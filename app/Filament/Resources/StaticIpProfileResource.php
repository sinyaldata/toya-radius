<?php

namespace App\Filament\Resources;

use App\Enums\LimitType;
use App\Enums\TimeUnit;
use App\Filament\Resources\StaticIpProfileResource\Pages;
use App\Models\Bandwidth;
use App\Models\ServicePackage;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profile (paket) untuk pelanggan Static IP (DHCP Lease MikroTik) --
 * lihat StaticIpUserResource. Sengaja resource TERPISAH dari
 * PppoeProfileResource/HotspotProfileResource (mengikuti pola yang
 * sudah dipakai di aplikasi ini: satu resource per tipe paket), BUKAN
 * digabung ke salah satunya -- supaya daftar paket tetap jelas mana
 * yang buat pelanggan Static IP.
 *
 * BEDA PENTING dari PppoeProfileResource: paket ini TIDAK didorong ke
 * MikroTik sebagai PPP/Hotspot Profile (lihat MikrotikService::
 * syncProfile() -- tipe static_ip sengaja dilewati di situ). Rate-
 * limit-nya baru benar-benar dipasang PER PELANGGAN sebagai Simple
 * Queue, saat pelanggan Static IP dibuat/diedit (lihat
 * CustomerRadiusService::provisionStaticIpCustomer()). Jadi field
 * "User Group (Profil)" ala PPP TIDAK ada di sini -- yang dibutuhkan
 * cuma Bandwidth (buat hitung rate-limit) dan Router (biar tahu mau
 * dikirim ke MikroTik yang mana).
 */
class StaticIpProfileResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = ServicePackage::class;


    protected static string|\BackedEnum|null $navigationIcon = null;

    // Grup menu biasa "Profile Paket" (sama seperti PppoeProfileResource).
    // JANGAN pakai $navigationParentItem di sini -- kalau parent-nya
    // ("Profile") tidak ada di grup, Filament MENYEMBUNYIKAN item ini
    // sama sekali (itu penyebab menu Static IP "hilang").
    protected static string|\UnitEnum|null $navigationGroup = 'Profile Paket';

    // Tetap memakai kunci hak akses lama.
    protected static ?string $accessNavigationGroup = 'Profile Pelanggan';

    protected static ?string $navigationLabel = 'Static IP';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'pppoe/static-ip-profile';

    public static function getModelLabel(): string
    {
        return 'Profile Static IP';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Profile Static IP';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'static_ip');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('type')->default('static_ip'),

            // Sama seperti PppoeProfileResource: field yang tidak
            // relevan buat Static IP disembunyikan (bukan dihapus),
            // supaya kolom wajib di database tetap terisi.
            Forms\Components\Hidden::make('priority')->default(8),
            Forms\Components\Hidden::make('shared_users')->default(1),
            Forms\Components\Hidden::make('cost_price')->default(0),
            Forms\Components\Hidden::make('mikrotik_profile')->default('static-ip'),
            Forms\Components\Hidden::make('limit_type')->default('unlimited'),

            Wizard::make([
                Step::make('Informasi Produk')
                    ->icon('lucide-cable')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Produk')
                            ->placeholder('mis. Static IP 10M-150rb')
                            ->prefixIcon('heroicon-o-tag')
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Select::make('bandwidth_id')
                            ->label('Bandwidth')
                            ->placeholder('Pilih Bandwidth')
                            ->prefixIcon('heroicon-o-signal')
                            ->options(fn () => Bandwidth::where('is_active', true)->get()->mapWithKeys(fn ($b) => [$b->id => $b->label()]))
                            ->required(),

                        Forms\Components\TextInput::make('sale_price')
                            ->label('Harga Jual')
                            ->numeric()
                            ->default(0)
                            ->prefix('Rp')
                            ->required(),
                    ]),

                Step::make('Router')
                    ->icon('lucide-server')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('router_id')
                            ->label('Router')
                            ->prefixIcon('heroicon-o-server')
                            ->options(fn () => \App\Models\Router::pluck('name', 'id'))
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Step::make('Deskripsi')
                    ->icon('lucide-file-text')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->rows(2),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')->label('Nama Produk')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('bandwidth.name')->label('Bandwidth'),
                Tables\Columns\TextColumn::make('router.name')->label('Router')->placeholder('-'),
                Tables\Columns\TextColumn::make('sale_price')->label('Jual')->money('IDR', decimalPlaces: 0)->alignEnd(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah')
                    ->modalWidth('xl'),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton()->modalWidth('xl'),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaticIpProfiles::route('/'),
        ];
    }
}
