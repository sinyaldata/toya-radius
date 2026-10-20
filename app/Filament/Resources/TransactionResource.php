<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Transaction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-banknote';

    protected static ?string $navigationLabel = 'Transaksi';

    // Diberi grup sendiri ("Transaksi") supaya posisinya di sidebar bisa
    // diatur lewat urutan ->navigationGroups() di AdminPanelProvider.
    // Kalau TIDAK diberi grup, Filament selalu menaruh item tanpa grup
    // paling atas sendiri (di atas Master Data dkk), jadi tidak bisa
    // diletakkan di antara Keuangan dan Laporan seperti yang diminta.
    protected static string|\UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return 'Transaksi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Transaksi';
    }

    public static function form(Schema $schema): Schema
    {
        // Transaksi dibuat lewat aksi "Jual" di Hotspot > User, bukan form langsung —
        // form di sini hanya dipakai untuk melihat detail (read-only) saat perlu.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('transaction_number')->label('No. Transaksi')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('transaction_date')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet')->placeholder('Stok Pusat'),
                Tables\Columns\TextColumn::make('voucher.username')->label('Voucher'),
                Tables\Columns\TextColumn::make('total_amount')->label('Total')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('payment_method')->label('Metode')->badge(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('outlet_id')->relationship('outlet', 'name')->label('Outlet'),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options(PaymentMethod::options()),
                Tables\Filters\Filter::make('transaction_date')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                \App\Filament\Actions\HapusPenjualanAction::untukTransaksi(), // HAPUS-PENJUALAN
                Actions\ViewAction::make()->iconButton(),
            ])
            ->defaultSort('transaction_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // transaksi hanya dibuat lewat aksi "Jual" di Hotspot > User
    }
}
