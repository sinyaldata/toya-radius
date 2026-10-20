<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Pengaturan profil milik pengguna yang sedang login — foto, password,
 * dan (khusus akun outlet) alamat & no. HP outletnya.
 *
 * Halaman ini SENGAJA terbuka untuk semua role: akun outlet butuh ini
 * karena "Pengaturan Perusahaan" disembunyikan dari mereka.
 */
class ProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Profil Saya';

    protected static ?string $title = 'Profil Saya';

    protected string $view = 'filament.pages.profile-page';

    public ?array $data = [];

    public function mount(): void
    {
        $user = Auth::user();
        $outlet = $user?->outlet;

        $this->form->fill([
            'name' => $user?->name,
            'email' => $user?->email,
            'avatar_path' => $user?->avatar_path,
            'address' => $outlet?->address,
            'phone' => $outlet?->phone,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isOutlet = Auth::user()?->isOutlet();

        return $schema->components([
            Forms\Components\FileUpload::make('avatar_path')->disk('public')->visibility('public')
                ->label('Foto Profil')
                ->image()
                ->avatar()
                ->directory('avatars')
                ->maxSize(2048)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(120),
            Forms\Components\TextInput::make('phone')
                ->label('No. HP')
                ->tel()
                ->maxLength(30)
                ->visible($isOutlet),
            Forms\Components\Textarea::make('address')
                ->label('Alamat')
                ->rows(3)
                ->visible($isOutlet)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('password')
                ->label('Password Baru')
                ->password()
                ->revealable()
                ->minLength(8)
                ->confirmed(),
            Forms\Components\TextInput::make('password_confirmation')
                ->label('Ulangi Password Baru')
                ->password()
                ->revealable(),
        ])->statePath('data')->columns(2);
    }

    public function simpan(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->avatar_path = $data['avatar_path'] ?: null;

        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        // Alamat & no. HP disimpan di data OUTLET-nya (bukan di user),
        // karena itu memang milik outlet, bukan milik akun login.
        if ($user->isOutlet() && $user->outlet) {
            $user->outlet->update([
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);
        }

        $this->form->fill([
            ...$data,
            'password' => null,
            'password_confirmation' => null,
        ]);

        Notification::make()->title('Profil berhasil disimpan')->success()->send();
    }
}
