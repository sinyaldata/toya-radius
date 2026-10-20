<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoiceUnpaidResource\Pages;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceUnpaidResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Belum Bayar';

    protected static string|\UnitEnum|null $navigationGroup = 'Invoice';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'invoice/belum-bayar';

    public static function getModelLabel(): string
    {
        return 'Tagihan Belum Bayar';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tagihan Belum Bayar';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn () => Invoice::query()->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue]))
            ->columns([
                Tables\Columns\TextColumn::make('index')->rowIndex()->label('No'),
                Tables\Columns\TextColumn::make('invoice_number')->label('No. Invoice')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('customer.customer_number')->label('No. Pelanggan'),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')->relationship('customer', 'name')->label('Pelanggan')->searchable(),
            ])
            ->headerActions([
                // Hapus BANYAK tagihan sekaligus lewat checkbox. Logika
                // pengamannya dipakai bareng dengan menu "Semua" -- lihat
                // InvoiceResource::hapusInvoiceAman(). Kehadiran BulkAction
                // inilah yang otomatis memunculkan kolom checkbox di tabel.
                Actions\BulkAction::make('hapus_selected')
                    ->extraAttributes([
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Hapus')
                    ->icon('lucide-trash-2')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus tagihan terpilih')
                    ->modalDescription('Tagihan yang SUDAH ADA PEMBAYARAN SEBAGIAN tidak akan dihapus — otomatis dilewati supaya riwayat pembayaran pelanggan tetap nyambung.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (\Illuminate\Support\Collection $records) => InvoiceResource::hapusInvoiceAman($records)),
            ])
            ->recordActions([
                Actions\Action::make('pay')
                    ->label('Bayar')
                    ->icon('lucide-banknote')
                    ->color('success')
                    ->iconButton()
                    ->schema([
                        Forms\Components\Select::make('payment_method')
                            ->label('Metode Pembayaran')
                            ->options(PaymentMethod::options())
                            ->required(),
                        Forms\Components\Textarea::make('notes')->label('Catatan'),
                    ])
                    ->action(function (Invoice $record, array $data, InvoiceService $invoiceService) {
                        $invoiceService->pay($record, $data['payment_method'], $data['notes'] ?? null);
                        Notification::make()->title('Tagihan berhasil ditandai lunas')->success()->send();
                    }),

                // Sama seperti di InvoiceResource (menu "Semua") -- lihat
                // catatan lengkap di sana. Di sini SELALU tampil karena
                // daftar ini isinya memang invoice yang belum ada
                // pembayaran sama sekali (Unpaid/Overdue), jadi cocok
                // untuk membuang invoice yang salah generate akibat
                // perubahan paket pelanggan.
                Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->iconButton()
                    ->visible(fn (Invoice $record) => (float) $record->amount_paid <= 0)
                    ->modalHeading('Hapus Invoice?')
                    ->modalDescription(fn (Invoice $record) => 'Invoice '.$record->invoice_number.' ('.($record->customer?->name ?? '-').', Rp'.number_format((float) $record->amount, 0, ',', '.').') akan DIHAPUS PERMANEN dan tidak bisa dibatalkan. Pakai ini kalau invoice ini memang salah generate (misalnya gara-gara paket pelanggan baru saja diganti).')
                    ->successNotificationTitle('Invoice berhasil dihapus'),
            ])
            ->defaultSort('due_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoiceUnpaid::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
