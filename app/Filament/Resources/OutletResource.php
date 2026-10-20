<?php

namespace App\Filament\Resources;

use App\Enums\RoleType;
use App\Enums\VoucherStatus;
use App\Filament\Resources\OutletResource\Pages;
use App\Models\Outlet;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OutletResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Outlet::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Outlet';

    protected static string|\UnitEnum|null $navigationGroup = 'Mitra';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'mitra/outlet';

    public static function getModelLabel(): string
    {
        return 'Outlet';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Outlet';
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('partner_type', \App\Enums\PartnerType::Outlet);
    }

    // Form ini dipakai dalam bentuk MODAL (bukan halaman penuh) — lihat
    // getPages() di bawah, tidak ada route 'create'/'edit' terdaftar,
    // jadi CreateAction/EditAction otomatis tampil sebagai popup ringkas.
    //
    // login_username / login_email / login_password BUKAN kolom di tabel
    // outlets — field ini transit saja, dipakai di ListOutlets::CreateAction
    // dan EditAction (lihat ->using()/->fillForm() di bawah) untuk otomatis
    // membuat/memperbarui akun login (User role=Outlet) begitu outlet disimpan.
    /**
     * Merapikan tampilan angka persen komisi: buang nol di belakang
     * koma (permintaan user "jangan ada 00 dibelakang").
     *
     * Kolom commission_percent bertipe DECIMAL(5,2) dan di-cast
     * 'decimal:2' di model Outlet, jadi nilainya SELALU sampai ke
     * tampilan dalam bentuk "10.00" — bukan "10". Fungsi ini cuma
     * mengubah TAMPILAN, tidak menyentuh nilai yang tersimpan di
     * database (jadi komisi pecahan seperti 7,5% tetap aman: yang
     * dipangkas hanya nol yang tidak bermakna).
     */
    public static function formatPersen($state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        $angka = (float) $state;

        // rtrim dua tahap: "10.00" -> "10." -> "10", sedangkan
        // "7.50" -> "7.5" (nol terakhir saja yang hilang).
        $teks = rtrim(rtrim(number_format($angka, 2, '.', ''), '0'), '.');

        // Koma sebagai pemisah desimal (format Indonesia).
        return str_replace('.', ',', $teks === '' ? '0' : $teks);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Wizard::make([
                Step::make('Data Outlet')
                    ->icon('lucide-store')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Outlet')
                            ->placeholder('Nama outlet')
                            ->prefixIcon('heroicon-o-building-storefront')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('owner_name')
                            ->label('Nama Pemilik')
                            ->placeholder('Nama pemilik')
                            ->prefixIcon('heroicon-o-identification')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('WhatsApp')
                            ->placeholder('08xxxx')
                            ->prefixIcon('heroicon-o-phone')
                            ->tel()
                            ->maxLength(20),
                        Forms\Components\Select::make('area_id')
                            ->label('Area / Wilayah')
                            ->placeholder('Pilih area')
                            ->prefixIcon('heroicon-o-map-pin')
                            ->options(fn () => \App\Models\Area::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->preload()
                            // Kalau areanya belum ada, bisa langsung dibuat
                            // dari sini tanpa pindah halaman.
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nama Area')->required()->unique(table: 'areas', column: 'name'),
                                Forms\Components\TextInput::make('code')
                                    ->label('Kode (opsional)')->maxLength(20),
                            ])
                            ->createOptionUsing(fn (array $data) => \App\Models\Area::create($data)->getKey()),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ])->columns(2),

                Step::make('Profile Hotspot')
                    ->icon('lucide-wifi')
                    ->description('Batasi profile mana saja yang boleh dipakai outlet ini generate voucher.')
                    ->schema([
                        Forms\Components\Select::make('servicePackages')
                            ->label('Profile Hotspot yang Boleh Dipakai')
                            ->relationship(
                                'servicePackages',
                                'name',
                                fn ($query) => $query->where('is_active', true)->whereIn('type', ['hotspot', 'both'])
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('Kosongkan = boleh pakai SEMUA profile hotspot aktif')
                            ->columnSpanFull(),
                    ]),

                Step::make('Komisi & Catatan')
                    ->icon('lucide-percent')
                    ->schema([
                        // OPSI-KOMISI-SELISIH-HARGA (2026-10-04): cara menghitung komisi mitra ini.
                        Forms\Components\Select::make('commission_type')
                            ->label('Tipe Komisi')
                            ->options([
                                'percent' => 'Persentase dari harga jual (%)',
                                'markup' => 'Selisih Harga Jual - Harga Beli voucher',
                            ])
                            ->default('percent')
                            ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : ($state ?: 'percent'))
                            ->native(false)
                            ->live()
                            ->required()
                            ->helperText('Persentase: komisi = persen x harga jual. Selisih harga: komisi = harga jual voucher dikurangi harga beli (modal) voucher itu, dihitung per voucher yang terjual. Komisi dihitung saat laporan dibuka, jadi mengubah tipe ini ikut mengubah angka periode lama.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('commission_percent')
                            ->label('Komisi (%)')
                            ->visible(fn ($get) => ($get('commission_type') ?? 'percent') !== 'markup')
                            ->placeholder('0')
                            ->numeric()
                            // Tanpa ini, isian di form muncul "10.00"
                            // (bawaan cast decimal:2) -- dipangkas jadi
                            // "10" supaya enak dilihat & diedit.
                            ->formatStateUsing(fn ($state) => static::formatPersen($state))
                            ->default(0)
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100),
                        // Kebijakan saat ini: SEMUA Outlet otomatis tidak
                        // wajib saldo untuk generate voucher (yang wajib
                        // saldo hanya Reseller — lihat Outlet::requiresSaldo()
                        // & ResellerResource). Jadi toggle "Bebas Saldo"
                        // untuk Outlet sudah tidak relevan lagi dan
                        // sengaja tidak ditampilkan di sini supaya tidak
                        // membingungkan (dulu ada, sekarang dihapus dari
                        // form ini) — cukup diberi tahu lewat teks di
                        // bawah. Kolom is_saldo_exempt di database tetap
                        // ada, tapi untuk Outlet nilainya tidak lagi
                        // berpengaruh apa pun.
                        Forms\Components\Placeholder::make('info_bebas_saldo')
                            ->label('Kebijakan Saldo')
                            ->content('Outlet TIDAK wajib punya saldo untuk generate voucher lewat bot Telegram (berlaku otomatis untuk semua Outlet). Yang wajib saldo hanya mitra bertipe Reseller.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('address')
                            ->label('Alamat')
                            ->placeholder('Alamat')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->placeholder('Catatan (opsional)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                Step::make('Akun Login')
                    ->icon('lucide-key')
                    ->description('Otomatis dibuatkan begitu disimpan.')
                    ->schema([
                        Forms\Components\TextInput::make('login_username')
                            ->label('Username')
                            ->prefixIcon('heroicon-o-user')
                            ->required()
                            ->alphaDash()
                            ->maxLength(50),
                        Forms\Components\TextInput::make('login_email')
                            ->label('Email')
                            ->prefixIcon('heroicon-o-envelope')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('login_password')
                            ->label('Password')
                            ->prefixIcon('heroicon-o-lock-closed')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->columnSpanFull(),
                    ])->columns(2),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Kolom No/Kode/Nama DIBEKUKAN (sticky) di kiri saat tabel
                // digeser mendatar -- permintaan user: tabel Mitra ini lebar
                // (>10 kolom), dan tanpa ini identitas barisnya (nama Outlet/
                // Reseller/Biller) hilang dari layar begitu digeser ke kanan.
                //
                // position:sticky WAJIB lewat gaya inline (style=) DENGAN
                // !important -- compiled Filament CSS (Tailwind v4) memakai
                // @layer, dan !important di stylesheet BIASA (juga gaya
                // inline TANPA !important) SELALU KALAH lawan !important di
                // dalam @layer manapun, apa pun urutannya di HTML. Sudah
                // dibuktikan lewat uji render nyata sebelum dikirim. Kelas
                // tya-sticky-col/1/2/3 (lihat global-table-style.blade.php)
                // tetap dipakai untuk background/border/lebar kolom -- bagian
                // itu TIDAK kena masalah @layer, terbukti aman lewat class.
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()
                    ->extraHeaderAttributes(['class' => 'tya-sticky-col tya-sticky-1', 'style' => 'position:sticky !important;left:0 !important;z-index:3 !important'])
                    ->extraCellAttributes(['class' => 'tya-sticky-col tya-sticky-1', 'style' => 'position:sticky !important;left:0 !important;z-index:2 !important']),
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()
                    ->extraHeaderAttributes(['class' => 'tya-sticky-col tya-sticky-2', 'style' => 'position:sticky !important;left:2.75rem !important;z-index:3 !important'])
                    ->extraCellAttributes(['class' => 'tya-sticky-col tya-sticky-2', 'style' => 'position:sticky !important;left:2.75rem !important;z-index:2 !important']),
                Tables\Columns\TextColumn::make('name')->label('Nama Outlet')->searchable()->sortable()
                    ->extraHeaderAttributes(['class' => 'tya-sticky-col tya-sticky-3', 'style' => 'position:sticky !important;left:7.25rem !important;z-index:3 !important'])
                    ->extraCellAttributes(['class' => 'tya-sticky-col tya-sticky-3', 'style' => 'position:sticky !important;left:7.25rem !important;z-index:2 !important']),
                Tables\Columns\TextColumn::make('owner_name')->label('Pemilik'),
                Tables\Columns\TextColumn::make('phone')->label('No HP'),
                Tables\Columns\TextColumn::make('area.name')
                    ->label('Area')
                    ->placeholder('-')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                // formatStateUsing: buang ",00" di belakang angka komisi
                // (permintaan user). Kolomnya DECIMAL(5,2) & di-cast
                // 'decimal:2' di model, jadi nilainya selalu datang
                // sebagai "10.00". Di sini nol di belakang koma
                // dipangkas, tapi komisi pecahan (mis. 7,5%) TETAP utuh.
                Tables\Columns\TextColumn::make('commission_percent')
                    ->label('Komisi')
                    ->state(fn ($record) => ($record->commission_type instanceof \BackedEnum ? $record->commission_type->value : $record->commission_type) === 'markup' ? 'Selisih harga' : static::formatPersen($record->commission_percent).'%'),
                // Kolom "Saldo" DIHAPUS dari tabel Outlet (permintaan
                // user) -- sejak kebijakan diubah, Outlet tidak lagi
                // pakai sistem saldo sama sekali, jadi angkanya tidak
                // relevan ditampilkan di sini. Kolom `saldo` di database
                // TETAP ada (dipakai Reseller lewat ResellerResource),
                // cuma tidak lagi ditampilkan/dioperasikan dari sini.
                // Cuma menghitung voucher yang BELUM TERJUAL (stok yang
                // masih bisa dijual) -- permintaan user. Sebelumnya
                // ->counts('vouchers') polos menjumlahkan SEMUA status
                // (termasuk yang sudah lama Expired/Disabled), jadi
                // angkanya tidak menggambarkan stok yang sebenarnya.
                Tables\Columns\TextColumn::make('vouchers_count')->label('Voucher')
                    ->counts(['vouchers' => fn ($query) => $query->where('status', VoucherStatus::BelumTerjual)]),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
                // Kolom "Bebas Saldo" DIHAPUS dari tabel Outlet -- sejak
                // kebijakan saldo diubah, SEMUA Outlet otomatis bebas
                // saldo (lihat Outlet::requiresSaldo()), jadi kolom yang
                // selalu bernilai sama untuk setiap baris tidak lagi
                // berguna ditampilkan di sini.
                // Ikon Telegram: ORANYE mengikuti tema aplikasi, bukan
                // hijau/merah bawaan ->boolean(). Ukurannya dikecilkan
                // ('sm') supaya sebanding dengan toggle di kolom Aktif.
                Tables\Columns\IconColumn::make('telegram_chat_id')
                    ->label('Telegram')
                    ->boolean()
                    ->size('sm')
                    ->color('primary')
                    ->trueIcon('lucide-send'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
                Tables\Filters\SelectFilter::make('area_id')
                    ->label('Area / Wilayah')
                    ->options(fn () => \App\Models\Area::orderBy('name')->pluck('name', 'id'))
                    ->preload(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah Outlet')
                    ->modalHeading('Tambah Outlet')
                    ->modalWidth('xl')
                    ->using(function (array $data) {
                        $duplicate = \App\Models\User::where('email', $data['login_email'])
                            ->orWhere('username', $data['login_username'])
                            ->exists();

                        if ($duplicate) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'login_email' => 'Email atau username sudah dipakai user lain.',
                            ]);
                        }

                        $username = $data['login_username'];
                        $email = $data['login_email'];
                        $password = $data['login_password'];
                        unset($data['login_username'], $data['login_email'], $data['login_password']);

                        $outlet = Outlet::create($data);

                        \App\Models\User::create([
                            'name' => $outlet->owner_name,
                            'username' => $username,
                            'email' => $email,
                            'password' => $password,
                            'role' => \App\Enums\RoleType::Outlet,
                            'outlet_id' => $outlet->id,
                            'is_active' => $outlet->is_active,
                        ]);

                        return $outlet;
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit Outlet')
                    ->modalWidth('xl')
                    ->fillForm(function (Outlet $record) {
                        $user = static::outletUser($record);

                        // PENTING: fillForm MENIMPA seluruh isi form, bukan
                        // menambah. Jadi data outlet-nya sendiri harus ikut
                        // disertakan — kalau cuma mengembalikan field login,
                        // Nama/Alamat/dll jadi kosong saat form dibuka.
                        return array_merge($record->attributesToArray(), [
                            'login_username' => $user?->username,
                            'login_email' => $user?->email,
                        ]);
                    })
                    ->using(function (Outlet $record, array $data) {
                        $existing = static::outletUser($record);

                        $duplicate = User::where(function ($q) use ($data) {
                            $q->where('email', $data['login_email'])
                                ->orWhere('username', $data['login_username']);
                        })
                            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                            ->exists();

                        if ($duplicate) {
                            throw ValidationException::withMessages([
                                'login_email' => 'Email atau username sudah dipakai user lain.',
                            ]);
                        }

                        $userPayload = [
                            'name' => $data['owner_name'],
                            'username' => $data['login_username'],
                            'email' => $data['login_email'],
                            'role' => RoleType::Outlet,
                            'outlet_id' => $record->id,
                            'is_active' => $data['is_active'] ?? true,
                        ];

                        if (filled($data['login_password'] ?? null)) {
                            $userPayload['password'] = $data['login_password'];
                        }

                        if ($existing) {
                            $existing->update($userPayload);
                        } else {
                            $userPayload['password'] = $data['login_password'] ?? Str::random(12);
                            User::create($userPayload);
                        }

                        unset($data['login_username'], $data['login_email'], $data['login_password']);
                        $record->update($data);

                        return $record;
                    }),
                Actions\Action::make('telegram')
                    ->iconButton()
                    ->icon('lucide-send')
                    ->color('info')
                    ->tooltip('Telegram')
                    ->modalHeading('Hubungkan Bot Telegram')
                    ->modalWidth('md')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function (Outlet $record) {
                        // Sedang diblokir -- jangan generate kode
                        // baru sama sekali, tampilkan status blokir
                        // saja (permintaan user).
                        if ($record->telegram_blocked) {
                            return [
                                Forms\Components\Placeholder::make('status')
                                    ->label('Status')
                                    ->content('🚫 Diblokir dari Telegram. Buka blokirnya dulu lewat tombol gembok di baris ini kalau mau menghubungkan lagi.'),
                            ];
                        }

                        if (empty($record->telegram_link_code) && empty($record->telegram_chat_id)) {
                            $record->update(['telegram_link_code' => strtoupper(Str::random(8))]);
                        }

                        $botUsername = config('services.telegram.bot_username', '@NamaBotAnda');

                        if ($record->telegram_chat_id) {
                            return [
                                Forms\Components\Placeholder::make('status')
                                    ->label('Status')
                                    ->content('✅ Outlet ini sudah terhubung ke Telegram.'),
                            ];
                        }

                        return [
                            Forms\Components\Placeholder::make('instruksi')
                                ->label('Cara Menghubungkan')
                                ->content(new HtmlString(
                                    "1. Buka bot <b>{$botUsername}</b> di Telegram<br>".
                                    "2. Kirim: <code>/start</code><br>".
                                    "3. Lalu kirim kode ini:<br><br>".
                                    "<code style='font-size:1.1rem;'>/link {$record->telegram_link_code}</code>"
                                )),
                        ];
                    }),
                // BARU (permintaan user): putuskan koneksi Telegram --
                // dipakai kalau outlet ini berhenti kerja sama, supaya
                // akun Telegram lama itu tidak bisa lagi generate voucher
                // dkk atas nama outlet ini. Cuma muncul kalau memang
                // sedang terhubung.
                Actions\Action::make('telegram_unlink')
                    ->iconButton()
                    ->icon('lucide-unlink')
                    ->color('danger')
                    ->tooltip('Putuskan Telegram')
                    ->visible(fn (Outlet $record) => filled($record->telegram_chat_id))
                    ->requiresConfirmation()
                    ->modalHeading('Putuskan Koneksi Telegram')
                    ->modalDescription('Akun Telegram yang terhubung sekarang akan LEPAS dari outlet ini -- semua perintah bot (generate voucher, cek stok, dll) lewat akun Telegram itu tidak akan berfungsi lagi untuk outlet ini, sampai dihubungkan ulang lewat kode link yang baru. Cocok dipakai kalau outlet ini berhenti bekerja sama.')
                    ->modalSubmitActionLabel('Ya, Putuskan')
                    ->action(function (Outlet $record) {
                        $record->update([
                            'telegram_chat_id' => null,
                            'telegram_link_code' => null,
                        ]);

                        Notification::make()
                            ->title('Koneksi Telegram sudah diputus.')
                            ->success()
                            ->send();
                    }),
                // BARU (permintaan user): blokir PERMANEN -- beda dari
                // "Putuskan Telegram" di atas (yang cuma memutus sesi
                // yang sedang aktif tapi masih boleh connect lagi lewat
                // kode baru). Kalau diblokir, outlet ini TIDAK BISA
                // terhubung ke bot Telegram sama sekali sampai blokirnya
                // dibuka lagi lewat tombol yang sama -- cocok dipakai
                // kalau outlet ini berhenti bekerja sama.
                Actions\Action::make('telegram_block')
                    ->iconButton()
                    ->icon(fn (Outlet $record) => $record->telegram_blocked ? 'lucide-lock-open' : 'lucide-lock')
                    ->color(fn (Outlet $record) => $record->telegram_blocked ? 'gray' : 'danger')
                    ->tooltip(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->modalDescription(fn (Outlet $record) => $record->telegram_blocked
                        ? 'Setelah dibuka, outlet ini boleh terhubung ke bot Telegram lagi (butuh kode /link baru kalau sebelumnya sudah putus).'
                        : 'Setelah diblokir, outlet ini TIDAK BISA terhubung ke bot Telegram lagi -- koneksi yang sedang aktif (kalau ada) langsung ikut diputus juga. Bisa dibuka blokirnya lagi kapan saja lewat tombol ini.')
                    ->modalSubmitActionLabel(fn (Outlet $record) => $record->telegram_blocked ? 'Ya, Buka Blokir' : 'Ya, Blokir')
                    ->action(function (Outlet $record) {
                        if ($record->telegram_blocked) {
                            $record->update(['telegram_blocked' => false]);

                            Notification::make()->title('Blokir Telegram dibuka.')->success()->send();

                            return;
                        }

                        $record->update([
                            'telegram_blocked' => true,
                            'telegram_chat_id' => null,
                            'telegram_link_code' => null,
                        ]);

                        Notification::make()->title('Berhasil diblokir dari Telegram.')->success()->send();
                    }),
                // Tombol "Top Up Saldo" DIHAPUS dari Outlet (permintaan
                // user) -- Outlet tidak lagi pakai sistem saldo, jadi
                // tidak perlu bisa di-top up dari sini lagi. Tombol yang
                // sama masih ada di ResellerResource, karena Reseller
                // tetap wajib saldo.
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    protected static function outletUser(Outlet $record): ?User
    {
        return User::where('outlet_id', $record->id)->where('role', RoleType::Outlet)->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutlets::route('/'),
        ];
    }
}
