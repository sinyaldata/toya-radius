<?php

namespace App\Models;

use App\Enums\RoleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'avatar_path',
        'password',
        'role',
        'outlet_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => RoleType::class,
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === RoleType::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === RoleType::Admin;
    }

    public function isOutlet(): bool
    {
        return $this->role === RoleType::Outlet;
    }

    public function isTeknisi(): bool
    {
        return $this->role === RoleType::Teknisi;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * Foto profil yang dipakai avatar di topbar & menu user.
     *
     * PENTING: kolom avatar_path sudah lama ada dan foto yang diunggah
     * di halaman Profil memang tersimpan, TAPI Filament tidak akan
     * pernah membacanya kalau model User tidak mengimplementasikan
     * kontrak HasAvatar ini. Tanpa method ini Filament selalu jatuh ke
     * avatar inisial (mis. "ZH") -- persis seperti foto tidak pernah
     * berganti, padahal filenya sudah masuk. Jadi JANGAN dihapus.
     *
     * Mengembalikan null saat kolomnya kosong atau filenya tidak ada
     * di disk, supaya Filament kembali ke avatar inisial dan bukan
     * menampilkan gambar rusak.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        $path = $this->avatar_path;

        if (blank($path)) {
            return null;
        }

        // Kalau yang tersimpan sudah berupa URL penuh (mis. hasil impor
        // lama), pakai apa adanya.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->url($path);
    }
}
