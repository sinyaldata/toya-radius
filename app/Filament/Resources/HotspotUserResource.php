<?php

namespace App\Filament\Resources;

use App\Actions\GenerateVoucherBatchAction;
use App\Actions\SellVoucherAction;
use App\Enums\PartnerType;
use App\Enums\PaymentMethod;
use App\Enums\VoucherCodeCombination;
use App\Enums\VoucherLoginMethod;
use App\Enums\VoucherStatus;
use App\Filament\Resources\HotspotUserResource\Pages;
use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Models\Voucher;
use App\Services\CsvService;
use App\Services\VoucherGeneratorService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class HotspotUserResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Voucher::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Hotspot';

    protected static ?string $navigationLabel = 'Voucher';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'hotspot/user';

    // Voucher yang statusnya sudah Expired otomatis hilang dari menu ini —
    // riwayatnya tetap ada, cukup dilihat lewat filter Status kalau perlu,
    // atau lewat Voucher Terjual.
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // "outlet" ikut di-eager-load di sini supaya kolom "Created By"
        // bisa jatuh balik ke nama outlet (lihat closure kolomnya di
        // bawah) tanpa nambah query per baris.
        return parent::getEloquentQuery()
            ->where('status', '!=', VoucherStatus::Expired)
            ->with(['outlet', 'profile.router', 'generatedBy']);
    }

    public static function getModelLabel(): string
    {
        return 'User Hotspot';
    }

    public static function getPluralModelLabel(): string
    {
        return 'User Hotspot';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['username', 'password'];
    }

    /**
     * Form ini dipakai untuk "Tambah User" — membuat SATU voucher manual
     * (mis. untuk tamu/karyawan), bukan generate massal.
     *
     * REDESIGN: dikelompokkan pakai jarak & tipografi (label kecil huruf
     * kapital, garis pemisah tipis) — BUKAN kotak/Section berbingkai
     * bertumpuk seperti sebelumnya. Field utama (Username/Password)
     * dibuat lebih besar & menonjol, field sekunder (harga, status)
     * lebih ringkas di bawahnya.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Group::make([
                Forms\Components\TextInput::make('username')
                    ->label('Username')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->prefixIcon('heroicon-o-user')
                    ->placeholder('mis. budi123')
                    ->extraInputAttributes(['style' => 'font-size: 1.05rem; font-weight: 400;']),
                Forms\Components\TextInput::make('password')
                    ->label('Password')
                    ->required()
                    ->prefixIcon('heroicon-o-lock-closed')
                    ->placeholder('min 6 karakter')
                    ->extraInputAttributes(['style' => 'font-size: 1.05rem; font-family: monospace;']),
            ])->columns(2),

            Group::make([
                Forms\Components\Select::make('voucher_profile_id')
                    ->label('Profile')
                    // is_member_only excluded (permintaan user) -- paket
                    // "Khusus Member" tidak boleh ikut jadi pilihan saat
                    // generate/tambah voucher, sama seperti di bot
                    // Telegram (lihat showPackageMenu() di
                    // TelegramWebhookController).
                    ->options(fn () => ServicePackage::where('is_active', true)
                        ->where('is_member_only', false)
                        ->whereIn('type', ['hotspot', 'both'])
                        ->get()
                        ->filter(fn ($p) => $p->isAvailableForOutlet(auth()->user()?->outlet_id))
                        ->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $package = ServicePackage::find($state);

                        if ($package) {
                            $set('cost_price', $package->cost_price);
                            $set('sale_price', $package->sale_price);
                        }
                    }),
                Forms\Components\Select::make('outlet_id')
                    ->label('Outlet')
                    ->placeholder('Kosongkan = stok pusat')
                    // Peringatan ini SENGAJA (permintaan user): dulu voucher
                    // yang dikosongkan Outlet-nya ("stok pusat") jadi
                    // membingungkan -- akun Outlet TIDAK BISA melihatnya
                    // sama sekali di menu mana pun (lihat OutletScope pada
                    // Voucher: kalau outlet_id NULL, syarat "outlet_id =
                    // outlet milik akun itu" tidak pernah cocok). Itu
                    // memang perilaku yang diinginkan (Outlet cuma boleh
                    // lihat stoknya sendiri), tapi tanpa keterangan apa pun
                    // terlihat seperti voucher-nya hilang/tidak tersinkron.
                    ->helperText('Kosongkan hanya kalau voucher ini memang belum dibagikan ke outlet manapun. Voucher tanpa Outlet TIDAK akan terlihat oleh akun Outlet di menu manapun (termasuk bot Telegram) sampai nanti ditugaskan ke sebuah Outlet.')
                    ->options(fn () => Outlet::where('is_active', true)->where('partner_type', '!=', PartnerType::Biller)->pluck('name', 'id')),
            ])->columns(2),

            Group::make([
                Forms\Components\TextInput::make('cost_price')
                    ->label('Harga Modal')->numeric()->prefix('Rp')->default(0)->required(),
                Forms\Components\TextInput::make('sale_price')
                    ->label('Harga Jual')->numeric()->prefix('Rp')->default(0)->required(),
            ])->columns(2),

            Group::make([
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(VoucherStatus::options())
                    ->default('belum_terjual')
                    ->required(),
                Forms\Components\Toggle::make('auto_bind_mac')
                    ->label('Auto-bind MAC')
                    ->inline(false),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Centang baris (checkbox) tetap perlu tampil walau tombol
            // Print/Disable/Hapus SUDAH TIDAK lagi didaftarkan lewat
            // ->toolbarActions()/BulkAction (dipindah ke ->headerActions()
            // biasa, lihat catatan di bawah) -- normalnya Filament
            // otomatis menyalakan checkbox HANYA kalau ada BulkAction
            // terdaftar, jadi di sini dipaksa nyala manual supaya baris
            // tetap bisa dicentang seperti biasa.
            ->selectable()
            // Permintaan user: tampilan default 20 baris per halaman
            // (dulu langsung 100). Pilihan 100/200/500/1000 tetap ada
            // buat yang mau lihat lebih banyak sekaligus.
            ->paginationPageOptions(\App\Support\HalamanHp::opsi([20, 100, 200, 500, 1000]))
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),

                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Username disalin')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('password')
                    ->label('Password')
                    ->copyable()
                    ->copyMessage('Password disalin')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->tooltip('Copy password'),

                Tables\Columns\TextColumn::make('profile.name')
                    ->label('Profile')
                    ->sortable()
                    ->placeholder('-')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('profile.router.name')
                    ->label('NAS')
                    ->placeholder('-')
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('Stok Pusat'),

                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Harga')
                    ->money('IDR', decimalPlaces: 0)
                    ->weight('semibold')
                    ->alignEnd(),

                // Bind MAC — sebelumnya cuma ikon centang/silang polos, diganti
                // badge yang lebih jelas maknanya: "Bound" (hijau, sudah
                // terikat ke satu perangkat) atau "Unbound" (abu, masih
                // bebas dipakai perangkat manapun). Data & logic TIDAK
                // berubah — cuma cara menampilkan boolean yang sama.
                Tables\Columns\TextColumn::make('auto_bind_mac')
                    ->label('Bind MAC')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Bound' : 'Unbound')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('bound_mac_address')->label('MAC')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal Dibuat')
                    ->formatStateUsing(fn ($state) => $state->format('d-m-y,H:i'))
                    ->size('sm')
                    ->sortable(),

                // Kedaluwarsa SEBELUMNYA cuma ada di menu "Voucher
                // Terjual" — padahal voucher hasil IMPOR bisa saja sudah
                // punya tanggal kedaluwarsa walau belum terjual, jadi
                // tidak kelihatan di mana-mana. Sekarang ditampilkan di
                // sini juga.
                Tables\Columns\TextColumn::make('expired_at')
                    ->label('Kedaluwarsa')
                    ->formatStateUsing(fn ($state) => $state?->format('d-m-y,H:i'))
                    ->placeholder('-')
                    // Voucher yang tanggalnya sudah lewat diberi warna
                    // merah supaya langsung ketahuan.
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null)
                    ->description(fn (Voucher $record) => $record->expired_at
                        ? ($record->expired_at->isPast()
                            ? 'sudah lewat'
                            : 'sisa '.$record->expired_at->diffForHumans(null, true))
                        : null)
                    ->size('sm')
                    ->sortable(),

                Tables\Columns\TextColumn::make('generatedBy.name')
                    ->label('Created By')
                    ->placeholder('-')
                    ->limit(20)
                    // Voucher yang dibuat outlet lewat bot Telegram TIDAK
                    // punya "generatedBy" (bot tidak login sebagai akun
                    // User panel manapun -- ia cuma tahu Outlet-nya lewat
                    // telegram_chat_id yang terhubung). Sebelumnya kolom
                    // ini jadi kosong ("-") sama sekali untuk voucher
                    // seperti itu. Sekarang, kalau tidak ada generatedBy
                    // TAPI vouchernya berasal dari Telegram dan punya
                    // outlet, tampilkan nama outlet itu sebagai pembuatnya
                    // -- karena secara nyata memang outlet itu yang minta
                    // dibuatkan lewat bot.
                    ->formatStateUsing(function (?string $state, Voucher $record) {
                        if (filled($state)) {
                            return $state;
                        }

                        if (in_array($record->generated_via, [\App\Enums\VoucherSource::Telegram, \App\Enums\VoucherSource::Qris], true) && $record->outlet) {
                            return $record->outlet->name;
                        }

                        return null;
                    })
                    // Keterangan kecil di bawah nama pembuatnya: voucher
                    // ini dibuat lewat Aplikasi atau lewat bot Telegram.
                    // Voucher LAMA (dibuat sebelum fitur ini ada) tidak
                    // punya catatan asalnya, jadi dibiarkan kosong —
                    // bukan diklaim dibuat lewat aplikasi.
                    ->description(fn (Voucher $record) => $record->generated_via?->label()
                        ? 'via '.$record->generated_via->label()
                        : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (VoucherStatus $state) => $state->label())
                    ->color(fn (VoucherStatus $state) => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(VoucherStatus::options())->except(VoucherStatus::Expired->value)->toArray()),

                // Filter Online/Offline -- dipakai sebagai tujuan link
                // dari kartu Dashboard (kartu "Voucher Online"/"Voucher
                // Offline" di halaman ini sendiri sudah dihapus, lihat
                // HotspotUserOverview), lewat URL
                // ?tableFilters[sesi_online][value]=online (atau offline).
                //
                // Caranya SENGAJA disamakan persis dengan logika online
                // Dashboard: ambil semua sesi radacct milik tenant ini
                // yang sedang online, KURANGI yang
                // ternyata pelanggan tetap (PPPoE/Hotspot member, bukan
                // voucher) -- sisanya baru dicocokkan ke username voucher
                // di sini. Supaya angka kartu & hasil filter selalu cocok.
                Tables\Filters\SelectFilter::make('sesi_online')
                    ->label('Sesi')
                    ->options(['online' => 'Online', 'offline' => 'Offline'])
                    ->query(function ($query, array $data) {
                        $nilai = $data['value'] ?? null;

                        if (! in_array($nilai, ['online', 'offline'], true)) {
                            return $query;
                        }

                        $usernamePelanggan = \App\Models\Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                            ->pluck('username')
                            ->map(fn ($u) => \App\Support\RadiusUsername::forRadius($u))
                            ->all();

                        $usernameOnline = \App\Models\Radacct::whereNull('acctstoptime')
                            ->milikTenantIni()
                            ->when(! empty($usernamePelanggan), fn ($q) => $q->whereNotIn('username', $usernamePelanggan))
                            ->pluck('username')
                            ->map(fn ($u) => \App\Support\RadiusUsername::stripPrefix($u))
                            ->all();

                        return $nilai === 'online'
                            ? $query->whereIn('username', $usernameOnline)
                            : $query->whereNotIn('username', $usernameOnline);
                    }),

                // Profile yang ditawarkan di filter ini WAJIB dibatasi ke
                // profile Hotspot saja. Sebelumnya ->relationship() dipakai
                // polos tanpa batasan, jadi profile PPPoE ikut terdaftar di
                // dropdown — padahal halaman ini khusus voucher hotspot,
                // dan memilih profile PPPoE di sini hasilnya selalu kosong
                // (tidak ada voucher yang memakainya). Batasan tipenya
                // disamakan persis dengan yang dipakai di form Tambah User
                // & Generate: 'hotspot' dan 'both'.
                Tables\Filters\SelectFilter::make('voucher_profile_id')
                    ->label('Profile')
                    ->relationship(
                        'profile',
                        'name',
                        // is_member_only excluded (permintaan user) -- paket
                        // khusus member tidak boleh muncul di filter ini,
                        // sama seperti di form Tambah User & Generate.
                        fn ($query) => $query->whereIn('type', ['hotspot', 'both'])->where('is_member_only', false)
                    )
                    ->preload(),
                Tables\Filters\SelectFilter::make('outlet_id')->label('Outlet')->relationship('outlet', 'name'),
                Tables\Filters\SelectFilter::make('generated_via')
                    ->label('Dibuat Lewat')
                    ->options(\App\Enums\VoucherSource::options()),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->headerActions([
                // Tombol terpisah (bukan dropdown) — lebar disamakan, warna
                // oranye sesuai tema, ikon kecil di tiap tombol.
                Actions\CreateAction::make()
                    ->label('Tambah User')
                    ->tooltip('Tambah User')
                    ->icon('lucide-user-plus')
                    ->color('primary')
                    ->modalWidth('lg')
                    // Dorong ke tabel radius supaya voucher langsung bisa
                    // dipakai login (halaman CreateHotspotUser sudah tidak
                    // dipakai — tombol Tambah kini berupa popup).
                    ->after(function ($record) {
                        if ($record->profile) {
                            app(\App\Services\FreeRadiusService::class)
                                ->provisionVoucher($record, $record->profile);
                        }
                    }),

                Actions\Action::make('hapus_expired')
                    ->label('Hapus Expired')
                    ->tooltip('Hapus Expired')
                    ->icon('lucide-trash-2')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus semua voucher expired')
                    ->modalDescription('Menghapus SEMUA voucher berstatus Expired sekaligus. Voucher yang sudah pernah terjual otomatis dilewati supaya riwayat transaksi & laporan pendapatan tetap utuh.')
                    ->action(function () {
                        $ids = Voucher::where('status', VoucherStatus::Expired)->pluck('id')->all();

                        if (empty($ids)) {
                            Notification::make()->title('Tidak ada voucher expired')->warning()->send();

                            return;
                        }

                        [$terhapus, $dilewati] = static::hapusVoucherAman($ids);

                        Notification::make()
                            ->title($terhapus.' voucher expired dihapus')
                            ->body($dilewati > 0 ? $dilewati.' dilewati karena sudah pernah terjual.' : null)
                            ->status($dilewati > 0 ? 'warning' : 'success')
                            ->send();
                    }),

                Actions\Action::make('export')
                    ->label('Export')
                    ->tooltip('Export')
                    ->icon('lucide-download')
                    ->color('primary')
                    ->action(fn (CsvService $csv) => $csv->exportVouchers(
                        static::getEloquentQuery(),
                        'voucher-'.now()->format('Ymd-His').'.csv'
                    )),

                Actions\Action::make('generate')
                    ->label('Generate')
                    ->tooltip('Generate')
                    ->icon('lucide-sparkles')
                    ->color('primary')
                    ->modalHeading('Generate Voucher')
                    ->modalWidth('md')
                    ->schema([
                        Forms\Components\Select::make('service_package_id')
                            ->label('Profile')
                            // is_member_only excluded (permintaan user) --
                            // sama seperti Select 'voucher_profile_id' di
                            // atas.
                            ->options(fn () => ServicePackage::where('is_active', true)
                                ->where('is_member_only', false)
                                ->whereIn('type', ['hotspot', 'both'])
                                ->get()
                                ->filter(fn ($p) => $p->isAvailableForOutlet(auth()->user()?->outlet_id))
                                ->pluck('name', 'id'))
                            ->required(),
                        Forms\Components\Select::make('outlet_id')
                            ->label('Outlet Tujuan')
                            ->placeholder('Kosongkan = stok pusat')
                            // Sama seperti catatan di Select outlet_id form
                            // "Tambah User" di atas -- lihat penjelasan di
                            // sana.
                            ->helperText('Kosongkan hanya kalau voucher-voucher ini memang belum dibagikan ke outlet manapun. Voucher tanpa Outlet TIDAK akan terlihat oleh akun Outlet di menu manapun (termasuk bot Telegram) sampai nanti ditugaskan ke sebuah Outlet.')
                            ->options(fn () => Outlet::where('is_active', true)->where('partner_type', '!=', PartnerType::Biller)->pluck('name', 'id')),
                        // Jumlah diketik BEBAS, bukan dropdown pilihan tetap.
                        // Sebelumnya cuma boleh 10/20/50/100/500/1000, jadi
                        // kalau butuh 23 atau 65 lembar terpaksa dibulatkan
                        // ke atas dan sisanya jadi voucher nganggur.
                        //
                        // Batas 1-1000 di sini HARUS sama dengan penjaga di
                        // VoucherGeneratorService::generate(). Kalau form
                        // membolehkan lebih besar dari service, user baru
                        // ditolak SETELAH menekan Submit — dan penolakannya
                        // berupa exception, bukan pesan validasi yang enak
                        // dibaca. Kalau suatu saat batasnya mau dinaikkan,
                        // ubah di KEDUA tempat, dan pertimbangkan memakai
                        // antrian: seluruh proses generate dibungkus satu
                        // transaksi database, jadi makin besar jumlahnya
                        // makin lama transaksinya mengunci tabel.
                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->default(10)
                            ->minValue(1)
                            ->maxValue(1000)
                            ->step(1)
                            ->required(),

                        Section::make('Format Voucher (opsional)')
                            ->columns(2)
                            ->collapsed()
                            ->schema([
                                Forms\Components\TextInput::make('prefix')
                                    ->label('Prefix')
                                    ->placeholder('mis. TYA')
                                    ->maxLength(10),
                                Forms\Components\Select::make('login_method')
                                    ->label('Metode Login')
                                    ->options(VoucherLoginMethod::options())
                                    ->default('user_pass')
                                    ->required()
                                    ->reactive(),
                                Forms\Components\Select::make('code_combination')
                                    ->label('Kombinasi Kode')
                                    ->options(VoucherCodeCombination::options())
                                    ->default('alnum_upper')
                                    ->required(),
                                Forms\Components\TextInput::make('username_length')
                                    ->label('Panjang Username')->numeric()->default(8),
                                Forms\Components\TextInput::make('password_length')
                                    ->label('Panjang Password')->numeric()->default(6)
                                    ->visible(fn (callable $get) => $get('login_method') === 'user_pass'),
                                Forms\Components\Toggle::make('auto_bind_mac')
                                    ->label('Auto-bind MAC')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->action(function (array $data, GenerateVoucherBatchAction $generateVoucherBatchAction) {
                        // Proses generate melempar RuntimeException untuk
                        // penolakan yang WAJAR (mis. jumlah di luar batas
                        // 1-1000 dari VoucherGeneratorService). Kalau tidak
                        // ditangkap di sini, exception-nya lolos ke Livewire
                        // dan user cuma lihat "Error while loading page" --
                        // pesan yang sama sekali tidak menjelaskan apa yang
                        // salah. Ini pernah bikin pelacakan errornya
                        // berputar-putar (dulu penyebabnya saldo kurang),
                        // jadi JANGAN dihapus meski jalur aplikasi sekarang
                        // tidak lagi memotong saldo: tampilkan alasannya
                        // apa adanya.
                        try {
                            $vouchers = $generateVoucherBatchAction->execute($data);
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Voucher gagal dibuat')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $batchCode = $vouchers->first()?->batch_code;

                        Notification::make()
                            ->title("{$vouchers->count()} voucher berhasil digenerate")
                            ->success()
                            ->actions([
                                Actions\Action::make('print')
                                    ->label('Cetak Voucher Baru')
                                    ->icon('lucide-printer')
                                    ->button()
                                    ->url(route('vouchers.print-templates', ['batch' => $batchCode]), shouldOpenInNewTab: true),
                            ])
                            ->send();
                    }),

                // Aksi massal (Print/Disable/Hapus untuk baris tercentang) --
                // sengaja ditaruh di SINI (->headerActions(), langsung
                // bersambung setelah tombol Generate) bukan di
                // ->toolbarActions() supaya PERSIS satu baris/kartu yang
                // sama secara visual dengan tombol header lain.
                //
                // PENTING: ini TETAP Actions\BulkAction asli (bukan Action
                // biasa) -- sempat dicoba diganti ke Action biasa yang baca
                // baris tercentang manual lewat $livewire->getSelectedTable
                // Records(), tapi ternyata TIDAK RELIABLE (kadang $records
                // kosong terus walau baris sudah dicentang, sampai muncul
                // bug "Tidak ada voucher..." padahal sudah pilih "cetak
                // semua"). Filament mendukung resmi taruh BulkAction di
                // ->headerActions() (lihat komentar di source HasHeaderActions
                // soal ini) -- jadi cukup dipindah lokasinya saja, logic
                // pemilihan barisnya tetap 100% bawaan Filament yang sudah
                // pasti benar ($records didapat otomatis lewat parameter
                // closure, deselect-otomatis juga bawaan).
                Actions\BulkAction::make('print_selected')
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
                    ->label('Print')
                    ->icon('lucide-printer')
                    ->color('primary')
                    ->schema([
                        Forms\Components\Radio::make('scope')
                            ->label('Voucher yang mau dicetak')
                            ->options([
                                'unused' => 'Cetak yang belum dipakai saja (lewati yang sudah digunakan/expired/nonaktif)',
                                'all' => 'Cetak semua yang dipilih',
                            ])
                            ->default('unused')
                            ->required(),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        $ids = $records
                            ->when(
                                $data['scope'] === 'unused',
                                fn ($collection) => $collection->filter(fn ($record) => in_array($record->status, [VoucherStatus::BelumTerjual, VoucherStatus::Terjual], true))
                            )
                            ->pluck('id');

                        if ($ids->isEmpty()) {
                            Notification::make()
                                ->title('Tidak ada voucher yang belum dipakai di antara yang dipilih')
                                ->warning()
                                ->send();

                            return null;
                        }

                        return redirect()->to(route('vouchers.print-templates', ['ids' => $ids->join(',')]));
                    }),

                Actions\BulkAction::make('disable_selected')
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
                    ->label('Disable')
                    ->icon('lucide-ban')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records, VoucherGeneratorService $service) {
                        foreach ($records as $record) {
                            if ($record->status !== VoucherStatus::Disabled) {
                                $service->disable($record);
                            }
                        }

                        Notification::make()->title($records->count().' voucher dinonaktifkan')->success()->send();
                    }),

                Actions\BulkAction::make('hapus_selected')
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
                    ->modalHeading('Hapus voucher terpilih')
                    ->modalDescription('Voucher yang SUDAH PERNAH TERJUAL tidak akan dihapus — otomatis dilewati, supaya riwayat transaksi & laporan pendapatan tetap utuh.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Support\Collection $records) {
                        [$terhapus, $dilewati] = static::hapusVoucherAman($records->pluck('id')->all());

                        Notification::make()
                            ->title($terhapus.' voucher dihapus')
                            ->body($dilewati > 0 ? $dilewati.' dilewati karena sudah pernah terjual (punya catatan transaksi).' : null)
                            ->status($dilewati > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\Action::make('toggle_disable')
                    ->label(fn (Voucher $record) => $record->status === VoucherStatus::Disabled ? 'Aktifkan' : 'Nonaktifkan')
                    ->icon(fn (Voucher $record) => $record->status === VoucherStatus::Disabled ? 'lucide-circle-check' : 'lucide-ban')
                    ->color(fn (Voucher $record) => $record->status === VoucherStatus::Disabled ? 'success' : 'danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalDescription(fn (Voucher $record) => $record->status === VoucherStatus::Disabled
                        ? 'User ini akan bisa login kembali.'
                        : 'User ini tidak akan bisa login lagi. Kalau sedang online, koneksinya juga langsung diputus.')
                    ->action(function (Voucher $record) {
                        if ($record->status === VoucherStatus::Disabled) {
                            // Dikembalikan ke "aktif" kalau sudah pernah dipakai,
                            // atau "terjual" kalau belum pernah login sama sekali.
                            $record->update([
                                'status' => $record->activated_at ? VoucherStatus::Aktif : VoucherStatus::Terjual,
                            ]);

                            Notification::make()->title('User berhasil diaktifkan kembali')->success()->send();

                            return;
                        }

                        $record->update(['status' => VoucherStatus::Disabled]);

                        // Kalau user sedang online, putuskan juga koneksinya —
                        // kalau tidak, dia tetap bisa lanjut internetan sampai
                        // sesinya habis sendiri meski sudah dinonaktifkan.
                        $session = \App\Models\Radacct::where('username', \App\Support\RadiusUsername::forRadius($record->username))
                            ->whereNull('acctstoptime')
                            ->latest('acctstarttime')
                            ->first();

                        $kickInfo = null;

                        if ($session) {
                            $router = app(\App\Services\RouterResolver::class)->resolveForNasIp($session->nasipaddress);

                            if ($router) {
                                $result = app(\App\Actions\DisconnectUserAction::class)->execute($router, $record->username);
                                $kickInfo = $result['success']
                                    ? 'Koneksi yang sedang aktif juga sudah diputus.'
                                    : 'Tapi gagal memutus koneksi aktifnya: '.($result['error'] ?? 'error tidak diketahui');
                            } else {
                                $kickInfo = 'Tapi router untuk NAS '.$session->nasipaddress.' tidak ditemukan, jadi koneksi aktifnya belum diputus.';
                            }
                        }

                        Notification::make()
                            ->title('User berhasil dinonaktifkan')
                            ->body($kickInfo)
                            ->success()
                            ->send();
                    }),
                Actions\EditAction::make()->iconButton()->modalWidth('lg'),
                Actions\DeleteAction::make()->iconButton(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Hapus voucher, TAPI lewati yang masih dipakai transaksi — kolom
     * transactions.voucher_id memakai restrictOnDelete, jadi kalau
     * dipaksa hapus, query-nya error dan SATU BATCH ikut gagal semua.
     * Di sini yang terkunci sengaja dilewati, sisanya tetap terhapus.
     *
     * @param  array<int, int>  $ids
     * @return array{0: int, 1: int} [jumlah terhapus, jumlah dilewati]
     */
    protected static function hapusVoucherAman(array $ids): array
    {
        if (empty($ids)) {
            return [0, 0];
        }

        $terkunci = \App\Models\Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('voucher_id', $ids)
            ->pluck('voucher_id')
            ->unique()
            ->all();

        $bolehHapus = array_diff($ids, $terkunci);

        // WAJIB hapus SATU-SATU (bukan whereIn()->delete() massal) —
        // query massal TIDAK memicu event model Voucher::booted(), jadi
        // pembersihan RADIUS/log otomatis di sana tidak akan jalan sama
        // sekali kalau dihapus dengan cara massal.
        $terhapus = 0;

        foreach (Voucher::whereIn('id', $bolehHapus)->get() as $voucher) {
            $voucher->delete();
            $terhapus++;
        }

        return [$terhapus, count($terkunci)];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotspotUsers::route('/'),
            'edit' => Pages\EditHotspotUser::route('/{record}/edit'),
        ];
    }
}
