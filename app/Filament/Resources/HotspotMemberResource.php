<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\HotspotMemberResource\Pages;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HotspotMemberResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    // Dipindah dari grup "Hotspot" ke "Pelanggan" (permintaan user) --
    // Member itu pelanggan langganan bulanan (mirip PPPoE/Static IP),
    // bukan penjualan voucher jalan seperti isi grup Hotspot lainnya.
    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Member';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'hotspot/member';

    // Menu "Member" DISEMBUNYIKAN dari sidebar (Menu User yang sudah ada
    // sekarang berperan menggantikannya, cuma nama label-nya diganti
    // jadi "Member"). Resource ini TETAP AKTIF (route/halaman tidak
    // dihapus), cuma tidak lagi muncul di navigasi.
    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getModelLabel(): string
    {
        return 'Member Hotspot';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Member Hotspot (Langganan Bulanan)';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', CustomerType::Hotspot);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('type')->default('hotspot'),

            Wizard::make([
                Step::make('Data Pelanggan')
                    ->icon('lucide-user')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Nama Pelanggan')->required(),
                        Forms\Components\TextInput::make('phone')->label('No HP')->tel(),
                        Forms\Components\Textarea::make('address')->label('Alamat')->columnSpanFull(),
                        Forms\Components\Select::make('service_package_id')
                            ->label('Profile')
                            // is_member_only WAJIB true di sini (permintaan
                            // user) -- Profile Voucher biasa TIDAK BOLEH
                            // ikut dipilih buat Member (langganan bulanan,
                            // beda siklus dari Voucher sekali pakai). Sama
                            // persis dengan filter di bot Telegram
                            // (/addmember, lihat showMemberPackageMenu() di
                            // TelegramWebhookController).
                            ->options(fn () => ServicePackage::where('is_active', true)
                                ->where('is_member_only', true)
                                ->whereIn('type', ['hotspot', 'both'])->pluck('name', 'id'))
                            ->required(),
                        Forms\Components\Select::make('outlet_id')
                            ->label('Outlet (opsional)')
                            ->options(fn () => Outlet::pluck('name', 'id')),
                    ])
                    ->columns(2),

                Step::make('Kredensial Login')
                    ->icon('lucide-lock')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->required(),
                    ])
                    ->columns(2),

                Step::make('Billing')
                    ->icon('lucide-credit-card')
                    ->schema([
                        Forms\Components\TextInput::make('billing_day')
                            ->label('Tanggal Jatuh Tempo Tiap Bulan')
                            ->numeric()->minValue(1)->maxValue(28)->default(1)->required(),
                        Forms\Components\DatePicker::make('installation_date')->label('Tanggal Pasang'),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(CustomerStatus::options())
                            ->default('active')
                            ->required(),
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
            ->columns([
                // ->visibleFrom('md'): kolom tetap ada di desktop/tablet,
                // disembunyikan di HP (<768px) saja -- supaya tabel muat 1
                // layar. Lihat catatan sama di SoldVoucherResource.
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()->visibleFrom('md'),
                // Kolom STATUS (Online/Offline), tepat setelah kolom No:
                // titik hijau = Online, oranye = Offline. Bisa di-sort
                // (klik judul kolom: sekali = Offline di atas, dua kali =
                // Online di atas). Logika ada di App\Support\OnlineStatus --
                // definisi "online" sama persis dengan kartu Sesi Online.
                \App\Support\OnlineStatus::column(),
                Tables\Columns\TextColumn::make('customer_number')->label('No. Pelanggan')->searchable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('servicePackage.name')->label('Profile'),
                Tables\Columns\TextColumn::make('username')->label('Username')->copyable(),
                // TOMBOL GESER status — bisa diklik langsung dari tabel,
                // tanpa membuka form Edit (permintaan user).
                //
                // Beda dengan kolom is_active di menu lain yang isinya
                // cuma benar/salah, status pelanggan punya TIGA keadaan:
                // Aktif, Suspend, dan Isolir. Sebuah tombol geser cuma
                // punya dua posisi, jadi:
                //   - digeser NYALA  -> Aktif
                //   - digeser MATI   -> Suspend
                // Pelanggan yang sedang Isolir juga tampil sebagai mati.
                // Supaya bedanya tidak hilang, keadaan sebenarnya
                // ditampilkan sebagai tooltip saat kursor diarahkan ke
                // tombolnya.
                //
                // PENTING: mengubah status WAJIB ikut menulis ke RADIUS.
                // Kalau cuma kolom di database yang diubah, pelanggan
                // yang "dimatikan" tetap bisa internetan, dan yang
                // "dinyalakan" tetap ditolak login — persis masalah
                // blokir nyasar yang sempat terjadi.
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
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet')->placeholder('-')->visibleFrom('md'),
                // Biller penagih member ini -- lihat App\Support\BillerInfo.
                \App\Support\BillerInfo::column(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CustomerStatus::options()),
                \App\Support\BillerInfo::filter(),
                // Filter Online/Offline (sama seperti di User PPPoE).
                \App\Support\OnlineStatus::filter(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah')
                    ->modalWidth('2xl')
                    // Provisioning RADIUS dipindah ke sini (halaman
                    // CreateHotspotMember sudah tidak dipakai).
                    ->after(fn ($record) => app(CustomerRadiusService::class)
                        ->provisionCustomer($record, $record->servicePackage)),

                Actions\Action::make('export')
                    ->label('Export CSV')
                    ->icon('lucide-download')
                    ->color('gray')
                    ->action(fn (\App\Services\CsvService $csv) => $csv->exportCustomers(
                        static::getEloquentQuery(),
                        'member-hotspot-'.now()->format('Ymd-His').'.csv'
                    )),

                Actions\Action::make('import')
                    ->label('Import CSV')
                    ->icon('lucide-upload')
                    ->color('gray')
                    ->modalDescription('Kolom wajib: name, username, password, service_package. Kolom opsional: phone, address, billing_day, status. Baris dengan username yang sudah ada akan dilewati.')
                    ->schema([
                        Forms\Components\FileUpload::make('file')
                            ->label('Berkas CSV')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data, \App\Services\CsvService $csv) {
                        $result = $csv->importCustomers($data['file']->getRealPath(), \App\Enums\CustomerType::Hotspot);

                        Notification::make()
                            ->title("{$result['imported']} pelanggan diimport, {$result['skipped']} dilewati")
                            ->body(empty($result['errors']) ? null : implode("\n", array_slice($result['errors'], 0, 5)))
                            ->status($result['imported'] > 0 ? 'success' : 'warning')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                // GANTI PAKET — penjelasan lengkap kenapa sesi harus
                // diputus ada di App\Actions\ChangeCustomerPackageAction.
                // Catatan khusus Hotspot: setelah diputus, pelanggan
                // perlu LOGIN ULANG (beda dengan PPPoE yang dial sendiri),
                // kecuali perangkatnya sudah terikat MAC/login otomatis.
                Actions\Action::make('ganti_paket')
                    ->label('Ganti Paket')
                    ->icon('lucide-repeat')
                    ->color('warning')
                    ->iconButton()
                    ->modalHeading(fn (Customer $record) => 'Ganti Paket — '.$record->name)
                    ->modalDescription('Paket baru langsung ditulis ke RADIUS, lalu sesi yang sedang aktif diputus supaya paket baru benar-benar dipakai. Khusus Hotspot, pelanggan perlu login ulang setelah diputus.')
                    ->modalSubmitActionLabel('Ganti & Putuskan Sesi')
                    ->schema([
                        Forms\Components\Placeholder::make('paket_sekarang')
                            ->label('Paket Sekarang')
                            ->content(fn (Customer $record) => $record->servicePackage?->name ?? '-'),
                        Forms\Components\Select::make('service_package_id')
                            ->label('Paket Baru')
                            ->options(fn (Customer $record) => ServicePackage::where('is_active', true)
                                ->where('is_member_only', true)
                                ->whereIn('type', ['hotspot', 'both'])
                                ->where('id', '!=', $record->service_package_id)
                                ->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (Customer $record, array $data) {
                        $paket = ServicePackage::find($data['service_package_id']);

                        if (! $paket) {
                            Notification::make()->title('Paket tidak ditemukan')->danger()->send();

                            return;
                        }

                        $hasil = app(\App\Actions\ChangeCustomerPackageAction::class)->execute($record, $paket);

                        $notif = Notification::make()
                            ->title($hasil['message'])
                            ->body($hasil['warning'])
                            ->status(! $hasil['success'] ? 'danger' : ($hasil['warning'] ? 'warning' : 'success'));

                        if ($hasil['warning']) {
                            $notif->persistent();
                        }

                        $notif->send();
                    }),

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
                    ->modalHeading('Ubah Member Hotspot')
                    // BUG YANG DIPERBAIKI: sebelumnya Edit sama sekali TIDAK
                    // menulis ulang data ke RADIUS. Akibatnya, mengubah
                    // password atau paket lewat Edit hanya berubah di
                    // aplikasi — RADIUS masih memakai nilai lama, sehingga
                    // pelanggan gagal login padahal di layar terlihat benar.
                    // Dan kalau baris RADIUS-nya hilang, "Edit lalu Simpan"
                    // tidak akan memulihkannya.
                    //
                    // provisionCustomer() memakai updateOrCreate, jadi aman
                    // dipanggil berulang: menimpa, bukan menggandakan.
                    ->after(function ($record) {
                        if (! $record->servicePackage) {
                            return;
                        }

                        app(CustomerRadiusService::class)
                            ->provisionCustomer($record, $record->servicePackage);

                        // Menyelaraskan blokir dengan status di aplikasi —
                        // DUA ARAH, dan arah kedua ini yang selama ini
                        // hilang:
                        //
                        // - Bukan Aktif -> pasang blokir, supaya menyimpan
                        //   Edit tidak malah menghidupkan akses pelanggan
                        //   yang sedang disuspend.
                        //
                        // - Aktif -> HAPUS blokir yang mungkin masih
                        //   tertinggal. Kasus nyata: pelanggan diisolir
                        //   otomatis karena tagihan lewat tempo, lalu
                        //   statusnya dikembalikan ke Aktif lewat form Edit.
                        //   Statusnya berubah di aplikasi, tapi baris
                        //   "Auth-Type := Reject" di RADIUS tidak pernah
                        //   dihapus — jadi pelanggan tetap ditolak login
                        //   padahal di layar tertulis Aktif.
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
            'index' => Pages\ListHotspotMembers::route('/'),
            // Halaman Edit TERPISAH SENGAJA DIHAPUS (permintaan user):
            // selama route 'edit' masih terdaftar di sini, EditAction akan
            // BERPINDAH HALAMAN ke situ dan mengabaikan pengaturan modal
            // (->modalWidth dsb) -- itu sebabnya form wizard dulu selalu
            // terbuka satu halaman penuh. Begitu barisnya dilepas,
            // Filament otomatis membuka form yang sama sebagai POPUP.
        ];
    }
}
