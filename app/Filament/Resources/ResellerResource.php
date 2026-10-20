<?php

namespace App\Filament\Resources;

use App\Enums\PartnerType;
use App\Enums\RoleType;
use App\Enums\VoucherStatus;
use App\Filament\Resources\ResellerResource\Pages;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reseller = mitra yang MEMBAYAR DI MUKA (topup saldo) untuk mengambil
 * voucher — beda dari Outlet yang konsinyasi (bayar setelah voucher laku).
 * Karena itu Reseller tidak punya commission_percent seperti Outlet;
 * "untungnya" dari selisih harga beli-jual, dan pengurangan saldo terjadi
 * saat mereka mengambil voucher (di luar scope resource ini — lihat catatan
 * di bawah getPages()).
 */
class ResellerResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Outlet::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Mitra';

    protected static ?string $navigationLabel = 'Reseller';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'mitra/reseller';

    public static function getModelLabel(): string
    {
        return 'Reseller';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Reseller';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('partner_type', PartnerType::Reseller);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('partner_type')->default(PartnerType::Reseller->value),

            Wizard::make([
                Step::make('Data Reseller')
                    ->icon('lucide-user-round')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Reseller')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('owner_name')
                            ->label('Nama Pemilik')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('WhatsApp')
                            ->placeholder('08xxxx')
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
                    ->description('Batasi profile mana saja yang boleh dipakai reseller ini generate voucher.')
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

                Step::make('Saldo, Komisi & Catatan')
                    ->icon('lucide-wallet')
                    ->schema([
                        // OPSI-KOMISI-SELISIH-HARGA (2026-10-04): cara menghitung komisi mitra ini.
                        Forms\Components\Select::make('commission_type')
                            ->label('Tipe Komisi')
                            ->options([
                                'percent' => 'Persentase dari harga jual (%)',
                                'markup' => 'Selisih Harga Jual - Harga Beli voucher',
                            ])
                            ->default('markup')
                            ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : ($state ?: 'markup'))
                            ->native(false)
                            ->live()
                            ->required()
                            ->helperText('Persentase: komisi = persen x harga jual. Selisih harga: komisi = harga jual voucher dikurangi harga beli (modal) voucher itu, dihitung per voucher yang terjual. Komisi dihitung saat laporan dibuka, jadi mengubah tipe ini ikut mengubah angka periode lama.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('commission_percent')
                            ->label('Komisi (%)')
                            ->numeric()
                            ->default(0)
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->visible(fn ($get) => ($get('commission_type') ?? 'percent') !== 'markup'),
                        Forms\Components\TextInput::make('saldo')
                            ->label('Saldo Saat Ini')
                            ->numeric()
                            ->prefix('Rp')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                        Forms\Components\Toggle::make('is_saldo_exempt')
                            ->label('Bebas Saldo')
                            ->default(false)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('address')
                            ->label('Alamat')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                Step::make('Akun Login')
                    ->icon('lucide-key')
                    ->description('Otomatis dibuatkan begitu disimpan.')
                    ->schema([
                        Forms\Components\TextInput::make('login_username')
                            ->label('Username')
                            ->required()
                            ->alphaDash()
                            ->maxLength(50),
                        Forms\Components\TextInput::make('login_email')
                            ->label('Email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('login_password')
                            ->label('Password')
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
                Tables\Columns\TextColumn::make('name')->label('Nama Reseller')->searchable()->sortable()
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
                Tables\Columns\TextColumn::make('saldo')->label('Saldo')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('commission_percent')
                    ->label('Komisi')
                    ->state(fn ($record) => ($record->commission_type instanceof \BackedEnum ? $record->commission_type->value : $record->commission_type) === 'markup' ? 'Selisih harga' : rtrim(rtrim(number_format((float) $record->commission_percent, 2, ',', ''), '0'), ',').'%'),
                // Cuma menghitung voucher yang BELUM TERJUAL (stok yang
                // masih bisa dijual) -- permintaan user. Sebelumnya
                // ->counts('vouchers') polos menjumlahkan SEMUA status
                // (termasuk yang sudah lama Expired/Disabled), jadi
                // angkanya tidak menggambarkan stok yang sebenarnya.
                Tables\Columns\TextColumn::make('vouchers_count')->label('Voucher Diambil')
                    ->counts(['vouchers' => fn ($query) => $query->where('status', VoucherStatus::BelumTerjual)]),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
                Tables\Columns\IconColumn::make('is_saldo_exempt')
                    ->label('Bebas Saldo')
                    ->boolean()
                    ->tooltip(fn (Outlet $record) => ! $record->requiresSaldo()
                        ? 'Boleh generate voucher tanpa saldo'
                        : 'Wajib saldo cukup untuk generate voucher'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
                Tables\Filters\SelectFilter::make('area_id')
                    ->label('Area / Wilayah')
                    ->options(fn () => \App\Models\Area::orderBy('name')->pluck('name', 'id'))
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_saldo_exempt')->label('Bebas Saldo'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah Reseller')
                    ->modalHeading('Tambah Reseller')
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

                        $reseller = Outlet::create($data);

                        \App\Models\User::create([
                            'name' => $reseller->owner_name,
                            'username' => $username,
                            'email' => $email,
                            'password' => $password,
                            'role' => \App\Enums\RoleType::Outlet,
                            'outlet_id' => $reseller->id,
                            'is_active' => $reseller->is_active,
                        ]);

                        return $reseller;
                    }),
            ])
            ->recordActions([
                Actions\Action::make('topup')
                    ->label('Topup Saldo')
                    ->icon('lucide-plus-circle')
                    ->color('success')
                    ->iconButton()
                    ->schema([
                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah Topup')
                            ->numeric()
                            ->prefix('Rp')
                            ->required()
                            ->minValue(1),
                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('mis. Transfer BCA, tunai, dll.')
                            ->rows(2),
                    ])
                    ->action(function (Outlet $record, array $data) {
                        $record->increment('saldo', $data['amount']);

                        Notification::make()
                            ->title('Topup berhasil')
                            ->body('Saldo baru: Rp'.number_format($record->fresh()->saldo, 0, ',', '.'))
                            ->success()
                            ->send();
                    }),
                // Pendaftaran/hubungkan bot Telegram (permintaan user) --
                // sama persis seperti punya Outlet, supaya Reseller juga
                // bisa generate voucher lewat bot Telegram. Kebijakan
                // saldo: generate lewat aplikasi TETAP boleh tanpa saldo
                // (tidak pernah dipotong, lihat GenerateVoucherBatchAction),
                // tapi generate lewat Telegram WAJIB saldo cukup dulu
                // (kecuali reseller ini ditandai "Bebas Saldo" di atas) --
                // itu diatur otomatis lewat Outlet::requiresSaldo(),
                // tidak perlu apa-apa lagi di sini selain menghubungkan
                // akun Telegram-nya.
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
                                    ->content('✅ Reseller ini sudah terhubung ke Telegram.'),
                            ];
                        }

                        return [
                            Forms\Components\Placeholder::make('instruksi')
                                ->label('Cara Menghubungkan')
                                ->content(new HtmlString(
                                    "1. Buka bot <b>{$botUsername}</b> di Telegram<br>".
                                    "2. Kirim: <code>/start</code><br>".
                                    "3. Lalu kirim kode ini:<br><br>".
                                    "<code style='font-size:1.1rem;'>/link {$record->telegram_link_code}</code><br><br>".
                                    "Setelah terhubung, generate voucher lewat Telegram WAJIB saldo cukup dulu (top up lewat tombol Topup Saldo di sini, atau kirim <code>/topup JUMLAH</code> di bot)."
                                )),
                        ];
                    }),
                // BARU (permintaan user): putuskan koneksi Telegram --
                // dipakai kalau reseller ini berhenti kerja sama, supaya
                // akun Telegram lama itu tidak bisa lagi generate voucher
                // dkk atas nama reseller ini. Cuma muncul kalau memang
                // sedang terhubung.
                Actions\Action::make('telegram_unlink')
                    ->iconButton()
                    ->icon('lucide-unlink')
                    ->color('danger')
                    ->tooltip('Putuskan Telegram')
                    ->visible(fn (Outlet $record) => filled($record->telegram_chat_id))
                    ->requiresConfirmation()
                    ->modalHeading('Putuskan Koneksi Telegram')
                    ->modalDescription('Akun Telegram yang terhubung sekarang akan LEPAS dari reseller ini -- semua perintah bot (generate voucher, cek stok, dll) lewat akun Telegram itu tidak akan berfungsi lagi untuk reseller ini, sampai dihubungkan ulang lewat kode link yang baru. Cocok dipakai kalau reseller ini berhenti bekerja sama.')
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
                // kode baru). Kalau diblokir, reseller ini TIDAK BISA
                // terhubung ke bot Telegram sama sekali sampai blokirnya
                // dibuka lagi lewat tombol yang sama -- cocok dipakai
                // kalau reseller ini berhenti bekerja sama.
                Actions\Action::make('telegram_block')
                    ->iconButton()
                    ->icon(fn (Outlet $record) => $record->telegram_blocked ? 'lucide-lock-open' : 'lucide-lock')
                    ->color(fn (Outlet $record) => $record->telegram_blocked ? 'gray' : 'danger')
                    ->tooltip(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->modalDescription(fn (Outlet $record) => $record->telegram_blocked
                        ? 'Setelah dibuka, reseller ini boleh terhubung ke bot Telegram lagi (butuh kode /link baru kalau sebelumnya sudah putus).'
                        : 'Setelah diblokir, reseller ini TIDAK BISA terhubung ke bot Telegram lagi -- koneksi yang sedang aktif (kalau ada) langsung ikut diputus juga. Bisa dibuka blokirnya lagi kapan saja lewat tombol ini.')
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
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit Reseller')
                    ->modalWidth('xl')
                    ->fillForm(function (Outlet $record) {
                        $user = static::resellerUser($record);

                        return array_merge($record->attributesToArray(), [
                            'login_username' => $user?->username,
                            'login_email' => $user?->email,
                        ]);
                    })
                    ->using(function (Outlet $record, array $data) {
                        $existing = static::resellerUser($record);

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

                        unset($data['login_username'], $data['login_email'], $data['login_password'], $data['saldo']);
                        $record->update($data);

                        return $record;
                    }),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    protected static function resellerUser(Outlet $record): ?User
    {
        return User::where('outlet_id', $record->id)->where('role', RoleType::Outlet)->first();
    }

    /**
     * Belum ada logic pengurangan saldo otomatis saat Reseller mengambil
     * voucher — alur "ambil voucher" yang ada sekarang (SellVoucherAction/
     * VoucherGeneratorService) belum tahu konsep prabayar. Untuk saat ini
     * saldo hanya bertambah lewat "Topup Saldo"; pengurangannya masih
     * manual (Owner kurangi manual, atau minta dikembangkan lebih lanjut
     * kalau mau otomatis saat transaksi voucher terjadi).
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResellers::route('/'),
        ];
    }
}
