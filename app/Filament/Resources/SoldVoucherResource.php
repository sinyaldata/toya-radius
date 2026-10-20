<?php

namespace App\Filament\Resources;

use App\Enums\VoucherStatus;
use App\Filament\Resources\SoldVoucherResource\Pages;
use App\Models\Voucher;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class SoldVoucherResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Voucher::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Hotspot';

    protected static ?string $navigationLabel = 'Voucher Terjual';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'hotspot/voucher-terjual';

    public static function getModelLabel(): string
    {
        return 'Voucher Terjual';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Voucher Terjual';
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load relasi yang dipakai kolom tabel MAUPUN popup "Detail
        // Transaksi" (profile, outlet, generatedBy, transaction) sekali
        // jalan -- supaya buka popup tidak memicu query tambahan per baris.
        return parent::getEloquentQuery()
            ->whereIn('status', [VoucherStatus::Terjual, VoucherStatus::Aktif, VoucherStatus::Expired])
            ->with(['profile', 'outlet', 'generatedBy', 'transaction']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // ->visibleFrom('md') di beberapa kolom di bawah: kolom itu
                // TETAP ada di desktop/tablet, cuma DISEMBUNYIKAN di layar HP
                // (<768px) -- permintaan user supaya tabel muat 1 layar di
                // HP. Beda dari ->toggleable(): itu urusan tombol "Kolom
                // Manager" (yang sudah dimatikan total di seluruh aplikasi
                // lewat Table::columnManager(false)), sedangkan ini murni
                // responsif berdasar lebar layar, tidak ada tombol apa pun.
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('username')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('profile.name')->label('Produk'),
                Tables\Columns\TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('Stok Pusat')
                    ->visibleFrom('md')
                    // "Stok Pusat" (permintaan user, supaya tidak
                    // membingungkan lagi): voucher tanpa Outlet TIDAK
                    // terlihat sama sekali oleh akun Outlet manapun (lihat
                    // App\Models\Scopes\OutletScope pada Voucher) --
                    // ini SENGAJA begitu, bukan bug/gagal sinkron.
                    ->tooltip(fn ($state) => $state === null
                        ? 'Stok Pusat -- belum dibagikan ke outlet manapun. Akun Outlet TIDAK bisa melihat voucher ini di menu manapun sampai ditugaskan ke sebuah Outlet.'
                        : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (VoucherStatus $state) => $state->label())
                    ->color(fn (VoucherStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('sale_price')->label('Harga')->money('IDR', decimalPlaces: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('sold_at')->label('Terjual')->dateTime('d M Y H:i')->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('activated_at')->label('Dipakai Sejak')->dateTime('d M Y H:i')->placeholder('Belum dipakai')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('expired_at')->label('Kedaluwarsa')->dateTime('d M Y H:i')->placeholder('-')->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'terjual' => 'Terjual (belum dipakai)',
                    'aktif' => 'Aktif (sudah dipakai)',
                    'expired' => 'Expired',
                ]),

                // Saring berdasarkan OUTLET penjualnya. "Stok Pusat"
                // (voucher tanpa outlet) dikasih pilihan sendiri karena
                // outlet_id-nya NULL, tidak bisa diwakili dropdown biasa.
                Tables\Filters\SelectFilter::make('outlet_id')
                    ->label('Outlet')
                    ->options(fn () => ['__pusat__' => 'Stok Pusat (tanpa outlet)']
                        + \App\Models\Outlet::orderBy('name')->pluck('name', 'id')->all())
                    ->preload()
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(($data['value'] ?? null) === '__pusat__', fn ($q) => $q->whereNull('outlet_id'))
                        ->when(filled($data['value'] ?? null) && ($data['value'] ?? null) !== '__pusat__',
                            fn ($q) => $q->where('outlet_id', $data['value']))),

                // Saring per AREA/WILAYAH outlet penjualnya (master
                // datanya di menu Mitra > Area / Wilayah).
                Tables\Filters\SelectFilter::make('area_id')
                    ->label('Area / Wilayah')
                    ->options(fn () => \App\Models\Area::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['value'] ?? null), fn ($q) => $q->whereHas(
                            'outlet', fn ($oq) => $oq->where('area_id', $data['value'])
                        ))),

                // Rentang TANGGAL TERJUAL (sold_at).
                Tables\Filters\Filter::make('rentang_terjual')
                    ->label('Rentang Tanggal Terjual')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('dari')
                            ->label('Terjual Dari Tanggal')
                            ->native(false)
                            ->closeOnDateSelection(),
                        \Filament\Forms\Components\DatePicker::make('sampai')
                            ->label('Terjual Sampai Tanggal')
                            ->native(false)
                            ->closeOnDateSelection(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $v) => $q->whereDate('sold_at', '>=', $v))
                        ->when($data['sampai'] ?? null, fn ($q, $v) => $q->whereDate('sold_at', '<=', $v)))
                    // Supaya rentang yang aktif kelihatan sebagai "chip"
                    // di atas tabel, bukan cuma jumlah filter aktif.
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['dari'] ?? null) {
                            $indicators[] = 'Terjual dari '.\Illuminate\Support\Carbon::parse($data['dari'])->format('d M Y');
                        }

                        if ($data['sampai'] ?? null) {
                            $indicators[] = 'Terjual sampai '.\Illuminate\Support\Carbon::parse($data['sampai'])->format('d M Y');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->defaultSort('sold_at', 'desc')
            // Klik BARISNYA (bukan cuma tombol) langsung buka popup
            // "Detail Transaksi" -- actionnya didaftarkan di bawah lewat
            // recordActions(), lalu dijadikan default record-click lewat
            // recordAction() persis di bawahnya.
            ->recordActions([
                \App\Filament\Actions\HapusPenjualanAction::untukVoucher(), // HAPUS-PENJUALAN
                static::detailTransaksiAction(),
            ])
            ->recordAction('detail_transaksi');
    }

    /**
     * Popup "Detail Transaksi" saat baris voucher di tabel Voucher Terjual
     * diklik -- SELURUH datanya diambil dari data yang SUDAH ADA (relasi
     * Voucher > profile/outlet/generatedBy/transaction), tidak ada
     * kolom/tabel baru yang ditambah untuk fitur ini.
     *
     * Beberapa nilai di gambar contoh (mis. "PPN") memang tidak pernah
     * dicatat aplikasi ini (tidak ada fitur pajak) -- untuk itu ditampilkan
     * "-" apa adanya, bukan dikarang angkanya.
     */
    protected static function detailTransaksiAction(): Actions\Action
    {
        return Actions\Action::make('detail_transaksi')
            ->label('Detail')
            ->iconButton()
            ->icon('lucide-file-text')
            ->tooltip('Detail Transaksi')
            ->modalHeading('📋 Detail Transaksi')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('✕ Tutup')
            ->schema(fn (Voucher $record) => [
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Section::make('ℹ️ Info Voucher')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Placeholder::make('kode_trx')
                                    ->label('Kode Trx')
                                    ->content($record->transaction?->transaction_number ?? '-'),
                                Forms\Components\Placeholder::make('username')
                                    ->label('Username')
                                    ->content($record->username),
                                Forms\Components\Placeholder::make('password')
                                    ->label('Password')
                                    ->content($record->password),
                                Forms\Components\Placeholder::make('batch')
                                    ->label('Batch')
                                    ->content($record->batch_code ?: '-'),
                                Forms\Components\Placeholder::make('mac')
                                    ->label('MAC')
                                    ->content($record->bound_mac_address ?: '-'),
                                Forms\Components\Placeholder::make('tanggal_login')
                                    ->label('Tanggal Login')
                                    ->content($record->activated_at?->format('d M Y H:i') ?? 'Belum login'),
                                Forms\Components\Placeholder::make('kedaluwarsa')
                                    ->label('Kedaluwarsa')
                                    ->content($record->expired_at
                                        ? new HtmlString('<span style="color:#dc2626;font-weight:600;">'.$record->expired_at->format('d M Y H:i').'</span>')
                                        : '-'),
                                Forms\Components\Placeholder::make('dibuat_pada')
                                    ->label('Dibuat Pada')
                                    ->content($record->created_at?->format('d M Y H:i') ?? '-'),
                                Forms\Components\Placeholder::make('dibuat_oleh')
                                    ->label('Dibuat Oleh')
                                    ->columnSpan(2)
                                    // Voucher yang dibuat outlet lewat bot Telegram tidak
                                    // punya akun User (generatedBy) -- bot cuma tahu Outlet-nya.
                                    // Jadi kalau generatedBy kosong tapi asalnya Telegram dan
                                    // ada outlet-nya, tampilkan nama outlet itu (bukan cuma
                                    // label "Telegram" polos tanpa keterangan siapa).
                                    ->content(
                                        $record->generatedBy?->name
                                        ?? (in_array($record->generated_via, [\App\Enums\VoucherSource::Telegram, \App\Enums\VoucherSource::Qris], true) && $record->outlet
                                            ? $record->outlet->name.' (via '.$record->generated_via->label().')'
                                            : ($record->generated_via?->label() ?? '-'))
                                    ),
                            ]),

                        Section::make('💰 Harga')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Placeholder::make('harga_beli')
                                    ->label('Harga Beli')
                                    ->content('Rp'.number_format((float) $record->cost_price, 0, ',', '.')),
                                Forms\Components\Placeholder::make('harga_jual')
                                    ->label('Harga Jual')
                                    ->content('Rp'.number_format((float) $record->sale_price, 0, ',', '.')),
                                Forms\Components\Placeholder::make('ppn')
                                    ->label('PPN')
                                    ->content('-'),
                                Forms\Components\Placeholder::make('total')
                                    ->label('Total')
                                    ->content(new HtmlString('<b>Rp'.number_format(
                                        (float) ($record->transaction?->total_amount ?? $record->sale_price),
                                        0, ',', '.'
                                    ).'</b>')),
                                Forms\Components\Placeholder::make('komisi')
                                    ->label('Komisi')
                                    ->columnSpan(2)
                                    ->content(function () use ($record) {
                                        $outlet = $record->outlet;

                                        // "Bebas Saldo" HANYA soal outlet ini generate voucher
                                        // tanpa dipotong saldo (lihat GenerateVoucherBatchAction) --
                                        // itu bukan alasan buat tidak dapat komisi dari
                                        // penjualannya. Komisi tetap dihitung dari
                                        // commission_percent outlet, sama untuk semua outlet.
                                        if (! $outlet) {
                                            return 'Rp0';
                                        }

                                        $dasar = (float) ($record->transaction?->total_amount ?? $record->sale_price);
                                        $komisi = $dasar * ((float) $outlet->commission_percent / 100);

                                        return new HtmlString('<span style="color:#16a34a;font-weight:600;">Rp'.number_format($komisi, 0, ',', '.').'</span>');
                                    }),
                            ]),

                        Section::make('📦 Detail Produk')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Placeholder::make('produk')
                                    ->label('Produk')
                                    ->columnSpan(2)
                                    ->content($record->profile?->name ?? '-'),
                                Forms\Components\Placeholder::make('durasi_masa_aktif')
                                    ->label('Durasi | Masa Aktif')
                                    ->columnSpan(2)
                                    ->content(function () use ($record) {
                                        $profile = $record->profile;

                                        if (! $profile) {
                                            return '-';
                                        }

                                        $durasi = ($profile->time_limit_value && $profile->time_limit_unit)
                                            ? $profile->time_limit_value.' '.$profile->time_limit_unit->label()
                                            : 'Unlimited';

                                        $masaAktif = ($profile->validity_days && $profile->validity_unit)
                                            ? $profile->validity_days.' '.$profile->validity_unit->label()
                                            : 'Tanpa batas';

                                        return $durasi.' | '.$masaAktif;
                                    }),
                                Forms\Components\Placeholder::make('share')
                                    ->label('Share')
                                    ->columnSpan(2)
                                    ->content($record->profile?->shared_users ? $record->profile->shared_users.' Pengguna' : '-'),
                            ]),

                        Section::make('🏢 Entitas')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Placeholder::make('kode_entitas')
                                    ->label('Kode Entitas')
                                    ->content($record->outlet?->code ?? '-'),
                                Forms\Components\Placeholder::make('nama_entitas')
                                    ->label('Nama Entitas')
                                    ->content($record->outlet
                                        ? new HtmlString($record->outlet->name.'<br><small style="color:#a1a1aa;">'.$record->outlet->partner_type?->label().'</small>')
                                        : 'Stok Pusat'),
                                Forms\Components\Placeholder::make('tipe_saldo')
                                    ->label('Tipe Saldo')
                                    ->columnSpan(2)
                                    ->content($record->outlet
                                        ? (! $record->outlet->requiresSaldo() ? 'Bebas Saldo (Gratis)' : 'Potong Saldo')
                                        : '-'),
                            ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSoldVouchers::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
