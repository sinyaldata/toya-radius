<?php

namespace App\Filament\Pages;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Odp;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Peta Pelanggan — sebaran pelanggan & ODP di atas peta.
 *
 * Peta digambar dengan Leaflet + ubin OpenStreetMap (gratis, tanpa API
 * key). Ini berarti BROWSER yang membuka halaman ini harus punya akses
 * internet; servernya sendiri tidak perlu.
 *
 * Titik pelanggan MURNI dari koordinat pelanggan itu sendiri, tidak
 * lagi menumpang koordinat ODP (lihat Customer::titikPeta()). Supaya
 * pengisian koordinatnya tidak berubah jadi pekerjaan mengetik angka
 * satu per satu, halaman ini menyediakan cara memasang titik langsung:
 * pilih pelanggan yang belum bertitik, lalu klik posisinya di peta.
 */
class CustomerMapPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'FTTH';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Peta';

    protected static ?string $title = 'Peta';

    protected string $view = 'filament.pages.customer-map-page';

    /** Pelanggan yang sedang dipilih untuk dipasangi titik. */
    public ?int $pasangUntuk = null;

    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected ?array $cacheTitik = null;

    /**
     * Titik pelanggan yang sudah punya koordinat.
     */
    public function titikPelanggan(): array
    {
        // Disimpan sekali per request: ringkasan() juga memakainya, dan
        // tanpa ini seluruh query pelanggan berjalan dua kali hanya untuk
        // menghitung ulang angka yang sama.
        if ($this->cacheTitik !== null) {
            return $this->cacheTitik;
        }

        $rows = Customer::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['odp:id,name', 'servicePackage:id,name'])
            ->get(['id', 'name', 'username', 'phone', 'address', 'status', 'type',
                'service_package_id', 'odp_id', 'latitude', 'longitude']);

        return $this->cacheTitik = $rows->map(fn (Customer $c) => [
            'id' => $c->id,
            'nama' => $c->name,
            'username' => $c->username,
            'telepon' => $c->phone,
            'alamat' => $c->address,
            'paket' => $c->servicePackage?->name,
            'odp' => $c->odp?->name,
            'status' => $c->status?->value,
            'statusLabel' => $c->status?->label(),
            'lat' => (float) $c->latitude,
            'lng' => (float) $c->longitude,
        ])->all();
    }

    /**
     * Pelanggan yang BELUM punya koordinat — inilah daftar kerja untuk
     * memasang titik. Dibatasi 200 supaya halaman tidak berat; sisanya
     * muncul sendiri setelah yang di atas selesai dipasang.
     */
    public function belumBertitik()
    {
        return Customer::query()
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'username', 'address']);
    }

    public function titikOdp(): array
    {
        return Odp::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withCount('customers')
            ->get()
            ->map(fn (Odp $o) => [
                'nama' => $o->name,
                'lat' => (float) $o->latitude,
                'lng' => (float) $o->longitude,
                'terpakai' => $o->portTerpakai(),
                'total' => (int) $o->total_ports,
                'sisa' => $o->sisaPort(),
                'alamat' => $o->address,
            ])->all();
    }

    public function ringkasan(): array
    {
        $totalPelanggan = Customer::count();
        $adaTitik = count($this->titikPelanggan());

        return [
            'total' => $totalPelanggan,
            'bertitik' => $adaTitik,
            'tanpaTitik' => max(0, $totalPelanggan - $adaTitik),
            'odp' => Odp::whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'isolir' => Customer::whereIn('status', [CustomerStatus::Isolir, CustomerStatus::Suspended])->count(),
        ];
    }

    public function pilihUntukDipasang($customerId): void
    {
        $this->pasangUntuk = $customerId ? (int) $customerId : null;
    }

    /**
     * Simpan koordinat hasil klik di peta.
     *
     * Nilai dari JavaScript SELALU diperiksa ulang di sini: lintang
     * -90..90 dan bujur -180..180. Koordinat di luar rentang itu bukan
     * sekadar salah tampil — kolomnya decimal(10,7), dan angka yang
     * melampaui presisi akan ditolak database dengan galat yang
     * membingungkan, atau lebih buruk, dipotong diam-diam.
     */
    public function simpanTitik($customerId, $lat, $lng): void
    {
        $pelanggan = Customer::find((int) $customerId);

        if (! $pelanggan) {
            Notification::make()->title('Pelanggan tidak ditemukan')->danger()->send();

            return;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Notification::make()->title('Koordinat di luar rentang yang wajar')->danger()->send();

            return;
        }

        $pelanggan->update(['latitude' => $lat, 'longitude' => $lng]);

        // Kosongkan pilihan supaya klik berikutnya di peta tidak
        // diam-diam memindahkan pelanggan yang barusan dipasang.
        $this->pasangUntuk = null;
        $this->cacheTitik = null;

        Notification::make()
            ->title('Titik tersimpan')
            ->body($pelanggan->name.' sudah muncul di peta.')
            ->success()
            ->send();
    }

    /** Hapus titik satu pelanggan (kalau salah pasang). */
    public function hapusTitik($customerId): void
    {
        $pelanggan = Customer::find((int) $customerId);

        if (! $pelanggan) {
            return;
        }

        $pelanggan->update(['latitude' => null, 'longitude' => null]);
        $this->cacheTitik = null;

        Notification::make()
            ->title('Titik dihapus')
            ->body($pelanggan->name.' kembali ke daftar belum bertitik.')
            ->success()
            ->send();
    }
}
