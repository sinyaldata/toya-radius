<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PartnerType;
use App\Filament\Resources\StaticIpUserResource\Pages;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Pelanggan Static IP -- diikat lewat DHCP Static Lease MikroTik (MAC
 * Address -> IP tetap), BUKAN login username/password seperti PPPoE.
 * Strukturnya SENGAJA dibuat semirip mungkin dengan PppoeUserResource
 * (wizard 3 langkah, tombol geser Aktif/Suspend, dst) supaya operator
 * yang sudah terbiasa dengan menu PPPoE tidak perlu belajar pola baru.
 *
 * Kolom `username`/`password` di tabel customers WAJIB diisi (NOT
 * NULL + unique untuk username) walau pelanggan tipe ini tidak benar-
 * benar login pakai keduanya -- jadi nilainya DIBUAT OTOMATIS dari
 * nama pelanggan (lihat generateUsername() & ->mutateFormDataUsing()
 * di bawah), bukan diminta ketik manual seperti di form PPPoE.
 */
class StaticIpUserResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Customer::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Static IP';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'Pelanggan Static IP';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pelanggan Static IP';
    }

    protected static ?string $slug = 'pppoe/static-ip-user';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', CustomerType::StaticIp);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'mac_address', 'static_ip', 'customer_number', 'phone'];
    }

    /**
     * Username unik acak dari nama pelanggan -- MURNI identitas
     * internal aplikasi (dipakai di kolom database & di beberapa
     * tempat yang mengasumsikan semua Customer punya username, mis.
     * pencarian pelanggan lewat bot Telegram), TIDAK PERNAH benar-
     * benar dipakai untuk login RADIUS (lihat CustomerRadiusService::
     * provisionStaticIpCustomer() -- pelanggan tipe ini tidak ditulis
     * ke radcheck sama sekali).
     */
    protected static function generateUsername(string $name): string
    {
        $dasar = Str::slug($name) ?: 'pelanggan';

        do {
            $kandidat = $dasar.'-'.random_int(1000, 9999);
        } while (Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $kandidat)->exists());

        return $kandidat;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('type')->default('static_ip'),

            Wizard::make([
                Step::make('Data Jaringan')
                    ->icon('lucide-cable')
                    ->schema([
                        Forms\Components\TextInput::make('mac_address')
                            ->label('MAC Address')
                            ->placeholder('AA:BB:CC:DD:EE:FF')
                            ->prefixIcon('heroicon-o-cpu-chip')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('static_ip')
                            ->label('IP Address')
                            ->placeholder('mis. 192.168.10.50')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->ip(),
                        Forms\Components\Select::make('service_package_id')
                            ->label('Paket')
                            ->prefixIcon('heroicon-o-queue-list')
                            ->options(fn () => ServicePackage::where('is_active', true)
                                ->where('type', 'static_ip')->pluck('name', 'id'))
                            ->required()
                            ->live(),
                        Forms\Components\Placeholder::make('nas_preview')
                            ->label('NAS / Router')
                            ->content(function (Get $get) {
                                $package = ServicePackage::with('router')->find($get('service_package_id'));

                                return $package?->router?->name ?? 'Paket ini belum diisi Router -- isi dulu di menu Profile Static IP.';
                            }),
                    ])
                    ->columns(2),

                Step::make('Data Pelanggan')
                    ->icon('lucide-user')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Nama Pelanggan')
                            ->placeholder('Nama Pelanggan')->prefixIcon('heroicon-o-identification')->required(),
                        Forms\Components\TextInput::make('phone')->label('No HP')
                            ->placeholder('08xxxx')->prefixIcon('heroicon-o-phone')->tel(),
                        Forms\Components\Textarea::make('address')->label('Alamat')->columnSpanFull(),

                        // ---- FTTH (sama seperti form PPPoE) ----
                        Forms\Components\Select::make('odp_id')
                            ->label('ODP')
                            ->placeholder('Belum ditentukan')
                            ->prefixIcon('lucide-box')
                            ->options(fn () => \App\Models\Odp::where('is_active', true)
                                ->orderBy('name')->pluck('name', 'id')),
                        Forms\Components\TextInput::make('odp_port')
                            ->label('Port di ODP')
                            ->placeholder('mis. 3')
                            ->maxLength(20),
                        Forms\Components\TextInput::make('latitude')
                            ->label('Latitude')
                            ->placeholder('mis. -8.5041234')
                            ->numeric(),
                        Forms\Components\TextInput::make('longitude')
                            ->label('Longitude')
                            ->placeholder('mis. 116.5231234')
                            ->numeric(),
                    ])
                    ->columns(2),

                Step::make('Data Pembayaran')
                    ->icon('lucide-banknote')
                    ->schema([
                        Forms\Components\TextInput::make('billing_day')
                            ->label('Tanggal Jatuh Tempo Tiap Bulan')
                            ->numeric()->minValue(1)->maxValue(28)->default(1)->required(),
                        Forms\Components\DatePicker::make('installation_date')->label('Tanggal Pasang'),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->prefixIcon('heroicon-o-check-circle')
                            ->options(CustomerStatus::options())
                            ->default('active')
                            ->required(),
                        Forms\Components\Select::make('outlet_id')
                            ->label('Biller (opsional)')
                            ->prefixIcon('heroicon-o-building-storefront')
                            ->options(fn () => Outlet::where('partner_type', PartnerType::Biller)->pluck('name', 'id')),
                        Forms\Components\Textarea::make('notes')->label('Catatan')->columnSpanFull(),
                    ])
                    ->columns(2),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => \App\Support\BillerInfo::eagerLoad($query))
            ->selectable()
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()->visibleFrom('md'),
                // Status Online/Offline lewat ARP-ping router -- lihat
                // App\Services\StaticIpOnlineService (dicek otomatis tiap 5 menit).
                \App\Support\StaticIpStatus::column(),
                Tables\Columns\TextColumn::make('customer_number')->label('No. Pelanggan')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('servicePackage.name')->label('Paket'),
                Tables\Columns\TextColumn::make('mac_address')->label('MAC Address')->copyable()->placeholder('-'),
                Tables\Columns\TextColumn::make('static_ip')->label('IP Address')->copyable()->placeholder('-'),
                Tables\Columns\ToggleColumn::make('status')
                    ->label('Aktif')
                    ->onColor('primary')
                    ->tooltip(fn (Customer $record) => 'Status: '.$record->status->label())
                    ->getStateUsing(fn (Customer $record) => $record->status === CustomerStatus::Active)
                    ->updateStateUsing(function (Customer $record, $state) {
                        $radius = app(CustomerRadiusService::class);

                        if ($state) {
                            $record->update(['status' => CustomerStatus::Active]);
                            $radius->reactivate($record);
                        } else {
                            $record->update(['status' => CustomerStatus::Suspended]);
                            $radius->suspend($record);
                        }

                        return $state;
                    }),
                // Biller: gabungan penugasan dari menu Biller, bot Telegram, dan
                // field "Biller (opsional)" -- lihat App\Support\BillerInfo.
                \App\Support\BillerInfo::column(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CustomerStatus::options()),
                \App\Support\StaticIpStatus::filter(),
                \App\Support\BillerInfo::filter(),
                Tables\Filters\SelectFilter::make('service_package_id')
                    ->label('Paket')
                    ->options(fn () => ServicePackage::where('type', 'static_ip')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->preload(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah Pelanggan')
                    ->icon('lucide-plus')
                    ->color('primary')
                    ->extraAttributes(['style' => 'min-width: 9rem; justify-content: center;'])
                    // Username/password DIISI OTOMATIS di sini (bukan
                    // diminta lewat form) -- lihat catatan di
                    // generateUsername() di atas kenapa keduanya tetap
                    // harus ada walau tidak dipakai login.
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['username'] = static::generateUsername($data['name'] ?? 'pelanggan');
                        $data['password'] = Str::random(16);

                        return $data;
                    })
                    ->after(function ($record) {
                        if ($record->servicePackage) {
                            app(CustomerRadiusService::class)->provisionCustomer($record, $record->servicePackage);
                        }
                    }),

                Actions\BulkAction::make('bulk_suspend')
                    ->extraAttributes([
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Suspend')
                    ->icon('lucide-ban')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, CustomerRadiusService $customerRadiusService) {
                        foreach ($records as $record) {
                            if ($record->status === CustomerStatus::Active) {
                                $record->update(['status' => CustomerStatus::Suspended]);
                                $customerRadiusService->suspend($record);
                            }
                        }

                        Notification::make()->title($records->count().' pelanggan disuspend')->success()->send();
                    }),
                Actions\BulkAction::make('bulk_reactivate')
                    ->extraAttributes([
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Aktifkan')
                    ->icon('lucide-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, CustomerRadiusService $customerRadiusService) {
                        foreach ($records as $record) {
                            if ($record->status !== CustomerStatus::Active) {
                                $record->update(['status' => CustomerStatus::Active]);
                                $customerRadiusService->reactivate($record);
                            }
                        }

                        Notification::make()->title($records->count().' pelanggan diaktifkan kembali')->success()->send();
                    }),
                Actions\BulkAction::make('bulk_delete')
                    ->extraAttributes([
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Hapus')
                    ->icon('lucide-trash-2')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus pelanggan terpilih')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        $count = $records->count();

                        foreach ($records as $record) {
                            $record->delete();
                        }

                        Notification::make()->title($count.' pelanggan dihapus')->success()->send();
                    }),
            ])
            ->recordActions([
                \App\Support\StaticIpStatus::checkAction(),
                Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('lucide-ban')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->visible(fn (Customer $record) => $record->status === CustomerStatus::Active)
                    ->action(function (Customer $record, CustomerRadiusService $customerRadiusService) {
                        $record->update(['status' => CustomerStatus::Suspended]);
                        $customerRadiusService->suspend($record);
                        Notification::make()->title('Pelanggan disuspend')->success()->send();
                    }),
                Actions\Action::make('reactivate')
                    ->label('Aktifkan Lagi')
                    ->icon('lucide-check-circle')
                    ->color('success')
                    ->iconButton()
                    ->visible(fn (Customer $record) => $record->status !== CustomerStatus::Active)
                    ->action(function (Customer $record, CustomerRadiusService $customerRadiusService) {
                        $record->update(['status' => CustomerStatus::Active]);
                        $customerRadiusService->reactivate($record);
                        Notification::make()->title('Pelanggan diaktifkan kembali')->success()->send();
                    }),
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalWidth('2xl')
                    ->modalHeading('Ubah Pelanggan Static IP')
                    // Sama seperti PppoeUserResource: Edit WAJIB ikut
                    // menulis ulang ke MikroTik (lease + queue), dan
                    // menyelaraskan status blokir dua arah -- lihat
                    // komentar panjang di PppoeUserResource.php kalau
                    // mau tahu detail bug yang pernah terjadi karena ini
                    // dilewatkan.
                    ->after(function ($record) {
                        if (! $record->servicePackage) {
                            return;
                        }

                        app(CustomerRadiusService::class)->provisionCustomer($record, $record->servicePackage);

                        if ($record->status !== CustomerStatus::Active) {
                            app(CustomerRadiusService::class)->suspend($record);
                        } else {
                            app(CustomerRadiusService::class)->reactivate($record);
                        }
                    }),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaticIpUsers::route('/'),
        ];
    }
}
