<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Scopes\OutletScope;
use App\Support\RadiusUsername;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_number',
        'name',
        'address',
        'phone',
        'type',
        'username',
        'password',
        'service_package_id',
        'outlet_id',
        // FTTH — lihat migration add_ftth_to_customers_table.
        'odp_id',
        'odp_port',
        'latitude',
        'longitude',
        'acs_device_id',
        'status',
        'billing_day',
        'credit_balance',
        'static_ip',
        'mac_address',
        'installation_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'installation_date' => 'date',
            'credit_balance' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OutletScope);

        static::creating(function (self $customer) {
            if (empty($customer->customer_number)) {
                $customer->customer_number = 'CUST-'.str_pad(
                    (string) (static::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->max('id') + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }
        });

        // Hapus pelanggan (dari mana pun — tombol satu-satu, bulk delete,
        // atau cara lain) HARUS ikut hapus SEMUA data terkaitnya —
        // RADIUS (radcheck/radreply/radusergroup, biar akun tidak bisa
        // dipakai login lagi) DAN riwayat invoice/pembayaran/komisi/log
        // sesi (keputusan: hapus pelanggan = bersih total, bukan cuma
        // sembunyi — kalau tidak, foreign key constraint di database
        // MENOLAK penghapusan pelanggan yang masih punya riwayat ini).
        static::deleting(function (self $customer) {
            app(\App\Services\CustomerRadiusService::class)->deprovisionCustomer($customer);

            \Illuminate\Support\Facades\DB::table('biller_customer_commissions')->where('customer_id', $customer->id)->delete();
            \App\Models\PaymentLog::where('customer_id', $customer->id)->delete();
            \App\Models\RadiusSessionLog::where('customer_id', $customer->id)->delete();
            \App\Models\Invoice::where('customer_id', $customer->id)->delete();
        });
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Biller yang ditugaskan menagih pelanggan ini LANGSUNG (pivot
     * `biller_customer_commissions`, sisi kebalikan dari
     * Outlet::billedCustomers()). Dipakai kolom "Biller" di tabel
     * pelanggan -- lihat App\Support\BillerInfo.
     *
     * DIKEMBALIKAN (2026-09-22): sempat KETIMPA TAK SENGAJA oleh
     * perbaikan IP Address (radius_username/latestSession) karena
     * dua perbaikan itu dibangun dari salinan Customer.php yang beda
     * turn, dan yang belakangan (IP Address) tidak tahu yang duluan
     * (kolom Biller) sudah menambah relasi ini -- makanya sempat
     * muncul error "Call to undefined relationship [billers]".
     */
    public function billers(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'biller_customer_commissions', 'customer_id', 'biller_id')
            ->withPivot('commission_amount')
            ->withTimestamps();
    }

    /**
     * BARU (permintaan user: "kebanyakan input lewat telegram" -- jadi
     * biller_id di invoice harus keisi OTOMATIS, tidak realistis minta
     * kasir pilih ulang manual tiap kali bayar). Cari Biller yang
     * BERTANGGUNG JAWAB menagih pelanggan ini, TANPA perlu ditanya
     * ulang setiap kali ada pembayaran -- penugasannya sendiri SUDAH
     * ada dari sebelumnya (lewat menu Biller > "Kelola Pelanggan &
     * Komisi" di aplikasi, atau lewat bot pas pelanggan ini pertama
     * dibuat), cuma belum pernah dipakai buat isi biller_id di
     * Invoice otomatis.
     *
     * Urutan pencarian: (1) penugasan LANGSUNG ke pelanggan ini
     * (billedCustomers, lebih spesifik/diutamakan), (2) kalau tidak
     * ada, penugasan ke OUTLET pelanggan ini secara keseluruhan
     * (billedOutlets). Balik null kalau memang tidak ada Biller yang
     * ditugaskan sama sekali -- pembayaran tetap bisa dicatat tanpa
     * biller (biller_id NULL), itu wajar untuk pelanggan yang memang
     * tidak ditagih lewat biller manapun.
     */
    public function resolveBillerId(): ?int
    {
        $billerLangsung = Outlet::where('partner_type', \App\Enums\PartnerType::Biller)
            ->whereHas('billedCustomers', fn ($q) => $q->where('customers.id', $this->id))
            ->value('id');

        if ($billerLangsung) {
            return $billerLangsung;
        }

        if (! $this->outlet_id) {
            return null;
        }

        return Outlet::where('partner_type', \App\Enums\PartnerType::Biller)
            ->whereHas('billedOutlets', fn ($q) => $q->where('outlets.id', $this->outlet_id))
            ->value('id');
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    /**
     * Titik pelanggan di peta.
     *
     * HANYA memakai koordinat pelanggan itu sendiri. Sebelumnya sempat
     * jatuh ke koordinat ODP kalau kosong, tapi itu dibatalkan
     * (permintaan user): semua pelanggan satu ODP jadi menumpuk persis
     * di satu titik, sehingga peta tidak bisa dipakai untuk hal yang
     * paling dibutuhkan di lapangan -- mencari rumah siapa yang mana.
     * Lebih baik pelanggan belum muncul di peta daripada muncul di
     * tempat yang salah.
     *
     * Mengembalikan null kalau koordinatnya belum diisi -- pemanggil
     * WAJIB memeriksa, jangan diasumsikan selalu ada.
     */
    public function titikPeta(): ?array
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return [
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentLogs(): HasMany
    {
        return $this->hasMany(PaymentLog::class);
    }

    /**
     * Baris-baris milik pelanggan ini di tabel RADIUS.
     *
     * Tabel-tabel ini ada di koneksi 'central' (dipakai bersama semua
     * tenant), sedangkan Customer ada di koneksi tenant. Eloquent tetap
     * bisa menghubungkannya karena masing-masing model menyatakan
     * koneksinya sendiri — yang penting kuncinya (customer_id) memang
     * ada di sana.
     */
    public function radcheck(): HasMany
    {
        return $this->hasMany(Radcheck::class, 'customer_id');
    }

    public function radreply(): HasMany
    {
        return $this->hasMany(Radreply::class, 'customer_id');
    }

    public function radusergroup(): HasMany
    {
        return $this->hasMany(Radusergroup::class, 'customer_id');
    }

    /**
     * Sesi RADIUS terakhir (tabel radacct — dicocokkan lewat username,
     * BUKAN customer_id, karena radacct tabel standar FreeRADIUS tidak
     * punya kolom itu). Dipakai untuk menampilkan IP Address SEBENARNYA
     * yang dipakai pelanggan — beda dari kolom static_ip di tabel
     * customers, yang cuma "permintaan" IP (dan untuk PPPoE di router
     * ini terbukti tidak selalu dituruti MikroTik — lihat catatan di
     * CustomerRadiusService::provisionCustomer()).
     */
    /**
     * Username SEBENARNYA yang tercatat di radacct/radcheck/radreply pusat
     * (lihat App\Support\RadiusUsername) -- untuk instalasi utama sama
     * persis dengan `username`, tapi untuk PELANGGAN TENANT diberi awalan
     * subdomain tenant (mis. "goodnet_dira"), karena tabel RADIUS dipakai
     * bersama semua tenant dan harus unik lintas tenant.
     *
     * PENTING: dipakai sebagai $localKey relasi latestSession() di bawah,
     * BUKAN cuma properti biasa -- Eloquent memanggil getAttribute() untuk
     * $localKey (baik saat diakses satu-satu maupun lewat eager load
     * ->with('latestSession')), dan getAttribute() otomatis lewat accessor
     * ini duluan. Jadi TIDAK BOLEH diganti nama tanpa mengganti juga
     * `'radius_username'` di latestSession().
     *
     * DIJAGA terhadap username KOSONG/NULL (2026-09-22): ternyata ada
     * baris pelanggan di database yang kolom username-nya NULL -- walau
     * migrasi mendefinisikan kolom itu wajib diisi, pada praktiknya ada
     * data yang lolos tanpa username (baris begini memang tidak bisa
     * dipakai login PPPoE, tapi TETAP harus bisa ditampilkan di tabel
     * tanpa membuat SELURUH HALAMAN error 500). Dibungkus (string) supaya
     * null jadi '' -- RadiusUsername::forRadius() mensyaratkan string,
     * dan '' dijamin tidak pernah cocok dengan baris radacct mana pun,
     * jadi latestSession() di bawah aman-aman saja kembali null (kolom
     * IP Address-nya tampil "-", bukan bikin seluruh tabel gagal dimuat).
     */
    public function getRadiusUsernameAttribute(): string
    {
        return RadiusUsername::forRadius((string) $this->username);
    }

    /**
     * Sesi RADIUS terakhir pelanggan ini (radacct, tabel PUSAT bersama
     * semua tenant -- lihat Radacct::$connection).
     *
     * KOREKSI (2026-09-22): dulu dicocokkan lewat `username` = `username`
     * apa adanya. Untuk pelanggan TENANT itu TIDAK PERNAH nyambung --
     * customers.username tersimpan TANPA awalan tenant (mis. "dira"),
     * sedangkan radacct.username tercatat DENGAN awalan (mis.
     * "goodnet_dira"). Akibatnya kolom IP Address di tabel User PPPoE
     * selalu kosong untuk SEMUA pelanggan tenant, walau pelanggannya
     * benar-benar online (kelihatan online di MikroTik/WinBox, dan status
     * Online/Offline aplikasi ini pun benar -- itu dihitung terpisah lewat
     * App\Support\OnlineStatus yang sudah menangani awalan ini dengan
     * benar). Sekarang dicocokkan lewat radius_username (accessor di
     * atas), yang otomatis memberi awalan yang tepat.
     */
    public function latestSession(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Radacct::class, 'username', 'radius_username')
            ->latestOfMany('acctstarttime');
    }

    public function unpaidInvoices(): HasMany
    {
        return $this->invoices()->whereIn('status', ['unpaid', 'overdue']);
    }
}
