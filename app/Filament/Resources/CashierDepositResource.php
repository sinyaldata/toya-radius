<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Filament\Resources\CashierDepositResource\Pages;
use App\Models\CashierDeposit;
use App\Models\Outlet;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * KASIR — pencatatan setoran uang dari Outlet/Reseller/Biller ke kantor.
 *
 * Tujuannya (permintaan user): supaya bisa diketahui SIAPA SAJA yang
 * sudah menyetorkan uang, berapa, kapan, dan diterima oleh siapa.
 *
 * Perlu dibedakan dari dua hal yang mirip di aplikasi ini supaya tidak
 * tertukar / dobel hitung:
 * - "Riwayat Pembayaran" (payment_logs) = pelanggan membayar tagihannya.
 * - "Top Up Saldo" outlet = outlet menyetor uang untuk MEMBELI stok
 *   voucher, dipotong otomatis saat generate.
 * Yang dicatat di sini adalah setoran hasil tagihan/penjualan yang
 * diserahkan mitra ke kantor.
 */
class CashierDepositResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = CashierDeposit::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Kasir';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'keuangan/kasir';

    public static function getModelLabel(): string
    {
        return 'Setoran';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kasir — Setoran Mitra';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('outlet_id')
                ->label('Penyetor (Outlet / Reseller / Biller)')
                ->options(fn () => Outlet::orderBy('name')
                    ->get(['id', 'name', 'partner_type'])
                    // Jenis mitranya ikut ditulis di daftar pilihan
                    // supaya kasir tidak bingung kalau ada Outlet dan
                    // Biller yang namanya mirip.
                    ->mapWithKeys(fn ($o) => [
                        $o->id => $o->name.' ('.($o->partner_type?->label() ?? '-').')',
                    ]))
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\TextInput::make('amount')
                ->label('Jumlah Setoran')
                ->numeric()
                ->prefix('Rp')
                ->minValue(1)
                ->required(),

            Forms\Components\DatePicker::make('deposit_date')
                ->label('Tanggal Setor')
                ->default(now())
                ->required(),

            Forms\Components\Select::make('payment_method')
                ->label('Metode')
                ->options(PaymentMethod::options())
                ->default('tunai')
                ->required(),

            Forms\Components\TextInput::make('reference')
                ->label('No. Bukti / Referensi')
                ->placeholder('mis. no. kuitansi atau no. transfer')
                ->maxLength(100),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('deposit_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('outlet.name')
                    ->label('Penyetor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('outlet.partner_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Metode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PaymentMethod::tryFrom($state)?->label() ?? $state)
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('reference')
                    ->label('No. Bukti')
                    ->placeholder('-')
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('receivedBy.name')
                    ->label('Diterima Oleh')
                    ->placeholder('-')
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('outlet_id')
                    ->label('Penyetor')
                    ->options(fn () => Outlet::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('periode')
                    ->schema([
                        Forms\Components\DatePicker::make('dari')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('sampai')->label('Sampai Tanggal'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $v) => $q->whereDate('deposit_date', '>=', $v))
                        ->when($data['sampai'] ?? null, fn ($q, $v) => $q->whereDate('deposit_date', '<=', $v))),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Terima Setoran')
                    ->icon('lucide-hand-coins')
                    ->color('primary')
                    ->modalHeading('Terima Setoran Mitra')
                    ->modalWidth('xl')
                    // Kasir yang sedang login otomatis tercatat sebagai
                    // penerima — tidak diisi manual supaya tidak bisa
                    // diakali/salah ketik.
                    ->mutateFormDataUsing(function (array $data) {
                        $data['received_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->modalHeading('Hapus Setoran?')
                    ->modalDescription('Catatan setoran ini akan dihapus permanen. Pakai hanya kalau memang salah input.'),
            ])
            ->defaultSort('deposit_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashierDeposits::route('/'),
        ];
    }
}
