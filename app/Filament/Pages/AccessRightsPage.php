<?php

namespace App\Filament\Pages;

use App\Models\AccessPermission;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Halaman untuk atur grup menu apa saja yang boleh diakses tiap jenis
 * login: User (staff/Admin — selalu akses penuh, tidak dibatasi di sini),
 * Reseller, Outlet, Biller.
 *
 * PENTING: halaman ini baru mengatur DATA-nya (disimpan ke tabel
 * access_permissions). Penerapannya (menyembunyikan menu yang tidak
 * diizinkan) lewat AccessPermission::userCanAccessGroup(), yang perlu
 * dipanggil dari tempat yang tepat (mis. render hook navigasi) — kalau
 * belum aktif otomatis, kabari saya untuk sambungkan.
 */
class AccessRightsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Hak Akses';

    // Halaman ini KHUSUS pemilik/admin — akun outlet tidak boleh melihat.
    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Hak Akses';

    protected string $view = 'filament.pages.access-rights-page';

    public array $reseller = [];

    public array $outlet = [];

    public array $biller = [];

    public function mount(): void
    {
        foreach (['reseller', 'outlet', 'biller'] as $roleKey) {
            $permission = AccessPermission::where('role_key', $roleKey)->first();
            $this->{$roleKey} = $permission?->allowed_groups ?? AccessPermission::ALL_GROUPS;
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            CheckboxList::make('reseller')
                ->label('Reseller boleh akses')
                ->options(array_combine(AccessPermission::ALL_GROUPS, AccessPermission::ALL_GROUPS))
                ->columns(3),

            CheckboxList::make('outlet')
                ->label('Outlet boleh akses')
                ->options(array_combine(AccessPermission::ALL_GROUPS, AccessPermission::ALL_GROUPS))
                ->columns(3),

            CheckboxList::make('biller')
                ->label('Biller boleh akses')
                ->options(array_combine(AccessPermission::ALL_GROUPS, AccessPermission::ALL_GROUPS))
                ->columns(3),
        ]);
    }

    public function getSubheading(): string
    {
        return 'Atur grup menu yang boleh dilihat masing-masing jenis login. "User" (staff/Admin) selalu akses penuh, tidak dibatasi di sini.';
    }

    public function save(): void
    {
        foreach (['reseller', 'outlet', 'biller'] as $roleKey) {
            AccessPermission::updateOrCreate(
                ['role_key' => $roleKey],
                ['allowed_groups' => $this->{$roleKey}]
            );
        }

        Notification::make()->title('Hak akses berhasil disimpan')->success()->send();
    }
}
