<?php

namespace App\Imports;

use App\Models\Outlet;
use App\Models\ServicePackage;
use App\Models\Voucher;
use App\Services\FreeRadiusService;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import Voucher — dicocokkan lewat "Username". Kolom "Profile" dan
 * "Outlet" dicocokkan lewat NAMA (bukan ID), supaya file Excel-nya bisa
 * dibaca manusia. Kalau nama Profile/Outlet tidak ditemukan, baris
 * dilewati (tidak bikin data setengah jadi).
 *
 * PERBAIKAN: sebelumnya Profile dicocokkan ke tabel LAMA
 * (voucher_profiles) yang sudah tidak dipakai lagi sejak "Produk
 * Voucher" diganti — profile voucher yang sebenarnya sekarang ada di
 * ServicePackage (service_packages), sama seperti yang dipakai di menu
 * Hotspot > Profile. Kemungkinan besar SEMUA baris gagal cocok
 * sebelumnya walau nama Profile-nya sudah benar.
 *
 * BARU: bisa juga pilih Outlet & Profile SEKALI lewat dropdown di
 * halaman Export/Import (dipakai kalau file Excel-nya TIDAK punya
 * kolom Outlet/Profile per baris, atau mau override kolom itu buat
 * SEMUA baris sekaligus). Kalau baris itu SENDIRI juga punya kolom
 * Outlet/Profile, kolom di baris itu yang menang (lebih spesifik).
 */
class VoucherImport implements ToModel, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(
        protected ?int $defaultOutletId = null,
        protected ?int $defaultProfileId = null,
    ) {}

    public function model(array $row)
    {
        if (empty($row['username'])) {
            return null;
        }

        $profile = ! empty($row['profile'])
            ? ServicePackage::where('name', $row['profile'])->whereIn('type', ['hotspot', 'both'])->first()
            : ($this->defaultProfileId ? ServicePackage::find($this->defaultProfileId) : null);

        if (! $profile) {
            return null;
        }

        $outlet = ! empty($row['outlet'])
            ? Outlet::where('name', $row['outlet'])->first()
            : ($this->defaultOutletId ? Outlet::find($this->defaultOutletId) : null);

        // Kolom TANGGAL -- sebelumnya TIDAK PERNAH dibaca sama sekali,
        // padahal fungsi Export justru MENGELUARKAN ketiga kolom ini.
        // Akibatnya: export -> isi tanggal -> import lagi = tanggalnya
        // hilang diam-diam tanpa peringatan apa pun. Nama kolom mengikuti
        // hasil Export ("Kedaluwarsa", "Terjual Pada", "Aktivasi
        // Pertama"), plus beberapa nama alternatif yang wajar dipakai.
        $expiredAt = $this->parseTanggal($row['kedaluwarsa'] ?? $row['expired_at'] ?? $row['tanggal_expired'] ?? $row['expired'] ?? null);
        $soldAt = $this->parseTanggal($row['terjual_pada'] ?? $row['sold_at'] ?? $row['tanggal_terjual'] ?? null);
        $activatedAt = $this->parseTanggal($row['aktivasi_pertama'] ?? $row['activated_at'] ?? null);

        $atribut = [
            'password' => $row['password'] ?? $row['username'],
            'voucher_profile_id' => $profile->id,
            'outlet_id' => $outlet?->id,
            'status' => $row['status'] ?? 'belum_terjual',
            'cost_price' => $row['harga_modal'] ?? 0,
            'sale_price' => $row['harga_jual'] ?? 0,
        ];

        // Tanggal hanya ditimpa kalau kolomnya memang DIISI di file.
        // Kalau kosong, nilai yang sudah ada di database dibiarkan apa
        // adanya -- supaya import ulang tidak menghapus tanggal yang
        // sudah tercatat sebelumnya.
        if ($expiredAt) {
            $atribut['expired_at'] = $expiredAt;
        }

        if ($soldAt) {
            $atribut['sold_at'] = $soldAt;
        }

        if ($activatedAt) {
            $atribut['activated_at'] = $activatedAt;
        }

        // Voucher SUDAH TIDAK PAKAI SoftDeletes lagi — hapus beneran
        // menghapus barisnya, jadi tidak ada lagi baris "tersembunyi" yang
        // bisa bikin username bentrok di sini.
        $voucher = Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->updateOrCreate(['username' => $row['username']], $atribut);

        // WAJIB: daftarkan juga ke tabel RADIUS (radcheck/radreply). Tanpa
        // ini voucher memang MASUK ke tabel aplikasi, tapi FreeRADIUS tidak
        // mengenalinya sama sekali sehingga TIDAK BISA dipakai login.
        try {
            app(FreeRadiusService::class)->provisionVoucher($voucher, $profile);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gagal provisioning voucher hasil impor ke RADIUS', [
                'username' => $voucher->username,
                'error' => $e->getMessage(),
            ]);
        }

        return $voucher;
    }

    /**
     * Membaca isi kolom tanggal dari Excel/CSV jadi Carbon.
     *
     * Ada tiga bentuk yang harus ditangani:
     *
     * 1. ANGKA SERIAL — kalau sel-nya diformat sebagai Date asli di
     *    Excel, yang terbaca bukan teks tanggal tapi angka (mis. 46023).
     *    Ini penyebab paling sering "tanggalnya jadi ngawur".
     * 2. FORMAT INDONESIA (31/12/2026 atau 31-12-2026) — TIDAK boleh
     *    diserahkan ke Carbon::parse() begitu saja, karena PHP membaca
     *    pola dengan garis miring sebagai bulan/tanggal (gaya Amerika),
     *    sehingga 03/09/2026 salah dibaca jadi 9 Maret.
     * 3. Format ISO (2026-09-03 10:00:00) — aman diserahkan ke Carbon.
     *
     * Nilai yang tidak bisa dibaca dikembalikan null (kolomnya dianggap
     * kosong) — lebih baik tidak terisi daripada terisi tanggal ngawur.
     */
    protected function parseTanggal($value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        // (1) Angka serial Excel.
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                return null;
            }
        }

        $teks = trim((string) $value);

        // (2) Tanggal gaya Indonesia: hari dulu, baru bulan.
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})(.*)$#', $teks, $m)) {
            $tanggal = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            $jam = trim($m[4]);

            try {
                return Carbon::parse($jam !== '' ? $tanggal.' '.$jam : $tanggal);
            } catch (\Throwable) {
                return null;
            }
        }

        // (3) Sisanya (ISO dan sejenisnya).
        try {
            return Carbon::parse($teks);
        } catch (\Throwable) {
            return null;
        }
    }
}
