<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PartnerType;
use App\Filament\Resources\PppoeUserResource\Pages;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Services\CustomerRadiusService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PppoeUserResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Customer::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'PPPoE';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'Pelanggan PPPoE';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pelanggan PPPoE';
    }

    protected static ?string $slug = 'pppoe/user';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', CustomerType::Pppoe)->with('latestSession');
    }

    /**
     * Form 3-langkah (wizard) — permintaan Owner: "Outlet" DIGANTI "Biller"
     * di sini, karena yang bertugas menagih pelanggan PPPoE adalah Biller,
     * bukan Outlet (Outlet cuma titip-jual voucher Hotspot). Kolom database
     * yang dipakai TETAP `outlet_id` (tidak ganti skema) — cuma pilihannya
     * sekarang difilter khusus mitra bertipe Biller, dan labelnya "Biller".
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'customer_number', 'phone'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('type')->default('pppoe'),

            Wizard::make([
                Step::make('Data Akun')
                    ->icon('lucide-lock')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->placeholder('Username')
                            ->prefixIcon('heroicon-o-user')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->placeholder('Password')
                            ->prefixIcon('heroicon-o-lock-closed')
                            ->required(),
                        Forms\Components\Select::make('service_package_id')
                            ->label('Profile')
                            ->prefixIcon('heroicon-o-queue-list')
                            ->options(fn () => ServicePackage::where('is_active', true)
                                ->whereIn('type', ['pppoe', 'both'])->pluck('name', 'id'))
                            ->required()
                            ->live(),
                        Forms\Components\Placeholder::make('nas_preview')
                            ->label('NAS / Router')
                            ->content(function (Get $get) {
                                $package = ServicePackage::with('router')->find($get('service_package_id'));

                                return $package?->router?->name ?? 'Mengikuti pengaturan Profile';
                            }),
                        Forms\Components\TextInput::make('static_ip')
                            ->label('IP Address (opsional, kosongkan utk dynamic pool)')
                            ->prefixIcon('heroicon-o-globe-alt')
                            ->ip(),
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

                        // ---- FTTH ----
                        // Koordinat pelanggan berdiri sendiri, TIDAK lagi
                        // menumpang titik ODP (permintaan user) -- kalau
                        // menumpang, semua pelanggan satu ODP menumpuk di
                        // satu titik dan peta tidak bisa dipakai untuk hal
                        // yang paling dibutuhkan di lapangan: mencari rumah
                        // siapa yang mana.
                        //
                        // Mengetik angka koordinat di sini boleh, tapi cara
                        // yang jauh lebih cepat ada di menu FTTH > Peta
                        // Pelanggan: pilih namanya di daftar "Belum
                        // Bertitik", lalu klik posisinya di peta.
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
            // Centang baris tetap perlu tampil walau BulkAction sudah tidak
            // dipakai lagi (Suspend/Aktifkan/Hapus dipindah ke
            // ->headerActions() biasa) -- lihat catatan sama di
            // HotspotUserResource.php.
            ->selectable()
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
                // IP Address SEKARANG dibaca dari sesi RADIUS terakhir
                // (radacct.framedipaddress) — bukan lagi dari static_ip.
                // Ditemukan lewat laporan langsung: static_ip cuma
                // "permintaan" IP dari aplikasi, tapi untuk PPPoE di
                // router ini MikroTik yang benar-benar memutuskan IP-nya
                // sendiri dari pool — jadi angka di static_ip bisa BEDA
                // dari IP asli yang dipakai pelanggan. Ini yang ditampilkan
                // sekarang adalah IP ASLI dari sesi terakhir yang tercatat.
                Tables\Columns\TextColumn::make('latestSession.framedipaddress')
                    ->label('IP Address')
                    ->placeholder('-')
                    ->copyable()
                    ->tooltip(fn ($record) => $record->latestSession?->acctstoptime === null
                        ? 'Sedang online'
                        : ($record->latestSession ? 'Sesi terakhir: '.$record->latestSession->acctstarttime?->format('d M Y H:i') : null))
                    ->visibleFrom('md'),
                // Biller: gabungan penugasan dari menu Biller, bot Telegram, dan
                // field "Biller (opsional)" -- lihat App\Support\BillerInfo.
                \App\Support\BillerInfo::column(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CustomerStatus::options()),
                \App\Support\BillerInfo::filter(),

                // Filter Online/Offline -- dipakai juga sebagai tujuan link
                // dari kartu "Sesi Online"/"Offline" di atas tabel ini
                // (PppoeUserOverview) dan dari kartu Dashboard, lewat URL
                // ?tableFilters[sesi_online][value]=online (atau offline).
                //
                // "Online" dihitung dari radacct (sesi tanpa acctstoptime),
                // BUKAN dari relasi latestSession -- caranya SENGAJA
                // disamakan persis dengan PppoeUserOverview::getCards()
                // (ambil dulu username pelanggan PPPoE di tabel/query ini,
                // map ke format username RADIUS, baru dicocokkan ke
                // radacct) supaya angka di kartu & hasil filter ini selalu
                // cocok, termasuk untuk tenant dengan awalan username
                // RADIUS (RadiusUsername::forRadius()).
                Tables\Filters\SelectFilter::make('sesi_online')
                    ->label('Sesi')
                    ->options(['online' => 'Online', 'offline' => 'Offline'])
                    ->query(function (Builder $query, array $data) {
                        $nilai = $data['value'] ?? null;

                        if (! in_array($nilai, ['online', 'offline'], true)) {
                            return $query;
                        }

                        $usernameRadius = (clone $query)
                            ->pluck('username')
                            ->map(fn ($u) => \App\Support\RadiusUsername::forRadius($u))
                            ->all();

                        $usernameOnline = empty($usernameRadius) ? [] : \App\Models\Radacct::query()
                            ->whereNull('acctstoptime')
                            ->whereIn('username', $usernameRadius)
                            ->pluck('username')
                            ->map(fn ($u) => \App\Support\RadiusUsername::stripPrefix($u))
                            ->all();

                        return $nilai === 'online'
                            ? $query->whereIn('username', $usernameOnline)
                            : $query->whereNotIn('username', $usernameOnline);
                    }),

                // Filter JENIS PAKET (permintaan user). Pilihannya
                // dibatasi ke paket yang memang bisa dipakai PPPoE
                // ('pppoe' atau 'both') supaya daftarnya tidak
                // tercampur paket khusus Hotspot yang tidak akan
                // pernah cocok dengan satu pun baris di tabel ini.
                Tables\Filters\SelectFilter::make('service_package_id')
                    ->label('Jenis Paket')
                    ->options(fn () => ServicePackage::whereIn('type', ['pppoe', 'both'])
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->preload(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah User')
                    ->icon('lucide-plus')
                    ->color('primary')
                    ->extraAttributes(['style' => 'min-width: 9rem; justify-content: center;'])
                    // Provisioning RADIUS dipindah ke sini karena halaman
                    // CreatePppoeUser sudah tidak dipakai (tombol Tambah kini
                    // berupa popup, supaya daftar tabel tetap terlihat).
                    ->after(function ($record) {
                        // Alokasi IP otomatis dari IP Pool DINYALAKAN LAGI —
                        // penyebab penolakan sebelumnya (Filter-Id belum ada,
                        // router tidak tahu profile mana yang dipakai) sudah
                        // diperbaiki. Ini dicoba lagi sebagai tes.
                        $package = $record->servicePackage;

                        if (! $record->static_ip && $package?->ip_pool_id) {
                            $ip = $package->ipPool?->nextAvailableIp();

                            if ($ip) {
                                $record->update(['static_ip' => $ip]);
                            } else {
                                Notification::make()
                                    ->title('IP Pool "'.$package->ipPool->name.'" sudah penuh')
                                    ->body('Pelanggan tetap dibuat, tapi IP belum dibagikan otomatis — isi manual atau tambah rentang pool.')
                                    ->warning()
                                    ->send();
                            }
                        }

                        app(CustomerRadiusService::class)->provisionCustomer($record, $record->servicePackage);
                    }),

                Actions\Action::make('export')
                    ->label('Export CSV')
                    ->icon('lucide-download')
                    ->color('primary')
                    ->extraAttributes(['style' => 'min-width: 9rem; justify-content: center;'])
                    ->action(fn (\App\Services\CsvService $csv) => $csv->exportCustomers(
                        static::getEloquentQuery(),
                        'user-pppoe-'.now()->format('Ymd-His').'.csv'
                    )),

                // Aksi massal (Suspend/Aktifkan/Delete untuk baris tercentang)
                // -- ditaruh di ->headerActions() (bukan ->toolbarActions())
                // supaya jadi SATU baris fisik dengan Tambah User/Export CSV.
                //
                // PENTING: ini Actions\BulkAction ASLI (bukan Action biasa +
                // baca $livewire->getSelectedTableRecords() manual) --
                // sempat dicoba pakai Action biasa, tapi TIDAK RELIABLE,
                // sinkronisasi baris tercentang ke server kadang tidak
                // kebawa (baris keliatan dicentang tapi $records kosong pas
                // aksinya jalan). Filament resmi mendukung taruh BulkAction
                // di ->headerActions() (lihat catatan di source Filament
                // sendiri, HasHeaderActions), jadi cukup dipindah lokasinya
                // saja -- logic pemilihan baris & deselect-nya tetap 100%
                // bawaan Filament yang sudah pasti benar.
                Actions\BulkAction::make('bulk_suspend')
                    ->extraAttributes([
                        // Tombol aksi massal ini JANGAN disembunyikan total
                        // oleh Filament waktu belum ada baris dicentang
                        // (bawaannya x-show="getSelectedRecordsCount()"),
                        // tapi tetap tampil dalam keadaan pudar. Class
                        // "tya-mute" dipasang/dilepas otomatis oleh Alpine
                        // mengikuti jumlah baris tercentang -- jadi begitu
                        // checkbox dicentang, tombolnya LANGSUNG menyala
                        // penuh lagi tanpa reload.
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
                        // Tombol aksi massal ini JANGAN disembunyikan total
                        // oleh Filament waktu belum ada baris dicentang
                        // (bawaannya x-show="getSelectedRecordsCount()"),
                        // tapi tetap tampil dalam keadaan pudar. Class
                        // "tya-mute" dipasang/dilepas otomatis oleh Alpine
                        // mengikuti jumlah baris tercentang -- jadi begitu
                        // checkbox dicentang, tombolnya LANGSUNG menyala
                        // penuh lagi tanpa reload.
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
                        // Tombol aksi massal ini JANGAN disembunyikan total
                        // oleh Filament waktu belum ada baris dicentang
                        // (bawaannya x-show="getSelectedRecordsCount()"),
                        // tapi tetap tampil dalam keadaan pudar. Class
                        // "tya-mute" dipasang/dilepas otomatis oleh Alpine
                        // mengikuti jumlah baris tercentang -- jadi begitu
                        // checkbox dicentang, tombolnya LANGSUNG menyala
                        // penuh lagi tanpa reload.
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
                // GANTI PAKET — lihat penjelasan lengkap kenapa sesi
                // harus diputus di App\Actions\ChangeCustomerPackageAction.
                // Ringkasnya: atribut RADIUS (kecepatan/profile) cuma
                // dibaca router saat AUTENTIKASI, jadi selama sesi lama
                // masih hidup, paket baru belum benar-benar dipakai.
                Actions\Action::make('ganti_paket')
                    ->label('Ganti Paket')
                    ->icon('lucide-repeat')
                    ->color('warning')
                    ->iconButton()
                    ->modalHeading(fn (Customer $record) => 'Ganti Paket — '.$record->name)
                    ->modalDescription('Paket baru langsung ditulis ke RADIUS, lalu sesi yang sedang aktif diputus supaya pelanggan konek ulang memakai paket baru. Untuk PPPoE, modem pelanggan biasanya dial ulang sendiri dalam hitungan detik.')
                    ->modalSubmitActionLabel('Ganti & Putuskan Sesi')
                    ->schema([
                        Forms\Components\Placeholder::make('paket_sekarang')
                            ->label('Paket Sekarang')
                            ->content(fn (Customer $record) => $record->servicePackage?->name ?? '-'),
                        Forms\Components\Select::make('service_package_id')
                            ->label('Paket Baru')
                            ->options(fn (Customer $record) => ServicePackage::where('is_active', true)
                                ->whereIn('type', ['pppoe', 'both'])
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

                        // Kalau ada peringatan (mis. sesi gagal diputus),
                        // notifikasinya dibuat menetap supaya operator
                        // sempat membacanya, tidak keburu hilang sendiri.
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
                    ->modalHeading('Ubah User PPPoE')
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
            'index' => Pages\ListPppoeUsers::route('/'),
            // Halaman Edit TERPISAH SENGAJA DIHAPUS (permintaan user):
            // selama route 'edit' masih terdaftar di sini, EditAction akan
            // BERPINDAH HALAMAN ke situ dan mengabaikan pengaturan modal
            // (->modalWidth dsb) -- itu sebabnya form wizard dulu selalu
            // terbuka satu halaman penuh. Begitu barisnya dilepas,
            // Filament otomatis membuka form yang sama sebagai POPUP.
        ];
    }
}
