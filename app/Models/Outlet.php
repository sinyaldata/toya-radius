<?php

namespace App\Models;

use App\Enums\PartnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outlet extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'partner_type',
        'area_id',
        'name',
        'owner_name',
        'phone',
        'address',
        'commission_percent',
        'commission_type',
        'saldo',
        'is_saldo_exempt',
        'commission_flat_amount',
        'is_active',
        'telegram_link_code',
        'telegram_chat_id',
        'telegram_blocked',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'commission_percent' => 'decimal:2',
            'commission_type' => \App\Enums\CommissionType::class,
            'saldo' => 'decimal:2',
            'commission_flat_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'telegram_blocked' => 'boolean',
            'is_saldo_exempt' => 'boolean',
        ];
    }

    /**
     * Hitung komisi outlet/reseller ini untuk SATU transaksi senilai
     * $nominalTransaksi -- SATU-SATUNYA tempat rumus komisi ditulis,
     * dipakai bersama oleh ReportService & ProfitLossReportPage supaya
     * dua laporan itu TIDAK PERNAH bisa memberi angka komisi yang beda
     * untuk transaksi yang sama.
     *
     * commission_type menentukan rumus mana yang dipakai:
     * - Percent (bawaan/lama, TIDAK BERUBAH): persen dari nominal
     *   transaksi itu sendiri -- transaksi besar, komisi ikut besar.
     * - Markup (permintaan user: "komisi selisih harga jual dgn
     *   harga beli voucher"): komisi = voucher->sale_price -
     *   voucher->cost_price -- DUA-DUANYA sudah tersimpan di tiap baris
     *   Voucher sendiri (kolom yang SUDAH ADA dari sebelumnya, bukan
     *   kolom baru), jadi TIDAK PERLU tabel harga terpisah -- harga
     *   jual per voucher itu sendiri yang "ditentukan semaunya
     *   pemilik" pas voucher itu dibuat/diberi harga. HANYA berlaku
     *   buat transaksi VOUCHER (Hotspot) -- transaksi Invoice PPPoE
     *   tidak punya konsep "voucher" sama sekali, jadi $voucher akan
     *   null buat transaksi jenis itu dan komisinya otomatis 0 (bukan
     *   error) kalau tipe Markup dipilih tapi transaksinya bukan
     *   voucher.
     *
     * CATATAN: sempat ada tipe ketiga "Flat" (nominal tetap per
     * transaksi) -- DIHAPUS lagi atas permintaan user 2026-09-19,
     * cuma beberapa saat setelah ditambahkan. Kolom database
     * commission_flat_amount TETAP DIBIARKAN ADA (tidak dihapus lewat
     * migrasi) -- aman, cuma sudah tidak dipakai di mana pun lagi.
     */
    public function hitungKomisi(float $nominalTransaksi, ?\App\Models\Voucher $voucher = null): float
    {
        return match ($this->commission_type) {
            \App\Enums\CommissionType::Markup => $voucher
                ? max(0, (float) $voucher->sale_price - (float) $voucher->cost_price)
                : 0.0,
            default => $nominalTransaksi * ((float) $this->commission_percent / 100),
        };
    }

    /**
     * Area/Wilayah tempat mitra ini berada — dipakai untuk menyaring
     * laporan per wilayah.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * Profile Hotspot (ServicePackage) yang BOLEH dipakai outlet/reseller
     * ini secara khusus -- kebalikan dari ServicePackage::outlets().
     * TABEL PIVOT SAMA (service_package_outlet), cuma diakses dari sisi
     * Outlet biar bisa diatur sekali klik lewat form Outlet/Reseller
     * (permintaan user: "setiap reseller punya kebijakan berbeda-beda"),
     * tidak perlu buka satu-satu tiap Profile Hotspot lagi.
     *
     * INGAT semantik pembatasannya (lihat ServicePackage::isAvailableForOutlet()):
     * kalau relasi outlets() suatu Profile Hotspot KOSONG, profile itu
     * otomatis tersedia untuk SEMUA outlet/reseller. Jadi kalau field ini
     * dikosongkan (tidak dicentang sama sekali) di form Outlet/Reseller
     * tertentu, itu BUKAN berarti "tidak boleh pakai apa-apa" -- artinya
     * outlet ini tetap dapat akses ke semua profile yang memang tidak
     * dibatasi ke outlet manapun (perilaku default lama tetap jalan).
     */
    public function servicePackages(): BelongsToMany
    {
        return $this->belongsToMany(ServicePackage::class, 'service_package_outlet');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Outlet-outlet yang ditagih oleh Biller ini, dengan nominal komisi
     * tetap (bukan persen) per outlet — diatur lewat aksi "Kelola Outlet
     * & Komisi" di BillerResource.
     */
    public function billedOutlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'biller_outlet_commissions', 'biller_id', 'outlet_id')
            ->withPivot('commission_amount')
            ->withTimestamps();
    }

    /**
     * Pelanggan (PPPoE maupun Hotspot — sama-sama tabel `customers`)
     * yang ditagih LANGSUNG oleh Biller ini, dengan komisi tetap per
     * pelanggan — diatur lewat aksi "Kelola Pelanggan & Komisi" di
     * BillerResource. Terpisah dari billedOutlets() di atas (itu
     * penugasan level outlet, ini level pelanggan perorangan).
     */
    public function billedCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'biller_customer_commissions', 'biller_id', 'customer_id')
            ->withPivot('commission_amount')
            ->withTimestamps();
    }

    /**
     * Biller-biller yang bertugas menagih outlet ini.
     */
    public function billers(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'biller_outlet_commissions', 'outlet_id', 'biller_id')
            ->withPivot('commission_amount')
            ->withTimestamps();
    }

    /**
     * KEBIJAKAN SALDO (berlaku hanya untuk generate voucher lewat bot
     * Telegram — generate lewat aplikasi TIDAK PERNAH memotong saldo
     * siapa pun, lihat GenerateVoucherBatchAction).
     *
     * Sejak kebijakan ini diubah: HANYA mitra bertipe Reseller yang
     * wajib punya saldo untuk generate voucher. Outlet (dan Biller,
     * yang memang tidak generate voucher sendiri) TIDAK PERNAH wajib
     * saldo — berlaku otomatis untuk semua Outlet, bukan cuma yang
     * ditandai "Bebas Saldo" secara manual.
     *
     * Toggle "Bebas Saldo" (is_saldo_exempt) tetap dipakai, tapi
     * sekarang cuma berlaku sebagai PENGECUALIAN per-Reseller (Reseller
     * tertentu yang mau dibebaskan dari saldo tetap bisa ditandai
     * manual). Untuk Outlet toggle itu tidak lagi berpengaruh apa pun,
     * karena Outlet memang sudah otomatis bebas saldo.
     */
    public function requiresSaldo(): bool
    {
        return $this->partner_type === PartnerType::Reseller && ! $this->is_saldo_exempt;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $outlet) {
            if (empty($outlet->code)) {
                $prefix = match ($outlet->partner_type ?? PartnerType::Outlet) {
                    PartnerType::Reseller => 'RSL',
                    PartnerType::Biller => 'BIL',
                    default => 'OUT',
                };
                $outlet->code = $prefix.'-'.str_pad((string) (static::max('id') + 1), 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
