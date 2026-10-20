<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\TelegramService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Setting bot Telegram — berlaku buat INSTALASI UTAMA maupun TENANT,
 * cuma beda TEMPAT SIMPAN: tenant disimpan di tabel pusat `tenants`
 * (webhook harus bisa cari tenant lewat URL SEBELUM tau database mana
 * yang harus dipakai), instalasi utama disimpan di tabel `Setting`
 * biasa (dengan isi `.env` sebagai nilai awal/cadangan kalau belum
 * pernah diisi lewat sini).
 */
class TelegramSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Bot Telegram';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Pengaturan Bot Telegram';

    protected string $view = 'filament.pages.telegram-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function isTenant(): bool
    {
        return app()->bound('currentTenant');
    }

    public function mount(): void
    {
        if ($this->isTenant()) {
            $tenant = app('currentTenant');

            $this->form->fill([
                'telegram_voucher_length' => (int) (Setting::get('telegram_voucher_length') ?: 8),
                'telegram_voucher_password_length' => (int) (Setting::get('telegram_voucher_password_length') ?: 6),
                'telegram_voucher_prefix' => Setting::get('telegram_voucher_prefix') ?: '',
                // Nilai bawaan di bawah ini SENGAJA sama dengan format yang
                // dulu dipatok di dalam kode bot (user_only + alnum_upper),
                // supaya instalasi yang sudah jalan tidak berubah bentuk
                // vouchernya begitu pembaruan ini dipasang.
                'telegram_voucher_login_method' => Setting::get('telegram_voucher_login_method') ?: \App\Enums\VoucherLoginMethod::UserOnly->value,
                'telegram_voucher_combination' => Setting::get('telegram_voucher_combination') ?: \App\Enums\VoucherCodeCombination::AlnumUpper->value,
                'telegram_admin_chat_id' => Setting::get('telegram_admin_chat_id'),
                'telegram_bot_token' => $tenant->telegram_bot_token,
                'telegram_bot_username' => $tenant->telegram_bot_username,
            ]);

            return;
        }

        $this->form->fill([
            'telegram_voucher_length' => (int) (Setting::get('telegram_voucher_length') ?: 8),
            'telegram_voucher_password_length' => (int) (Setting::get('telegram_voucher_password_length') ?: 6),
            'telegram_voucher_prefix' => Setting::get('telegram_voucher_prefix') ?: '',
            // Nilai bawaan di bawah ini SENGAJA sama dengan format yang
            // dulu dipatok di dalam kode bot (user_only + alnum_upper),
            // supaya instalasi yang sudah jalan tidak berubah bentuk
            // vouchernya begitu pembaruan ini dipasang.
            'telegram_voucher_login_method' => Setting::get('telegram_voucher_login_method') ?: \App\Enums\VoucherLoginMethod::UserOnly->value,
            'telegram_voucher_combination' => Setting::get('telegram_voucher_combination') ?: \App\Enums\VoucherCodeCombination::AlnumUpper->value,
            'telegram_admin_chat_id' => Setting::get('telegram_admin_chat_id'),
            'telegram_bot_token' => Setting::get('telegram_bot_token') ?: config('services.telegram.bot_token'),
            'telegram_bot_username' => Setting::get('telegram_bot_username') ?: config('services.telegram.bot_username'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $webhookUrl = $this->isTenant()
            ? url('/telegram/webhook/'.app('currentTenant')->subdomain)
            : url('/telegram/webhook');

        return $schema->components([
            Section::make('Bot Telegram')
                ->description("Isi token & username bot Telegram Anda (dari @BotFather). URL webhook otomatis didaftarkan saat Anda simpan: {$webhookUrl}")
                ->schema([
                    Forms\Components\TextInput::make('telegram_bot_token')
                        ->label('Bot Token')
                        ->placeholder('123456789:AAH...')
                        ->password()
                        ->revealable(),
                    Forms\Components\TextInput::make('telegram_bot_username')
                        ->label('Username Bot')
                        ->placeholder('@NamaBotAnda'),
                    Forms\Components\Textarea::make('telegram_admin_chat_id')
                        ->label('Chat ID Admin')
                        ->placeholder("mis.\n646525365\n712345678")
                        ->rows(3),
                ]),

            // Format voucher yang dibuat LEWAT BOT.
            //
            // Sebelumnya format ini dipatok di dalam kode (selalu
            // user_only + alnum_upper + tanpa prefix), jadi voucher hasil
            // bot bisa berbeda bentuk dari voucher hasil aplikasi tanpa
            // ada cara mengubahnya. Pilihannya sengaja dibuat PERSIS sama
            // dengan bagian "Format Voucher" di form Generate Voucher --
            // sumbernya enum yang sama, bukan daftar yang diketik ulang,
            // supaya kalau enumnya bertambah, halaman ini ikut tanpa
            // perlu diubah.
            Section::make('Format Voucher dari Bot')
                ->description('Bentuk kode voucher yang dibuat lewat bot Telegram. Tidak memengaruhi voucher yang dibuat lewat aplikasi.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('telegram_voucher_login_method')
                        ->label('Metode Login')
                        ->options(\App\Enums\VoucherLoginMethod::options())
                        ->default(\App\Enums\VoucherLoginMethod::UserOnly->value)
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('telegram_voucher_combination')
                        ->label('Kombinasi Kode')
                        ->options(\App\Enums\VoucherCodeCombination::options())
                        ->default(\App\Enums\VoucherCodeCombination::AlnumUpper->value)
                        ->required(),
                    Forms\Components\TextInput::make('telegram_voucher_prefix')
                        ->label('Prefix')
                        ->placeholder('mis. TYA')
                        ->maxLength(10),
                    Forms\Components\TextInput::make('telegram_voucher_length')
                        ->label('Panjang Kode Voucher')
                        ->numeric()
                        ->minValue(4)
                        ->maxValue(20)
                        ->default(8)
                        ->required(),
                    // Panjang password cuma berarti kalau username dan
                    // password DIBUAT BERBEDA. Untuk "Username = Password"
                    // kolom ini tidak dipakai sama sekali, jadi
                    // disembunyikan -- meninggalkannya tampil cuma bikin
                    // user mengira sudah mengatur sesuatu yang sebenarnya
                    // diabaikan.
                    Forms\Components\TextInput::make('telegram_voucher_password_length')
                        ->label('Panjang Password')
                        ->numeric()
                        ->minValue(4)
                        ->maxValue(20)
                        ->default(6)
                        ->required()
                        ->visible(fn (callable $get) => $get('telegram_voucher_login_method') === \App\Enums\VoucherLoginMethod::UserPass->value),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        Setting::set('telegram_voucher_length', (string) $state['telegram_voucher_length'], 'telegram');
        Setting::set('telegram_voucher_prefix', (string) ($state['telegram_voucher_prefix'] ?? ''), 'telegram');
        Setting::set('telegram_voucher_login_method', (string) $state['telegram_voucher_login_method'], 'telegram');
        Setting::set('telegram_voucher_combination', (string) $state['telegram_voucher_combination'], 'telegram');
        // Kolom panjang password DISEMBUNYIKAN saat metode loginnya
        // "Username = Password", dan field tersembunyi tidak ikut di
        // $state -- jadi nilainya harus dijaga pakai ?? , kalau tidak
        // setelan lama pengguna akan tertimpa string kosong tiap kali
        // halaman ini disimpan dengan metode user_only.
        Setting::set('telegram_voucher_password_length', (string) ($state['telegram_voucher_password_length'] ?? (Setting::get('telegram_voucher_password_length') ?: 6)), 'telegram');

        Setting::set('telegram_admin_chat_id', (string) ($state['telegram_admin_chat_id'] ?? ''), 'telegram');
        if ($this->isTenant()) {
            app('currentTenant')->update([
                'telegram_bot_token' => $state['telegram_bot_token'],
                'telegram_bot_username' => $state['telegram_bot_username'],
            ]);
        } else {
            Setting::set('telegram_bot_token', (string) $state['telegram_bot_token'], 'telegram');
            Setting::set('telegram_bot_username', (string) $state['telegram_bot_username'], 'telegram');
        }

        if ($state['telegram_bot_token']) {
            $webhookUrl = $this->isTenant()
                ? url('/telegram/webhook/'.app('currentTenant')->subdomain)
                : url('/telegram/webhook');

            $result = (new TelegramService($state['telegram_bot_token']))->setWebhook($webhookUrl);

            if ($result['ok'] ?? false) {
                Notification::make()->title('Tersimpan & webhook bot berhasil didaftarkan')->success()->send();
            } else {
                Notification::make()
                    ->title('Tersimpan, tapi gagal daftarkan webhook')
                    ->body($result['description'] ?? 'Cek lagi token bot-nya sudah benar.')
                    ->warning()
                    ->send();
            }
        } else {
            Notification::make()->title('Pengaturan disimpan')->success()->send();
        }
    }
}
