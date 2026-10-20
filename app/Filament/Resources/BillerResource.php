<?php

namespace App\Filament\Resources;

use App\Enums\PartnerType;
use App\Enums\RoleType;
use App\Filament\Resources\BillerResource\Pages;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Biller = mitra yang bertugas MENAGIH pelanggan PPPoE/Hotspot (invoice)
 * dan/atau outlet, lalu mendapat komisi TETAP (nominal rupiah, BUKAN
 * persentase) yang besarnya berbeda-beda per Outlet/Pelanggan — diatur
 * Owner lewat aksi "Kelola Outlet & Komisi" dan "Kelola Pelanggan & Komisi".
 *
 * Dua jalur penugasan terpisah:
 * - billedOutlets(): Biller menagih SEMUA yang tercakup di satu Outlet.
 * - billedCustomers(): Biller menagih pelanggan PPPoE/Hotspot LANGSUNG,
 *   satu per satu (tidak lewat outlet) — mis. biller lepas yang keliling
 *   nagih pelanggan rumahan.
 */
class BillerResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Outlet::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Mitra';

    protected static ?string $navigationLabel = 'Biller';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'mitra/biller';

    public static function getModelLabel(): string
    {
        return 'Biller';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Biller';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('partner_type', PartnerType::Biller);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Forms\Components\Hidden::make('partner_type')->default(PartnerType::Biller->value),

            Wizard::make([
                Step::make('Data Biller')
                    ->icon('lucide-briefcase')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Biller')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('owner_name')
                            ->label('Nama Penagih')
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

                Step::make('Alamat & Catatan')
                    ->icon('lucide-map-pin')
                    ->schema([
                        Forms\Components\Textarea::make('address')
                            ->label('Alamat')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

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
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nama Biller')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('owner_name')->label('Penagih'),
                Tables\Columns\TextColumn::make('phone')->label('No HP'),
                Tables\Columns\TextColumn::make('area.name')
                    ->label('Area')
                    ->placeholder('-')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('billedOutlets_count')
                    ->label('Outlet Ditagih')
                    ->state(fn (Outlet $record) => $record->billedOutlets()->count()),
                Tables\Columns\TextColumn::make('billedCustomers_count')
                    ->label('Pelanggan Ditagih')
                    ->state(fn (Outlet $record) => $record->billedCustomers()->count()),
                Tables\Columns\TextColumn::make('total_komisi')
                    ->label('Total Komisi/Bulan')
                    ->state(fn (Outlet $record) => $record->billedOutlets()->sum('biller_outlet_commissions.commission_amount')
                        + $record->billedCustomers()->sum('biller_customer_commissions.commission_amount'))
                    ->money('IDR', decimalPlaces: 0)->alignEnd(),
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
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
                Tables\Filters\SelectFilter::make('area_id')
                    ->label('Area / Wilayah')
                    ->options(fn () => \App\Models\Area::orderBy('name')->pluck('name', 'id'))
                    ->preload(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah Biller')
                    ->modalHeading('Tambah Biller')
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

                        $biller = Outlet::create($data);

                        \App\Models\User::create([
                            'name' => $biller->owner_name,
                            'username' => $username,
                            'email' => $email,
                            'password' => $password,
                            'role' => \App\Enums\RoleType::Outlet,
                            'outlet_id' => $biller->id,
                            'is_active' => $biller->is_active,
                        ]);

                        return $biller;
                    }),
            ])
            ->recordActions([
                Actions\Action::make('manage_outlets')
                    ->label('Kelola Outlet & Komisi')
                    ->icon('lucide-clipboard-list')
                    ->color('warning')
                    ->iconButton()
                    ->modalHeading('Kelola Outlet & Komisi Tetap')
                    ->modalWidth('lg')
                    ->fillForm(fn (Outlet $record) => [
                        'assignments' => $record->billedOutlets()->get()->map(fn ($outlet) => [
                            'outlet_id' => $outlet->id,
                            'commission_amount' => $outlet->pivot->commission_amount,
                        ])->toArray(),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('assignments')
                            ->label('Outlet yang Ditagih Biller Ini')
                            ->schema([
                                Forms\Components\Select::make('outlet_id')
                                    ->label('Outlet')
                                    ->options(fn () => Outlet::where('partner_type', PartnerType::Outlet)->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                                    // Outlet yang sudah dipilih di baris lain (repeater
                                    // item lain) otomatis disembunyikan dari daftar
                                    // pilihan baris ini -- supaya tidak bisa pilih
                                    // outlet yang sama dua kali.
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('commission_amount')
                                    ->label('Komisi Tetap')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->required()
                                    ->minValue(0)
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->addActionLabel('Tambah Outlet')
                            ->defaultItems(0),
                    ])
                    ->action(function (Outlet $record, array $data) {
                        $sync = collect($data['assignments'] ?? [])
                            ->filter(fn ($row) => filled($row['outlet_id'] ?? null))
                            ->mapWithKeys(fn ($row) => [
                                $row['outlet_id'] => ['commission_amount' => $row['commission_amount'] ?? 0],
                            ])->toArray();

                        $record->billedOutlets()->sync($sync);

                        Notification::make()->title('Penugasan outlet & komisi berhasil disimpan')->success()->send();
                    }),
                Actions\Action::make('manage_customers')
                    ->label('Kelola Pelanggan & Komisi')
                    ->icon('lucide-users')
                    ->color('info')
                    ->iconButton()
                    ->modalHeading('Kelola Pelanggan & Komisi Tetap')
                    ->modalDescription('Untuk pelanggan PPPoE dan Hotspot yang ditagih LANGSUNG oleh biller ini (bukan lewat outlet).')
                    ->modalWidth('lg')
                    ->fillForm(fn (Outlet $record) => [
                        'assignments' => $record->billedCustomers()->get()->map(fn ($customer) => [
                            'customer_id' => $customer->id,
                            'commission_amount' => $customer->pivot->commission_amount,
                        ])->toArray(),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('assignments')
                            ->label('Pelanggan yang Ditagih Biller Ini')
                            ->schema([
                                Forms\Components\Select::make('customer_id')
                                    ->label('Pelanggan (PPPoE/Hotspot)')
                                    ->options(function (Outlet $record) {
                                        // Pelanggan yang sudah jadi tanggungan BILLER
                                        // LAIN (permintaan user) TIDAK BOLEH muncul di
                                        // sini sama sekali -- satu pelanggan cuma
                                        // boleh ditagih oleh SATU biller. Pelanggan
                                        // yang sudah jadi milik biller INI SENDIRI
                                        // tetap ditampilkan (kalau tidak, baris yang
                                        // sudah ada malah hilang/tidak kebaca saat
                                        // modal ini dibuka lagi untuk diedit).
                                        $milikBillerLain = \Illuminate\Support\Facades\DB::table('biller_customer_commissions')
                                            ->where('biller_id', '!=', $record->id)
                                            ->pluck('customer_id');

                                        return \App\Models\Customer::query()
                                            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                                            ->whereNotIn('id', $milikBillerLain)
                                            ->get()
                                            ->mapWithKeys(fn ($c) => [$c->id => $c->name.' — '.$c->type->label().' ('.$c->customer_number.')']);
                                    })
                                    ->searchable()
                                    ->required()
                                    // Sama seperti di "Kelola Outlet & Komisi" --
                                    // pelanggan yang sudah dipilih di baris lain
                                    // otomatis hilang dari daftar pilihan baris ini,
                                    // supaya tidak bisa pilih pelanggan yang sama
                                    // dua kali (dan bikin tidak jelas dua-duanya
                                    // saat di-sync ke pivot).
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('commission_amount')
                                    ->label('Komisi Tetap')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->required()
                                    ->minValue(0)
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->addActionLabel('Tambah Pelanggan')
                            ->defaultItems(0),
                    ])
                    ->action(function (Outlet $record, array $data) {
                        $sync = collect($data['assignments'] ?? [])
                            ->filter(fn ($row) => filled($row['customer_id'] ?? null))
                            ->mapWithKeys(fn ($row) => [
                                $row['customer_id'] => ['commission_amount' => $row['commission_amount'] ?? 0],
                            ])->toArray();

                        $record->billedCustomers()->sync($sync);

                        Notification::make()->title('Penugasan pelanggan & komisi berhasil disimpan')->success()->send();
                    }),
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit Biller')
                    ->modalWidth('xl')
                    ->fillForm(function (Outlet $record) {
                        $user = static::billerUser($record);

                        return array_merge($record->toArray(), [
                            'login_username' => $user?->username,
                            'login_email' => $user?->email,
                        ]);
                    })
                    ->using(function (Outlet $record, array $data) {
                        $existing = static::billerUser($record);

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
                // BARU (permintaan user): tombol "Hubungkan Telegram" --
                // sebelumnya cuma ada di Outlet & Reseller, padahal
                // fitur bot untuk Biller (cek/tagih pelanggan lewat
                // Telegram) sudah lama jalan di backend. Persis sama
                // seperti punya Outlet, cuma dipindah ke sini.
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
                                    ->content('✅ Biller ini sudah terhubung ke Telegram.'),
                            ];
                        }

                        return [
                            Forms\Components\Placeholder::make('instruksi')
                                ->label('Cara Menghubungkan')
                                ->content(new \Illuminate\Support\HtmlString(
                                    "1. Buka bot <b>{$botUsername}</b> di Telegram<br>".
                                    "2. Kirim: <code>/start</code><br>".
                                    "3. Lalu kirim kode ini:<br><br>".
                                    "<code style='font-size:1.1rem;'>/link {$record->telegram_link_code}</code>"
                                )),
                        ];
                    }),
                // BARU (permintaan user): putuskan koneksi Telegram --
                // dipakai kalau biller ini berhenti kerja sama, supaya
                // akun Telegram lama itu tidak bisa lagi pakai perintah
                // bot khusus Biller atas nama biller ini. Cuma muncul
                // kalau memang sedang terhubung.
                Actions\Action::make('telegram_unlink')
                    ->iconButton()
                    ->icon('lucide-unlink')
                    ->color('danger')
                    ->tooltip('Putuskan Telegram')
                    ->visible(fn (Outlet $record) => filled($record->telegram_chat_id))
                    ->requiresConfirmation()
                    ->modalHeading('Putuskan Koneksi Telegram')
                    ->modalDescription('Akun Telegram yang terhubung sekarang akan LEPAS dari biller ini -- semua perintah bot lewat akun Telegram itu tidak akan berfungsi lagi untuk biller ini, sampai dihubungkan ulang lewat kode link yang baru. Cocok dipakai kalau biller ini berhenti bekerja sama.')
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
                // kode baru). Kalau diblokir, biller ini TIDAK BISA
                // terhubung ke bot Telegram sama sekali sampai blokirnya
                // dibuka lagi lewat tombol yang sama -- cocok dipakai
                // kalau biller ini berhenti bekerja sama.
                Actions\Action::make('telegram_block')
                    ->iconButton()
                    ->icon(fn (Outlet $record) => $record->telegram_blocked ? 'lucide-lock-open' : 'lucide-lock')
                    ->color(fn (Outlet $record) => $record->telegram_blocked ? 'gray' : 'danger')
                    ->tooltip(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Outlet $record) => $record->telegram_blocked ? 'Buka Blokir Telegram' : 'Blokir Telegram')
                    ->modalDescription(fn (Outlet $record) => $record->telegram_blocked
                        ? 'Setelah dibuka, biller ini boleh terhubung ke bot Telegram lagi (butuh kode /link baru kalau sebelumnya sudah putus).'
                        : 'Setelah diblokir, biller ini TIDAK BISA terhubung ke bot Telegram lagi -- koneksi yang sedang aktif (kalau ada) langsung ikut diputus juga. Bisa dibuka blokirnya lagi kapan saja lewat tombol ini.')
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
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    protected static function billerUser(Outlet $record): ?User
    {
        return User::where('outlet_id', $record->id)->where('role', RoleType::Outlet)->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillers::route('/'),
        ];
    }
}
