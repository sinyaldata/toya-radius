<?php

namespace App\Filament\Pages;

use App\Services\TenantDataResetService;
use App\Support\RadiusUsername;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantDataResetPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Reset Data Percobaan';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Reset Data Percobaan';

    protected string $view = 'filament.pages.tenant-data-reset-page';

    public ?array $data = [];

    /**
     * SENGAJA hanya SuperAdmin, DAN sengaja TIDAK tersedia sama sekali
     * di instalasi utama (tanpa awalan tenant) — terlalu berisiko salah
     * hapus data tenant lain di tabel RADIUS pusat kalau instalasi
     * utama tidak punya cara aman membedakan "punya siapa" tanpa awalan.
     */
    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && RadiusUsername::currentPrefix() !== null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ketik Ulang untuk Konfirmasi')
                ->description('Ketik persis "HAPUS DATA PERCOBAAN" (tanpa tanda kutip) di bawah ini untuk mengaktifkan tombol reset.')
                ->schema([
                    Forms\Components\TextInput::make('confirmation')
                        ->label('Konfirmasi')
                        ->placeholder('HAPUS DATA PERCOBAAN')
                        ->required(),
                ]),
        ])->statePath('data');
    }

    public function resetData(TenantDataResetService $service): void
    {
        $state = $this->form->getState();

        if (trim((string) ($state['confirmation'] ?? '')) !== 'HAPUS DATA PERCOBAAN') {
            Notification::make()
                ->title('Teks konfirmasi tidak cocok')
                ->body('Ketik persis "HAPUS DATA PERCOBAAN" untuk melanjutkan.')
                ->danger()
                ->send();

            return;
        }

        $counts = $service->reset();
        $total = array_sum($counts);

        $this->form->fill();

        Notification::make()
            ->title('Data percobaan berhasil dihapus')
            ->body("{$total} baris data dihapus. Aplikasi siap dipakai dari nol.")
            ->success()
            ->duration(8000)
            ->send();
    }
}
