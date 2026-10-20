<?php

namespace App\Filament\Pages;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Scopes\OutletScope;
use App\Services\CustomerRadiusService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Halaman Isolir — semua pelanggan yang aksesnya SEDANG DITUTUP, baik
 * yang otomatis kena isolir karena tunggakan maupun yang di-suspend
 * manual oleh admin (keputusan user: dua-duanya tampil, dengan penanda
 * yang membedakan).
 *
 * Kenapa dijadikan satu halaman: dari sisi pelanggan, keduanya sama
 * saja — internetnya mati. Yang berbeda cuma SEBABNYA, dan itu yang
 * menentukan tindakannya (tagih vs. buka blokir). Memisahkan jadi dua
 * halaman malah membuat orang harus memeriksa dua tempat untuk
 * menjawab satu pertanyaan: "siapa saja yang sedang mati?"
 *
 * TAMBAHAN (permintaan user): tab/saringan "Menunggak (Belum Diisolir)"
 * -- pelanggan yang statusnya MASIH Aktif tapi punya tagihan yang
 * sudah lewat jatuh tempo. Ini kebalikan dari isolir OTOMATIS (yang
 * jalan sendiri lewat cron kalau "Isolir Otomatis" di Pengaturan >
 * Tagihan Otomatis dinyalakan) -- kalau kebijakannya dimatikan (atau
 * pemilik memang mau menagih dulu / memberi toleransi sebelum
 * memutus), tunggakan tidak pernah otomatis berujung isolir. Bagian
 * ini memberi pemilik daftar siapa saja yang layak diisolir, lalu
 * tombol "Isolir Sekarang" (isolirManual()) untuk memutuskannya
 * SATU-SATU secara manual, kapan pun dia mau.
 */
class IsolirPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    // Urutan grup Pelanggan (permintaan user): User PPPoE=1, User Static
    // IP=2, User Member=3, Online=4, Offline=5, Isolir=6, lalu
    // Profile=7 (cluster) paling bawah.
    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Isolir';

    protected static ?string $title = 'Pelanggan Terisolir & Suspend';

    protected string $view = 'filament.pages.isolir-page';

    public string $cari = '';

    public string $saring = 'semua';

    public static function canAccess(): bool
    {
        return ! \Illuminate\Support\Facades\Auth::user()?->isOutlet();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Daftar pelanggan yang sedang ditutup aksesnya.
     *
     * OutletScope SENGAJA TIDAK dilepas di sini — halaman ini dipakai
     * staff, dan kalau suatu saat diberikan ke akun yang terikat outlet,
     * penyaringan per outlet harus tetap berlaku seperti halaman lain.
     */
    public function daftar()
    {
        $kueri = Customer::query()
            ->with(['servicePackage', 'outlet', 'odp'])
            ->when(trim($this->cari) !== '', function ($q) {
                $kata = trim($this->cari);
                $q->where(function ($w) use ($kata) {
                    $w->where('name', 'like', '%'.$kata.'%')
                        ->orWhere('username', 'like', '%'.$kata.'%')
                        ->orWhere('phone', 'like', '%'.$kata.'%');
                });
            })
            // Dijumlahkan sebagai DUA kolom terpisah lalu dikurangkan di
            // tampilan, BUKAN withSum(DB::raw('amount - amount_paid')).
            // withSum dirancang untuk nama kolom biasa; memberi ekspresi
            // mentah ke situ perilakunya berbeda antar versi Laravel dan
            // gampang diam-diam salah hitung. Dua kolom ini tetap satu
            // query, jadi tidak ada ruginya.
            ->withSum(['invoices as tagihan_total' => fn ($q) => $q->whereIn('status', ['unpaid', 'overdue'])], 'amount')
            ->withSum(['invoices as tagihan_dibayar' => fn ($q) => $q->whereIn('status', ['unpaid', 'overdue'])], 'amount_paid')
            ->orderBy('name')
            ->limit(300);

        if ($this->saring === 'nunggak') {
            // "Menunggak (Belum Diisolir)" -- MASIH Aktif, tapi punya
            // tagihan yang sudah lewat jatuh tempo. Beda dari daftar
            // isolir/suspend di bawah (yang aksesnya SUDAH ditutup),
            // ini justru yang BELUM ditutup -- daftar calon isolir
            // manual.
            return $kueri->where('status', CustomerStatus::Active)
                ->whereHas('invoices', fn ($q) => $q->whereIn('status', ['unpaid', 'overdue'])->where('due_date', '<', now()))
                ->get();
        }

        $status = match ($this->saring) {
            'isolir' => [CustomerStatus::Isolir],
            'suspend' => [CustomerStatus::Suspended],
            default => [CustomerStatus::Isolir, CustomerStatus::Suspended],
        };

        return $kueri->whereIn('status', $status)->get();
    }

    public function ringkasan(): array
    {
        $isolir = Customer::where('status', CustomerStatus::Isolir)->count();
        $suspend = Customer::where('status', CustomerStatus::Suspended)->count();
        $menunggak = Customer::where('status', CustomerStatus::Active)
            ->whereHas('invoices', fn ($q) => $q->whereIn('status', ['unpaid', 'overdue'])->where('due_date', '<', now()))
            ->count();

        return ['isolir' => $isolir, 'suspend' => $suspend, 'total' => $isolir + $suspend, 'menunggak' => $menunggak];
    }

    /**
     * Buka blokir satu pelanggan: status kembali Aktif DAN baris blokir
     * di RADIUS dihapus.
     *
     * Menulis ke RADIUS di sini WAJIB. Kalau cuma status di aplikasi yang
     * diubah, pelanggan tetap ditolak FreeRADIUS sementara aplikasi
     * menampilkan "Aktif" — persis "blokir nyasar" yang dulu terjadi dan
     * sangat sulit dilacak.
     */
    public function bukaBlokir(int $customerId): void
    {
        $pelanggan = Customer::find($customerId);

        if (! $pelanggan) {
            Notification::make()->title('Pelanggan tidak ditemukan')->danger()->send();

            return;
        }

        try {
            $pelanggan->update(['status' => CustomerStatus::Active]);
            app(CustomerRadiusService::class)->reactivate($pelanggan);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal membuka blokir')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Blokir dibuka')
            ->body($pelanggan->name.' sudah diaktifkan kembali. Minta pelanggan menyambung ulang.')
            ->success()
            ->send();
    }

    /**
     * Isolir SATU pelanggan secara MANUAL -- dipakai dari tab
     * "Menunggak (Belum Diisolir)", kebalikan dari isolir OTOMATIS yang
     * jalan sendiri lewat cron (lihat InvoiceService::markOverdueAndIsolir()
     * & Pengaturan > Tagihan Otomatis). Dipakai kalau kebijakan isolir
     * otomatis dimatikan, atau pemilik memang mau memutuskan sendiri
     * kapan seorang pelanggan diputus, bukan menunggu cron.
     *
     * Menulis status APLIKASI *dan* RADIUS sekaligus (sama seperti
     * bukaBlokir() di atas) -- supaya tidak terjadi "isolir nyasar":
     * status di aplikasi bilang Isolir tapi RADIUS masih menerima
     * login, atau sebaliknya.
     */
    public function isolirManual(int $customerId): void
    {
        $pelanggan = Customer::find($customerId);

        if (! $pelanggan) {
            Notification::make()->title('Pelanggan tidak ditemukan')->danger()->send();

            return;
        }

        if ($pelanggan->status !== CustomerStatus::Active) {
            Notification::make()
                ->title('Tidak jadi diisolir')
                ->body('Pelanggan ini statusnya sudah bukan Aktif (mungkin sudah ada yang memproses lebih dulu). Muat ulang halaman untuk lihat status terbaru.')
                ->warning()
                ->send();

            return;
        }

        try {
            $pelanggan->update(['status' => CustomerStatus::Isolir]);
            app(CustomerRadiusService::class)->suspend($pelanggan);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal mengisolir')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Pelanggan diisolir')
            ->body($pelanggan->name.' sekarang diisolir -- akses internetnya diputus lewat RADIUS.')
            ->success()
            ->send();
    }
}
