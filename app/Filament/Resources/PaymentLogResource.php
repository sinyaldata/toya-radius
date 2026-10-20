<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentLogResource\Pages;
use App\Models\PaymentLog;
use App\Services\InvoiceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Riwayat semua pembayaran yang tercatat lewat tombol "Bayar" di Invoice
 * (InvoiceService::recordPayment()). Beda dari daftar Invoice — di sini
 * per TRANSAKSI pembayaran (bisa kena banyak invoice sekaligus), bukan
 * per invoice.
 */
class PaymentLogResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = PaymentLog::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Invoice';

    protected static ?string $navigationLabel = 'Riwayat Pembayaran';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'Riwayat Pembayaran';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Riwayat Pembayaran';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        // Cuma boleh edit metode & catatan — jumlah & alokasi TIDAK bisa
        // diubah langsung di sini (kalau salah jumlah, lebih aman Hapus
        // lalu catat ulang dari awal lewat tombol Bayar di Invoice, biar
        // alokasi ke invoice-nya ikut dihitung ulang dengan benar).
        return $schema->columns(1)->components([
            Forms\Components\Select::make('payment_method')
                ->label('Metode Pembayaran')
                ->options(\App\Enums\PaymentMethod::options())
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn () => PaymentLog::query()->with(['customer', 'recordedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('created_at')->label('Tanggal')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah Dibayar')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('credit_added')->label('Jadi Saldo')->money('IDR', decimalPlaces: 0)->alignEnd()->placeholder('-'),
                Tables\Columns\TextColumn::make('payment_method')->label('Metode')->badge(),
                Tables\Columns\TextColumn::make('allocations')
                    ->label('Invoice Terkait')
                    ->state(fn (PaymentLog $record) => count($record->allocations).' invoice'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Dicatat Oleh')->placeholder('-'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->label('Edit')
                    ->iconButton(),
                Actions\Action::make('undo')
                    ->label('Batalkan')
                    ->icon('lucide-undo-2')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Pembayaran Ini?')
                    ->modalDescription('Invoice yang tadinya kena alokasi dari pembayaran ini akan DIKEMBALIKAN ke status belum lunas (atau lunas sebagian dikurangi sesuai porsi pembayaran ini). Kalau ada saldo lebih bayar yang dihasilkan pembayaran ini, saldo itu juga akan dikurangi. Tindakan ini tidak bisa dibatalkan lagi.')
                    ->action(function (PaymentLog $record, InvoiceService $invoiceService) {
                        $invoiceService->undoPayment($record);

                        Notification::make()
                            ->title('Pembayaran dibatalkan')
                            ->body('Invoice terkait sudah dikembalikan ke status semula.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentLogs::route('/'),
            'edit' => Pages\EditPaymentLog::route('/{record}/edit'),
        ];
    }
}
