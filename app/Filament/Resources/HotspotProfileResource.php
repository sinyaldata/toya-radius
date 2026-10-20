<?php

namespace App\Filament\Resources;

use App\Enums\LimitType;
use App\Enums\TimeUnit;
use App\Filament\Resources\HotspotProfileResource\Pages;
use App\Models\Bandwidth;
use App\Models\ServicePackage;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HotspotProfileResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = ServicePackage::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    // Grup menu biasa "Profile Paket" (sama seperti PppoeProfileResource).
    // JANGAN pakai $navigationParentItem di sini -- kalau parent-nya
    // ("Profile") tidak ada di grup, Filament MENYEMBUNYIKAN item ini
    // sama sekali (itu penyebab menu Hotspot "hilang"). CATATAN: profile
    // ini tetap dipakai juga oleh alur generate VOUCHER hotspot.
    protected static string|\UnitEnum|null $navigationGroup = 'Profile Paket';

    // Tetap memakai kunci hak akses lama ('Hotspot'), sama seperti pola
    // di PppoeProfileResource/StaticIpProfileResource -- supaya hak
    // akses yang sudah diatur admin untuk Outlet/Reseller/Biller tidak
    // berubah gara-gara menu-nya pindah tempat.
    protected static ?string $accessNavigationGroup = 'Hotspot';

    protected static ?string $navigationLabel = 'Hotspot';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'hotspot/profile';

    public static function getModelLabel(): string
    {
        return 'Profile Hotspot';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Profile Hotspot';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', ['hotspot', 'both']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('type')->default('hotspot'),

            Wizard::make([
                Step::make('Informasi Produk')
                    ->icon('lucide-wifi')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Produk')
                            ->placeholder('mis. Hotspot-1HARI-5000')
                            ->prefixIcon('heroicon-o-tag')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('bandwidth_id')
                            ->label('Bandwidth')
                            ->placeholder('Pilih Bandwidth')
                            ->prefixIcon('heroicon-o-signal')
                            ->options(fn () => Bandwidth::where('is_active', true)->get()->mapWithKeys(fn ($b) => [$b->id => $b->label()]))
                            ->required(),
                        Group::make([
                            Forms\Components\TextInput::make('validity_days')
                                ->label('Masa Berlaku')
                                ->numeric()
                                ->default(30)
                                ->required(),
                            Forms\Components\Select::make('validity_unit')
                                ->label(false)
                                ->options(['jam' => 'Jam', 'hari' => 'Hari'])
                                ->default('hari'),
                        ])->columns(2),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                        // Permintaan user: paket yang cuma dipakai untuk
                        // Member (langganan bulanan hotspot, menu Member)
                        // jangan sampai ikut muncul di daftar pilihan
                        // GENERATE VOUCHER (baik di aplikasi maupun bot
                        // Telegram) -- paket Member & paket Voucher
                        // sama-sama disimpan di sini, jadi dibedakan
                        // lewat toggle ini. Aktifkan HANYA untuk paket
                        // yang memang khusus Member.
                        Forms\Components\Toggle::make('is_member_only')
                            ->label('Khusus Member')
                            ->default(false)
                            ->columnSpanFull(),
                        // Field "Batasi Khusus Outlet" DIHAPUS dari form ini
                        // (permintaan user): profile mana yang boleh dipakai
                        // sebuah outlet/reseller sekarang diatur dari SISI
                        // OUTLET -- menu Mitra > Reseller/Outlet, isian
                        // "Profile Hotspot yang Boleh Dipakai". Datanya
                        // TABEL PIVOT YANG SAMA (service_package_outlet, lihat
                        // ServicePackage::outlets() & Outlet::servicePackages()),
                        // jadi pembatasan yang sudah ada TETAP berlaku dan
                        // tidak ada data yang hilang; menyimpan profile dari
                        // form ini tidak menyentuh pivot-nya sama sekali.
                    ])->columns(2),

                Step::make('Batas Pemakaian')
                    ->icon('lucide-gauge')
                    ->schema([
                        Forms\Components\Select::make('limit_type')
                            ->label('Tipe Limit')
                            ->options(LimitType::options())
                            ->default('unlimited')
                            ->required()
                            ->reactive(),
                        Group::make([
                            Forms\Components\TextInput::make('time_limit_value')
                                ->label('Batas Waktu')
                                ->numeric()
                                ->placeholder('Unlimited'),
                            Forms\Components\Select::make('time_limit_unit')
                                ->label(false)
                                ->options(TimeUnit::options())
                                ->default('hari'),
                        ])
                            ->columns(2)
                            ->visible(fn (callable $get) => $get('limit_type') === 'waktu'),
                        Forms\Components\TextInput::make('quota_limit_mb')
                            ->label('Batas Kuota')
                            ->numeric()
                            ->suffix('MB')
                            ->placeholder('Unlimited')
                            ->visible(fn (callable $get) => $get('limit_type') === 'kuota'),
                    ])->columns(2),

                Step::make('Router')
                    ->icon('lucide-router')
                    ->schema([
                        Forms\Components\TextInput::make('mikrotik_profile')
                            ->label('User Group (Profil)')
                            ->prefixIcon('heroicon-o-cog-6-tooth')
                            ->default('DEFAULT')
                            ->required(),
                        Forms\Components\Select::make('router_id')
                            ->label('Router (untuk sinkron otomatis)')
                            ->prefixIcon('heroicon-o-server')
                            ->options(fn () => \App\Models\Router::pluck('name', 'id')),
                        Forms\Components\Select::make('ip_pool_id')
                            ->label('Group User')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->options(fn () => \App\Models\IpPool::where('is_active', true)
                                ->whereIn('service_type', ['hotspot', 'both'])
                                ->pluck('name', 'id')),
                        Forms\Components\Select::make('priority')
                            ->label('Prioritas')
                            ->options([
                                1 => '1 (Tertinggi)', 2 => '2', 3 => '3', 4 => '4',
                                5 => '5', 6 => '6', 7 => '7', 8 => 'Default (8)',
                            ])
                            ->default(8)
                            ->required(),
                        Forms\Components\TextInput::make('shared_users')
                            ->label('Shared Users')
                            ->numeric()
                            ->default(1)
                            ->required(),
                    ])->columns(2),

                Step::make('Harga & Deskripsi')
                    ->icon('lucide-tag')
                    ->schema([
                        Forms\Components\TextInput::make('cost_price')
                            ->label('Harga Modal')
                            ->numeric()
                            ->default(0)
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('sale_price')
                            ->label('Harga Jual')
                            ->numeric()
                            ->default(0)
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('Deskripsi')
                            ->columnSpanFull(),
                    ])->columns(2),
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
                Tables\Columns\TextColumn::make('limit_type')
                    ->label('Tipe Limit')
                    ->badge()
                    ->formatStateUsing(fn (LimitType $state) => $state->label()),
                Tables\Columns\TextColumn::make('validity_days')
                    ->label('Masa Berlaku')
                    ->formatStateUsing(fn ($state, $record) => $state == 0 ? 'Unlimited' : $state.' '.ucfirst($record->validity_unit?->value ?? 'hari')),
                Tables\Columns\TextColumn::make('cost_price')->label('Modal')->money('IDR', decimalPlaces: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('sale_price')->label('Jual')->money('IDR', decimalPlaces: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('mikrotik_profile')->label('User Group'),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
                Tables\Columns\IconColumn::make('is_member_only')
                    ->label('Khusus Member')
                    ->boolean()
                    ->tooltip(fn ($record) => $record->is_member_only
                        ? 'Tidak muncul di generate voucher'
                        : 'Muncul di generate voucher (paket voucher biasa)'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('limit_type')->options(LimitType::options()),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
                Tables\Filters\TernaryFilter::make('is_member_only')->label('Khusus Member'),
            ])
                        ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah')
                    ->modalWidth('xl')
                    ->after(function ($record) {
                        if (! $record->router_id) {
                            return;
                        }

                        $result = app(\App\Services\MikrotikService::class)
                            ->syncProfile($record->router, $record);

                        \Filament\Notifications\Notification::make()
                            ->title($result['success'] ? 'Profile berhasil disinkron ke MikroTik' : 'Gagal sinkron ke MikroTik')
                            ->body($result['error'] ?? null)
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton()->modalWidth('xl'),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotspotProfiles::route('/'),
            // Halaman Edit TERPISAH SENGAJA DIHAPUS (permintaan user):
            // selama route 'edit' masih terdaftar di sini, EditAction akan
            // BERPINDAH HALAMAN ke situ dan mengabaikan pengaturan modal
            // (->modalWidth dsb) -- itu sebabnya form wizard dulu selalu
            // terbuka satu halaman penuh. Begitu barisnya dilepas,
            // Filament otomatis membuka form yang sama sebagai POPUP.
        ];
    }
}
