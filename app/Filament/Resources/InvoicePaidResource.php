<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoicePaidResource\Pages;
use App\Models\Invoice;
use App\Models\PaymentLog;
use App\Services\InvoiceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class InvoicePaidResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Sudah Bayar';

    protected static string|\UnitEnum|null $navigationGroup = 'Invoice';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'invoice/sudah-bayar';

    public static function getModelLabel(): string
    {
        return 'Tagihan Lunas';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tagihan Lunas';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn () => Invoice::query()->where('status', InvoiceStatus::Paid))
            ->columns([
                Tables\Columns\TextColumn::make('index')->rowIndex()->label('No'),
                Tables\Columns\TextColumn::make('invoice_number')->label('No. Invoice')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('customer.customer_number')->label('No. Pelanggan'),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('paid_at')->label('Dibayar')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('biller.name')
                    ->label('Biller')
                    ->placeholder('-')
                    ->description(fn (Invoice $record) => $record->biller_id ? 'Lewat bot Telegram' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')->relationship('customer', 'name')->label('Pelanggan')->searchable(),
                Tables\Filters\SelectFilter::make('biller_id')
                    ->label('Biller')
                    ->relationship('biller', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                Actions\Action::make('edit_pembayaran')
                    ->label('Edit')
                    ->icon('lucide-pencil')
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (Invoice $record) => 'Edit Pembayaran — '.$record->invoice_number)
                    ->modalSubmitActionLabel('Simpan')
                    ->schema(function (Invoice $record) {
                        $log = static::paymentLogUntukInvoice($record);

                        return [
                            Forms\Components\Select::make('payment_method')
                                ->label('Metode Pembayaran')
                                ->options(PaymentMethod::options())
                                ->default($log?->payment_method ?? $record->payment_method)
                                ->required(),
                            Forms\Components\Textarea::make('notes')
                                ->label('Catatan')
                                ->default($log?->notes ?? $record->notes)
                                ->rows(2),
                        ];
                    })
                    ->action(function (Invoice $record, array $data) {
                        // Cuma metode & catatan yang boleh diedit langsung
                        // di sini -- sama seperti aturan di Riwayat
                        // Pembayaran (PaymentLogResource). Jumlah/alokasi
                        // TIDAK bisa diubah lewat form biasa, karena bisa
                        // merusak kecocokan dengan saldo & invoice lain --
                        // kalau jumlahnya salah, pakai tombol Hapus di
                        // bawah lalu catat ulang dari awal.
                        $log = static::paymentLogUntukInvoice($record);

                        if ($log) {
                            $log->update([
                                'payment_method' => $data['payment_method'],
                                'notes' => $data['notes'] ?? null,
                            ]);
                        }

                        $record->update([
                            'payment_method' => $data['payment_method'],
                            'notes' => $data['notes'] ?? null,
                        ]);

                        Notification::make()->title('Data pembayaran diperbarui')->success()->send();
                    }),
                Actions\Action::make('hapus_pembayaran')
                    ->label('Hapus')
                    ->icon('lucide-trash-2')
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus Pembayaran Ini?')
                    ->modalDescription(function (Invoice $record) {
                        $log = static::paymentLogUntukInvoice($record);

                        if (! $log) {
                            return 'Tagihan ini akan DIKEMBALIKAN ke status belum lunas. Tidak ditemukan catatan pembayaran (Riwayat Pembayaran) untuk transaksi ini -- kemungkinan data lama -- jadi saldo lebih bayar pelanggan (kalau ada) TIDAK ikut disesuaikan otomatis. Tindakan ini tidak bisa dibatalkan lagi.';
                        }

                        $jumlahInvoiceLain = collect($log->allocations)->count() - 1;

                        $peringatanLain = $jumlahInvoiceLain > 0
                            ? " PENTING: satu pembayaran ini juga mencakup {$jumlahInvoiceLain} invoice LAIN -- semuanya akan ikut dikembalikan ke status belum lunas, bukan cuma tagihan ini saja."
                            : '';

                        return 'Tagihan ini akan DIKEMBALIKAN ke status belum lunas (atau lunas sebagian, sesuai porsi pembayaran ini). Kalau ada saldo lebih bayar yang dihasilkan pembayaran ini, saldo itu juga akan dikurangi.'.$peringatanLain.' Tindakan ini tidak bisa dibatalkan lagi.';
                    })
                    ->action(function (Invoice $record, InvoiceService $invoiceService) {
                        $log = static::paymentLogUntukInvoice($record);

                        if ($log) {
                            // Lewat InvoiceService::undoPayment() yang SAMA
                            // dipakai tombol "Batalkan" di Riwayat
                            // Pembayaran -- supaya semua invoice yang ikut
                            // tercakup pembayaran ini (bisa lebih dari
                            // satu) dan saldo lebih bayar ikut dipulihkan
                            // dengan benar, bukan cuma baris ini saja.
                            $invoiceService->undoPayment($log);
                        } else {
                            // Tidak ada Riwayat Pembayaran yang cocok (data
                            // lama sebelum pencatatan ini ada) -- kembalikan
                            // invoice ini saja, tanpa menyentuh saldo
                            // pelanggan karena tidak ada yang bisa dipulihkan.
                            $record->update([
                                'amount_paid' => 0,
                                'status' => $record->due_date && $record->due_date->isPast()
                                    ? InvoiceStatus::Overdue
                                    : InvoiceStatus::Unpaid,
                                'paid_at' => null,
                                'payment_method' => null,
                                'notes' => null,
                                'biller_id' => null,
                            ]);
                        }

                        Notification::make()
                            ->title('Pembayaran dihapus')
                            ->body('Tagihan sudah dikembalikan ke status belum lunas.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('paid_at', 'desc');
    }

    /**
     * PaymentLog TIDAK punya kolom invoice_id langsung -- cakupannya
     * dicatat lewat kolom `allocations` (JSON array of {invoice_id,
     * amount}), karena satu pembayaran bisa melunasi beberapa invoice
     * sekaligus. Jadi untuk cari pembayaran mana yang melunasi SATU
     * invoice tertentu, disaring dulu per pelanggan (murah), baru
     * dicari di antara allocations-nya mana yang menyebut invoice ini.
     */
    protected static function paymentLogUntukInvoice(Invoice $record): ?PaymentLog
    {
        return PaymentLog::where('customer_id', $record->customer_id)
            ->get()
            ->first(fn (PaymentLog $log) => collect($log->allocations)
                ->contains(fn ($alloc) => (int) ($alloc['invoice_id'] ?? 0) === $record->id));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoicePaid::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
