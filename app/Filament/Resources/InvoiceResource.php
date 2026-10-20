<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Semua';

    protected static string|\UnitEnum|null $navigationGroup = 'Invoice';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'Tagihan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tagihan';
    }

    public static function form(Schema $schema): Schema
    {
        // Invoice dibuat otomatis lewat scheduler (invoices:generate-bulanan), bukan manual.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('invoice_number')->label('No. Invoice')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('customer.customer_number')->label('No. Pelanggan'),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', decimalPlaces: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('sisa')
                    ->label('Sisa')
                    ->state(fn (Invoice $record) => $record->remainingAmount())
                    ->money('IDR', decimalPlaces: 0)
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('paid_at')->label('Dibayar')->dateTime('d M Y H:i')->placeholder('-'),
                Tables\Columns\TextColumn::make('biller.name')
                    ->label('Biller')
                    ->placeholder('-')
                    ->description(fn (Invoice $record) => $record->biller_id ? 'Lewat bot Telegram' : null)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(InvoiceStatus::options()),
                Tables\Filters\SelectFilter::make('customer_id')->relationship('customer', 'name')->label('Pelanggan')->searchable(),
                Tables\Filters\SelectFilter::make('biller_id')
                    ->label('Biller')
                    ->relationship('biller', 'name')
                    ->searchable(),
            ])
            ->headerActions([
                // Dipindah ke sini dari halaman List — sekarang sejajar
                // dengan Search, sama seperti pola tombol di halaman lain.
                // Diubah (permintaan user): sekarang bisa PILIH bulan &
                // tahun yang mau digenerate, bukan cuma bulan berjalan
                // terus -- jadi bisa dipakai buat backfill invoice bulan
                // yang kelewat, ATAU sengaja generate invoice bulan
                // depan lebih awal (pengganti sederhana untuk konsep
                // "postpaid"/fleksibel, tanpa perlu bikin toggle mode
                // billing baru). InvoiceService::generateMonthlyInvoices()
                // sendiri sudah menerima parameter periode manapun sejak
                // awal (dipakai command terjadwal tiap tanggal 1), jadi
                // tidak ada perubahan di service-nya sama sekali -- ini
                // cuma nambah pilihan bulan/tahun di sisi form-nya.
                Actions\Action::make('generate_manual')
                    ->label('Generate Tagihan')
                    ->icon('lucide-receipt')
                    ->color('primary')
                    ->modalHeading('Generate Tagihan')
                    ->modalDescription('Membuat tagihan untuk BULAN & TAHUN yang dipilih, untuk SEMUA pelanggan aktif (Hotspot tetap & PPPoE) yang belum punya invoice periode itu. Aman dijalankan berkali-kali — pelanggan yang sudah punya invoice periode itu dilewati, tidak dobel.')
                    ->schema([
                        Forms\Components\Select::make('bulan')
                            ->label('Bulan')
                            ->options([
                                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                            ])
                            ->default(now()->month)
                            ->required()
                            ->native(false),
                        Forms\Components\Select::make('tahun')
                            ->label('Tahun')
                            ->options(function () {
                                $sekarang = now()->year;

                                // Rentang cukup lebar (2 tahun ke belakang,
                                // 1 tahun ke depan) supaya bisa backfill
                                // invoice bulan yang kelewat, atau sengaja
                                // bikin invoice bulan depan lebih awal.
                                return collect(range($sekarang - 2, $sekarang + 1))
                                    ->reverse()
                                    ->mapWithKeys(fn ($y) => [$y => (string) $y])
                                    ->all();
                            })
                            ->default(now()->year)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) {
                        $periode = \Illuminate\Support\Carbon::create((int) $data['tahun'], (int) $data['bulan'], 1);

                        $count = app(InvoiceService::class)->generateMonthlyInvoices($periode);

                        Notification::make()
                            ->title($count.' invoice baru berhasil dibuat')
                            ->body('Periode: '.$periode->translatedFormat('F Y'))
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('check_overdue_manual')
                    ->label('Cek Overdue & Isolir')
                    ->icon('lucide-alert-triangle')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Mengecek SEMUA invoice yang sudah lewat jatuh tempo tapi belum dibayar — invoice itu akan ditandai Overdue, dan pelanggannya otomatis diisolir (diputus akses internetnya) sampai bayar.')
                    ->action(function () {
                        $count = app(InvoiceService::class)->markOverdueAndIsolir();

                        Notification::make()
                            ->title($count.' pelanggan diisolir (invoice overdue)')
                            ->warning()
                            ->send();
                    }),

                // Hapus BANYAK invoice sekaligus lewat checkbox (permintaan
                // user): kalau generate bulanan menghasilkan banyak invoice
                // yang salah (mis. paket pelanggan baru saja diubah), tidak
                // perlu hapus satu-satu lagi.
                //
                // Ditaruh di ->headerActions() (bukan ->toolbarActions())
                // MENGIKUTI POLA YANG SUDAH TERBUKTI di HotspotUserResource:
                // tetap BulkAction asli Filament (pemilihan baris 100%
                // bawaan Filament lewat parameter $records), cuma posisinya
                // dipindah supaya sebaris dengan tombol header lain.
                // Kehadiran BulkAction inilah yang otomatis memunculkan
                // kolom checkbox di tabel.
                Actions\BulkAction::make('hapus_selected')
                    ->extraAttributes([
                        // Tetap tampil (pudar) walau belum ada baris
                        // dicentang -- sama seperti di menu Voucher.
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Hapus')
                    ->icon('lucide-trash-2')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus tagihan terpilih')
                    ->modalDescription('Tagihan yang SUDAH ADA PEMBAYARANNYA tidak akan dihapus — otomatis dilewati, supaya riwayat pembayaran pelanggan tetap nyambung. Kalau tagihan itu memang harus dibuang, batalkan dulu pembayarannya lewat menu Riwayat Pembayaran.')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (\Illuminate\Support\Collection $records) => static::hapusInvoiceAman($records)),
            ])
            ->recordActions([
                // Digabung jadi SATU tombol "Cetak" dengan menu pilihan
                // (permintaan user: "tombol print... setelah diklik ada
                // pilihan printer") -- sebelumnya 2 tombol ikon terpisah
                // yang SELALU kelihatan berdampingan. Route & fungsi cetak
                // di baliknya TIDAK diubah sama sekali, cuma cara
                // memicunya yang dirapikan jadi satu tombol + dropdown.
                Actions\ActionGroup::make([
                    Actions\Action::make('print_thermal')
                        ->label('Printer POS (Thermal)')
                        ->icon('lucide-receipt')
                        ->url(fn (Invoice $record) => route('invoice.print.thermal', $record))
                        ->openUrlInNewTab(),
                    Actions\Action::make('print_regular')
                        ->label('Printer Biasa (A4/F4)')
                        ->icon('lucide-printer')
                        ->url(fn (Invoice $record) => route('invoice.print.regular', $record))
                        ->openUrlInNewTab(),
                ])
                    ->label('Cetak')
                    ->icon('lucide-printer')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Cetak Invoice'),
                Actions\Action::make('pay')
                    ->label('Bayar')
                    ->icon('lucide-banknote')
                    ->color('success')
                    ->visible(fn (Invoice $record) => $record->status !== InvoiceStatus::Paid)
                    ->modalHeading(fn (Invoice $record) => 'Catat Pembayaran — '.$record->customer?->name)
                    ->modalDescription(function (Invoice $record, InvoiceService $invoiceService) {
                        $total = $invoiceService->getOutstandingBalance($record->customer);

                        return 'Total SEMUA tagihan pelanggan ini yang belum lunas (termasuk bulan-bulan lain kalau ada): Rp'.number_format($total, 0, ',', '.').
                            '. Jumlah yang dimasukkan akan OTOMATIS dipakai untuk melunasi tagihan dari yang PALING LAMA dulu — kalau lebih dari total tagihan, sisanya disimpan sebagai saldo lebih bayar untuk bulan berikutnya.';
                    })
                    ->schema([
                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah Dibayar')
                            ->numeric()
                            ->prefix('Rp')
                            ->required()
                            ->default(fn (Invoice $record) => $record->remainingAmount()),
                        Forms\Components\Select::make('payment_method')
                            ->label('Metode Pembayaran')
                            ->options(PaymentMethod::options())
                            ->required(),
                        Forms\Components\Textarea::make('notes')->label('Catatan'),
                    ])
                    ->action(function (Invoice $record, array $data, InvoiceService $invoiceService) {
                        $result = $invoiceService->recordPayment(
                            $record->customer,
                            (float) $data['amount'],
                            $data['payment_method'],
                            $data['notes'] ?? null
                        );

                        $paidCount = count($result['invoices_paid']);
                        $partialCount = count($result['invoices_partial']);
                        $credit = $result['credit_added'];

                        $body = $paidCount.' tagihan lunas penuh.';
                        if ($partialCount > 0) {
                            $body .= ' '.$partialCount.' tagihan terbayar sebagian (belum lunas semua).';
                        }
                        if ($credit > 0) {
                            $body .= ' Sisa Rp'.number_format($credit, 0, ',', '.').' disimpan sebagai saldo lebih bayar untuk bulan berikutnya.';
                        }

                        Notification::make()->title('Pembayaran berhasil dicatat')->body($body)->success()->send();
                    }),

                // Tombol Hapus BARU (permintaan user): buat invoice yang
                // salah generate -- misalnya karena paket pelanggan
                // berubah tepat sebelum/sesudah invoice bulanan otomatis
                // dibuat, jadi jumlahnya salah/dobel. SENGAJA hanya
                // tampil kalau invoice ini BELUM ada pembayaran tercatat
                // sama sekali (amount_paid <= 0) -- invoices tidak
                // terhubung ke payment_logs lewat foreign key sungguhan
                // (cuma dicatat di kolom JSON "allocations"), jadi kalau
                // invoice yang SUDAH kena alokasi pembayaran dihapus,
                // riwayat pembayaran pelanggan itu jadi tidak nyambung
                // lagi. Kalau ternyata invoice yang mau dihapus sudah
                // ada pembayarannya, batalkan dulu pembayarannya lewat
                // menu Riwayat Pembayaran (tombol Hapus di PaymentLog),
                // baru invoice ini bisa dihapus.
                Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->iconButton()
                    ->visible(fn (Invoice $record) => (float) $record->amount_paid <= 0)
                    ->modalHeading('Hapus Invoice?')
                    ->modalDescription(fn (Invoice $record) => 'Invoice '.$record->invoice_number.' ('.($record->customer?->name ?? '-').', Rp'.number_format((float) $record->amount, 0, ',', '.').') akan DIHAPUS PERMANEN dan tidak bisa dibatalkan. Pakai ini kalau invoice ini memang salah generate (misalnya gara-gara paket pelanggan baru saja diganti).')
                    ->successNotificationTitle('Invoice berhasil dihapus'),
            ])
            ->defaultSort('due_date', 'desc');
    }

    /**
     * Menghapus banyak invoice sekaligus DENGAN PENGAMAN: invoice yang
     * sudah pernah kena pembayaran (amount_paid > 0) dilewati, tidak
     * dihapus.
     *
     * Alasannya sama persis dengan tombol Hapus per-baris di atas:
     * invoices TIDAK terhubung ke payment_logs lewat foreign key
     * sungguhan (alokasi pembayaran cuma dicatat di kolom JSON
     * "allocations"), jadi menghapus invoice yang sudah kena alokasi
     * bikin riwayat pembayaran pelanggan tidak nyambung lagi.
     *
     * Dipakai bersama oleh menu "Semua" dan "Belum Bayar".
     */
    public static function hapusInvoiceAman(\Illuminate\Support\Collection $records): void
    {
        $terhapus = 0;
        $dilewati = 0;

        foreach ($records as $record) {
            if ((float) $record->amount_paid > 0) {
                $dilewati++;

                continue;
            }

            $record->delete();
            $terhapus++;
        }

        Notification::make()
            ->title($terhapus.' tagihan dihapus')
            ->body($dilewati > 0 ? $dilewati.' dilewati karena sudah ada pembayaran tercatat.' : null)
            ->status($dilewati > 0 ? 'warning' : 'success')
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
