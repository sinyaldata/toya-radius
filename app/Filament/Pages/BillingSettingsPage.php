<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Tagihan Otomatis';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Pengaturan Tagihan Otomatis';

    protected string $view = 'filament.pages.billing-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'billing_auto_generate' => Setting::get('billing_auto_generate', '1') === '1',
            'billing_generate_day' => (int) Setting::get('billing_generate_day', 1),
            'billing_auto_isolir' => Setting::get('billing_auto_isolir', '1') === '1',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Generate Invoice Otomatis')
                ->description('Berlaku untuk pelanggan Hotspot tetap & PPPoE yang masih aktif. Sistem mengecek jadwal ini setiap hari jam 01:00.')
                ->schema([
                    Forms\Components\Toggle::make('billing_auto_generate')
                        ->label('Aktifkan Generate Invoice Otomatis')
                        ->default(true),
                    Forms\Components\TextInput::make('billing_generate_day')
                        ->label('Tanggal Generate Setiap Bulan')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->default(1)
                        ->required(),
                ]),
            Section::make('Isolir Otomatis Saat Jatuh Tempo')
                ->description('Sistem mengecek ini setiap hari lewat perintah invoices:cek-overdue.')
                ->schema([
                    Forms\Components\Toggle::make('billing_auto_isolir')
                        ->label('Aktifkan Isolir Otomatis')
                        ->default(true),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Setting::set('billing_auto_generate', $state['billing_auto_generate'] ? '1' : '0', 'billing');
        Setting::set('billing_generate_day', (string) $state['billing_generate_day'], 'billing');
        Setting::set('billing_auto_isolir', $state['billing_auto_isolir'] ? '1' : '0', 'billing');

        Notification::make()->title('Pengaturan billing disimpan')->success()->send();
    }
}
