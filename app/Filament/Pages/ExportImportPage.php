<?php

namespace App\Filament\Pages;

use App\Enums\PartnerType;
use App\Exports\OutletExport;
use App\Exports\PppoeUserExport;
use App\Exports\VoucherExport;
use App\Imports\OutletImport;
use App\Imports\PppoeUserImport;
use App\Imports\VoucherImport;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ExportImportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Export / Import';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Export / Import Data';

    protected string $view = 'filament.pages.export-import-page';

    // PENTING: field FileUpload di custom Page WAJIB lewat pola array
    // $data + ->statePath('data') seperti ini (persis kayak SettingsPage.php
    // yang sudah lama jalan normal) — BUKAN properti mentah per-field
    // (public ?string $outletFile) seperti sebelumnya. Itu ternyata bug
    // yang sudah dikenal di Filament: FileUpload yang di-bind LANGSUNG ke
    // properti biasa (bukan lewat $data array) bikin Livewire gagal
    // serialize state upload-nya, muncul sebagai "Property type not
    // supported in Livewire for property: [null]" — persis error yang
    // dilaporkan. Sumber: github.com/filamentphp/filament/discussions/10342
    public ?array $data = [];

    public ?array $exportData = [];

    public function mount(): void
    {
        $this->importForm->fill();
        $this->exportForm->fill();
    }

    /**
     * Filament otomatis mengenali method bernama "<namaForm>Form" sebagai
     * form terpisah (tidak perlu didaftarkan manual) — dirender lewat
     * {{ $this->importForm }} di view. Dipisah dari exportForm() di bawah
     * supaya field FileUpload (import) dan Select filter (export) tidak
     * duduk di form/statePath yang sama — lebih rapi & tidak perlu trik
     * ekstraksi komponen satu-satu yang riskan.
     */
    public function importForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('outletFile')
                    ->label('File Excel Outlet')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
                    ->disk('public')
                    ->directory('imports')
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'outlet'"]),

                FileUpload::make('resellerFile')
                    ->label('File Excel Reseller')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
                    ->disk('public')
                    ->directory('imports')
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'reseller'"]),

                FileUpload::make('billerFile')
                    ->label('File Excel Biller')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
                    ->disk('public')
                    ->directory('imports')
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'biller'"]),

                FileUpload::make('voucherFile')
                    ->label('File Excel Voucher')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
                    ->disk('public')
                    ->directory('imports')
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'voucher'"]),

                // Outlet & Profile DEFAULT buat semua baris di file Voucher
                // yang diimport — dipakai kalau baris itu SENDIRI tidak
                // punya kolom Outlet/Profile di Excel-nya. Kalau baris-nya
                // PUNYA kolom itu, nilai di baris yang menang (lebih
                // spesifik daripada default ini).
                Select::make('voucherOutletId')
                    ->label('Outlet Default (opsional)')
                    ->options(fn () => \App\Models\Outlet::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'voucher'"]),
                Select::make('voucherProfileId')
                    ->label('Profile Default (opsional)')
                    ->options(fn () => \App\Models\ServicePackage::where('is_active', true)
                        ->whereIn('type', ['hotspot', 'both'])->pluck('name', 'id'))
                    ->searchable()
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'voucher'"]),

                FileUpload::make('pppoeFile')
                    ->label('File Excel User PPPoE')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'])
                    ->disk('public')
                    ->directory('imports')
                    ->extraFieldWrapperAttributes(['x-show' => "importType === 'pppoe'"]),
            ])
            ->statePath('data');
    }

    /**
     * Form TERPISAH khusus filter Export Voucher (Outlet/Profile) —
     * statePath sendiri ("exportData"), tidak nyampur sama sekali
     * dengan field upload di importForm() di atas.
     */
    public function exportForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('outletId')
                    ->label('Filter Outlet (opsional)')
                    ->options(fn () => \App\Models\Outlet::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Semua Outlet'),
                Select::make('profileId')
                    ->label('Filter Profile (opsional)')
                    ->options(fn () => \App\Models\ServicePackage::where('is_active', true)
                        ->whereIn('type', ['hotspot', 'both'])->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Semua Profile'),
            ])
            ->statePath('exportData');
    }

    /**
     * Satu tombol Import generik — dipanggil dengan argumen tipe data
     * langsung dari tombol (wire:click="runImport('pppoe')"), BUKAN dari
     * property Livewire terpisah yang reactive (sempat bikin error
     * "Property type not supported" — dihindari sepenuhnya sekarang).
     */
    public function runImport(string $type): void
    {
        match ($type) {
            'outlet' => $this->importOutlet(),
            'reseller' => $this->importReseller(),
            'biller' => $this->importBiller(),
            'voucher' => $this->importVoucher(),
            'pppoe' => $this->importPppoe(),
            default => null,
        };
    }

    // ===== EXPORT =====

    public function exportOutlet()
    {
        return Excel::download(new OutletExport(PartnerType::Outlet), 'outlet.xlsx');
    }

    public function exportReseller()
    {
        return Excel::download(new OutletExport(PartnerType::Reseller), 'reseller.xlsx');
    }

    public function exportBiller()
    {
        return Excel::download(new OutletExport(PartnerType::Biller), 'biller.xlsx');
    }

    public function exportVoucher()
    {
        $filters = $this->exportForm->getState();

        return Excel::download(
            new VoucherExport($filters['outletId'] ?? null, $filters['profileId'] ?? null),
            'voucher.xlsx'
        );
    }

    public function exportPppoe()
    {
        return Excel::download(new PppoeUserExport, 'user-pppoe.xlsx');
    }

    // ===== IMPORT =====

    protected function doImport(string|array|null $path, $importer, string $label): void
    {
        // FileUpload di pola $data ini nyimpen state-nya sebagai ARRAY
        // (daftar path) walau cuma 1 file yang diupload (bukan string
        // polos seperti dugaan sebelumnya) — diambil elemen pertamanya.
        if (is_array($path)) {
            $path = array_values($path)[0] ?? null;
        }

        if (! $path) {
            Notification::make()->title('Pilih file Excel dulu')->warning()->send();

            return;
        }

        try {
            $fullPath = storage_path('app/public/'.$path);
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

            $readerType = match ($extension) {
                'csv' => \Maatwebsite\Excel\Excel::CSV,
                'xls' => \Maatwebsite\Excel\Excel::XLS,
                default => \Maatwebsite\Excel\Excel::XLSX,
            };

            Excel::import($importer, $fullPath, null, $readerType);

            // Kalau importer-nya lacak baris yang dilewati (saat ini baru
            // PppoeUserImport), tampilkan ringkasannya — supaya "berhasil
            // upload tapi kok tidak ada di tabel" ada penjelasannya,
            // bukan cuma notifikasi sukses kosong.
            if (property_exists($importer, 'skipped') && ! empty($importer->skipped)) {
                Notification::make()
                    ->title($label.' selesai diimport — tapi ada baris yang dilewati')
                    ->body(implode("\n", array_slice($importer->skipped, 0, 8)).
                        (count($importer->skipped) > 8 ? "\n... dan ".(count($importer->skipped) - 8).' baris lainnya' : ''))
                    ->warning()
                    ->persistent()
                    ->send();
            } else {
                Notification::make()->title($label.' berhasil diimport')->success()->send();
            }
        } catch (Throwable $e) {
            Notification::make()
                ->title('Gagal import '.$label)
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function importOutlet(): void
    {
        $state = $this->importForm->getState();
        $this->doImport($state['outletFile'] ?? null, new OutletImport(PartnerType::Outlet), 'Outlet');
        $this->data['outletFile'] = null;
    }

    public function importReseller(): void
    {
        $state = $this->importForm->getState();
        $this->doImport($state['resellerFile'] ?? null, new OutletImport(PartnerType::Reseller), 'Reseller');
        $this->data['resellerFile'] = null;
    }

    public function importBiller(): void
    {
        $state = $this->importForm->getState();
        $this->doImport($state['billerFile'] ?? null, new OutletImport(PartnerType::Biller), 'Biller');
        $this->data['billerFile'] = null;
    }

    public function importVoucher(): void
    {
        $state = $this->importForm->getState();
        $this->doImport(
            $state['voucherFile'] ?? null,
            new VoucherImport($state['voucherOutletId'] ?? null, $state['voucherProfileId'] ?? null),
            'Voucher'
        );
        $this->data['voucherFile'] = null;
    }

    public function importPppoe(): void
    {
        $state = $this->importForm->getState();
        $this->doImport($state['pppoeFile'] ?? null, app(PppoeUserImport::class), 'User PPPoE');
        $this->data['pppoeFile'] = null;
    }

    /**
     * Provisioning massal — untuk pelanggan PPPoE hasil import Excel (atau
     * mana pun) yang datanya SUDAH ada di tabel customers tapi BELUM
     * tersinkron ke tabel RADIUS (radcheck/radreply/radusergroup), jadi
     * belum bisa dipakai login. Cuma memproses yang benar-benar belum
     * ke-provisioning — aman dijalankan berkali-kali, tidak dobel.
     */
    public function provisionPppoeMassal(): void
    {
        $service = app(\App\Services\CustomerRadiusService::class);

        $customers = \App\Models\Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', \App\Enums\CustomerType::Pppoe)
            ->whereDoesntHave('radcheck')
            ->with('servicePackage')
            ->get();

        if ($customers->isEmpty()) {
            Notification::make()->title('Tidak ada pelanggan PPPoE yang perlu di-provisioning')->success()->send();

            return;
        }

        $success = 0;
        $skipped = [];

        foreach ($customers as $customer) {
            if (! $customer->servicePackage) {
                $skipped[] = $customer->username.' (Profile tidak ditemukan)';

                continue;
            }

            try {
                $service->provisionCustomer($customer, $customer->servicePackage);
                $success++;
            } catch (Throwable $e) {
                $skipped[] = $customer->username.' ('.$e->getMessage().')';
            }
        }

        Notification::make()
            ->title("Provisioning selesai: {$success}/{$customers->count()} pelanggan berhasil")
            ->body($skipped ? 'Dilewati: '.implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' dan '.(count($skipped) - 5).' lainnya' : '') : null)
            ->status(empty($skipped) ? 'success' : 'warning')
            ->send();
    }
}
