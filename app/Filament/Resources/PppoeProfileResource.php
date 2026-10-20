<?php

namespace App\Filament\Resources;

use App\Enums\LimitType;
use App\Enums\TimeUnit;
use App\Filament\Resources\PppoeProfileResource\Pages;
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

class PppoeProfileResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = ServicePackage::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    // Grup menu biasa "Profile Paket" (permintaan user, ditaruh di
    // bawah grup Mitra) -- percobaan sebelumnya (submenu bersarang
    // pakai $navigationParentItem, lalu halaman hub) sudah dibatalkan,
    // sekarang balik jadi item menu biasa yang langsung kelihatan.
    protected static string|\UnitEnum|null $navigationGroup = 'Profile Paket';

    protected static ?string $navigationLabel = 'PPPoE';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'pppoe/profile';

    public static function getModelLabel(): string
    {
        return 'Profile PPPoE';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Profile PPPoE';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', ['pppoe', 'both']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('type')->default('pppoe'),

            // Field yang dihapus dari TAMPILAN (Prioritas, Shared Users,
            // Harga Modal) TETAP ADA di data — cuma disembunyikan pakai
            // Hidden + nilai default, BUKAN dihapus total. Kalau dihapus
            // total, kolomnya di database yang wajib diisi bisa bikin
            // gagal simpan (pernah kejadian kasus serupa sebelumnya).
            Forms\Components\Hidden::make('priority')->default(8),
            Forms\Components\Hidden::make('shared_users')->default(1),
            Forms\Components\Hidden::make('cost_price')->default(0),

            Wizard::make([
                Step::make('Informasi Produk')
                    ->icon('lucide-router')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Produk')
                            ->placeholder('mis. PPPoE 20M-200rb')
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

                Step::make('Batas Pemakaian')
                    ->icon('lucide-gauge')
                    ->columns(2)
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
                    ]),

                Step::make('Router')
                    ->icon('lucide-server')
                    ->columns(2)
                    ->schema([
                        // Nama ini = nama PPP Profile di ROUTER (dan yang dikirim
                        // RADIUS sebagai Mikrotik-Group). Sengaja TANPA nilai
                        // bawaan: dulu bawaannya "DEFAULT", jadi paket yang lupa
                        // diganti langsung memakai nama itu -- berbagi (dan saling
                        // menimpa) satu profile di router.
                        Forms\Components\TextInput::make('mikrotik_profile')
                            ->label('User Group (Profil)')
                            ->prefixIcon('heroicon-o-cog-6-tooth')
                            ->placeholder('mis. PAKET-10M')
                            ->helperText('Nama PPP Profile di MikroTik -- harus SAMA PERSIS dengan yang ada di router. Tiap paket wajib punya nama berbeda; jangan pakai default / default-encryption.')
                            ->trim()
                            ->required()
                            ->rules([
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                    // Tanpa peduli huruf besar/kecil: "DEFAULT" hanya
                                    // beda huruf dari profile bawaan "default".
                                    if (in_array(strtolower(trim((string) $value)), ['default', 'default-encryption'], true)) {
                                        $fail('Nama "'.trim((string) $value).'" sama dengan profile bawaan MikroTik. Pakai nama lain, mis. PAKET-10M.');
                                    }
                                },
                            ])
                            // Unik di antara paket PPPoE pada router yang sama --
                            // dua paket bernama sama berarti satu profile di router
                            // yang rate-limit, pool, dan gateway-nya saling menimpa.
                            ->unique(
                                table: ServicePackage::class,
                                column: 'mikrotik_profile',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, callable $get) => $rule
                                    ->whereIn('type', ['pppoe', 'both'])
                                    ->when(
                                        $get('router_id'),
                                        fn ($r, $routerId) => $r->where('router_id', $routerId),
                                        fn ($r) => $r->whereNull('router_id'),
                                    ),
                            )
                            ->validationMessages([
                                'unique' => 'Nama profile ini sudah dipakai paket PPPoE lain di router yang sama. Pakai nama yang berbeda.',
                            ]),

                        Forms\Components\Select::make('router_id')
                            ->label('Router')
                            ->prefixIcon('heroicon-o-server')
                            ->options(fn () => \App\Models\Router::pluck('name', 'id')),

                        Forms\Components\Select::make('ip_pool_id')
                            ->label('Group User')
                            ->options(fn () => \App\Models\IpPool::where('is_active', true)
                                ->whereIn('service_type', ['pppoe', 'both'])
                                ->pluck('name', 'id'))
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
                Tables\Columns\TextColumn::make('limit_type')
                    ->label('Tipe Limit')
                    ->badge()
                    ->formatStateUsing(fn (LimitType $state) => $state->label()),
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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('limit_type')->options(LimitType::options()),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
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
                            ->title($result['success'] ? 'PPP Profile berhasil disinkron ke MikroTik' : 'Gagal sinkron ke MikroTik')
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
            'index' => Pages\ListPppoeProfiles::route('/'),
            // Halaman Edit TERPISAH SENGAJA DIHAPUS (permintaan user):
            // selama route 'edit' masih terdaftar di sini, EditAction akan
            // BERPINDAH HALAMAN ke situ dan mengabaikan pengaturan modal
            // (->modalWidth dsb) -- itu sebabnya form wizard dulu selalu
            // terbuka satu halaman penuh. Begitu barisnya dilepas,
            // Filament otomatis membuka form yang sama sebagai POPUP.
        ];
    }
}
