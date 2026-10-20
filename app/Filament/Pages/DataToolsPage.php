<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Voucher;
use App\Services\BackupService;
use App\Services\CsvService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DataToolsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Backup & Data';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Backup Database & Export/Import Data';

    protected string $view = 'filament.pages.data-tools-page';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backup')
                ->label('Backup Database Sekarang')
                ->icon('lucide-download')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Membuat file backup penuh database (.sql) dan langsung mendownloadnya.')
                ->action(function (BackupService $backup) {
                    try {
                        $path = $backup->createDatabaseBackup();

                        return response()->download($path)->deleteFileAfterSend(true);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Backup gagal')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\ActionGroup::make([
                Actions\Action::make('export_voucher')
                    ->label('Export Voucher (CSV)')
                    ->icon('lucide-download')
                    ->action(fn (CsvService $csv) => $csv->exportVouchers(Voucher::query(), 'voucher-'.now()->format('Ymd-His').'.csv')),
                Actions\Action::make('import_voucher')
                    ->label('Import Voucher (CSV)')
                    ->icon('lucide-upload')
                    ->modalDescription('Kolom wajib: username, password, profile. Kolom opsional: sale_price, status.')
                    ->schema([
                        Forms\Components\FileUpload::make('file')->label('Berkas CSV')->storeFiles(false)->required(),
                    ])
                    ->action(function (array $data, CsvService $csv) {
                        $this->notifyImportResult($csv->importVouchers($data['file']->getRealPath()));
                    }),
            ])->label('Voucher')->icon('lucide-ticket')->color('gray'),

            Actions\ActionGroup::make([
                Actions\Action::make('export_outlet')
                    ->label('Export Outlet (CSV)')
                    ->icon('lucide-download')
                    ->action(fn (CsvService $csv) => $csv->exportOutlets(Outlet::query(), 'outlet-'.now()->format('Ymd-His').'.csv')),
                Actions\Action::make('import_outlet')
                    ->label('Import Outlet (CSV)')
                    ->icon('lucide-upload')
                    ->modalDescription('Kolom wajib: name, owner_name. Kolom opsional: phone, address, commission_percent, is_active.')
                    ->schema([
                        Forms\Components\FileUpload::make('file')->label('Berkas CSV')->storeFiles(false)->required(),
                    ])
                    ->action(function (array $data, CsvService $csv) {
                        $this->notifyImportResult($csv->importOutlets($data['file']->getRealPath()));
                    }),
            ])->label('Outlet / Reseller')->icon('lucide-store')->color('gray'),

            Actions\ActionGroup::make([
                Actions\Action::make('export_pppoe')
                    ->label('Export PPPoE (CSV)')
                    ->icon('lucide-download')
                    ->action(fn (CsvService $csv) => $csv->exportCustomers(
                        Customer::query()->where('type', CustomerType::Pppoe),
                        'pppoe-'.now()->format('Ymd-His').'.csv'
                    )),
                Actions\Action::make('import_pppoe')
                    ->label('Import PPPoE (CSV)')
                    ->icon('lucide-upload')
                    ->modalDescription('Kolom wajib: name, username, password, service_package. Kolom opsional: phone, address, billing_day, status.')
                    ->schema([
                        Forms\Components\FileUpload::make('file')->label('Berkas CSV')->storeFiles(false)->required(),
                    ])
                    ->action(function (array $data, CsvService $csv) {
                        $this->notifyImportResult($csv->importCustomers($data['file']->getRealPath(), CustomerType::Pppoe));
                    }),
            ])->label('Pelanggan PPPoE')->icon('lucide-radio')->color('gray'),
        ];
    }

    protected function notifyImportResult(array $result): void
    {
        Notification::make()
            ->title("{$result['imported']} data diimport, {$result['skipped']} dilewati")
            ->body(empty($result['errors']) ? null : implode("\n", array_slice($result['errors'], 0, 5)))
            ->status($result['imported'] > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }
}
