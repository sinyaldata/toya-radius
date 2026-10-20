<?php

namespace App\Imports;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\ServicePackage;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import User PPPoE — dicocokkan lewat "No Pelanggan" (customer_number),
 * BUKAN Username — customer_number itu unique di database dan lebih
 * stabil (ketemu kasus nyata: username di Excel beda dari yang
 * tersimpan, tapi No Pelanggan-nya sama — kalau dicocokkan lewat
 * Username, sistem coba bikin baris BARU dan kebentur error "Duplicate
 * entry" di customer_number karena sudah dipakai row lain).
 * Kolom "Profile" dan "Biller" dicocokkan lewat NAMA. Baris dilewati
 * kalau Profile tidak ditemukan (field wajib di database).
 *
 * CATATAN soal "Biller": kolom database yang dipakai TETAP `outlet_id`
 * (skema tidak diganti, keputusan dari sesi sebelumnya — lihat komentar
 * di PppoeUserResource::form()), tapi cuma dicocokkan ke mitra
 * BERTIPE BILLER (bukan Outlet biasa), samain dengan filter yang
 * dipakai form Tambah/Edit User PPPoE.
 *
 * Provisioning ke RADIUS (radcheck/radreply/radusergroup) langsung
 * OTOMATIS tiap baris berhasil disimpan — TIDAK PERLU lagi jalankan
 * "Provisioning Massal" manual setelah import (tombol itu sudah
 * dihapus dari menu Export/Import).
 */
class PppoeUserImport implements ToModel, WithHeadingRow, SkipsEmptyRows
{
    public function __construct(protected \App\Services\CustomerRadiusService $customerRadiusService) {}

    /** @var array<int, string> baris yang dilewati + alasannya — dibaca ExportImportPage setelah import selesai. */
    public array $skipped = [];

    protected int $rowNumber = 1; // baris 1 = heading, data mulai baris 2

    public function model(array $row)
    {
        $this->rowNumber++;

        if (empty($row['username'])) {
            $this->skipped[] = "Baris {$this->rowNumber}: kolom Username kosong";

            return null;
        }

        $profileName = $row['profile'] ?? null;
        $package = ServicePackage::where('name', $profileName)
            ->whereIn('type', ['pppoe', 'both'])
            ->first();

        if (! $package) {
            $this->skipped[] = "Baris {$this->rowNumber} ({$row['username']}): Profile \"{$profileName}\" tidak ditemukan — nama harus sama PERSIS dengan yang ada di menu PPPoE > Profile";

            return null;
        }

        // "Biller" itu nama kolom yang baru (samain dengan export terbaru).
        // "outlet" dipertahankan sebagai fallback, buat file Excel lama
        // yang masih pakai heading "Outlet" dari sebelum diganti.
        $billerName = $row['biller'] ?? $row['outlet'] ?? null;

        $biller = ! empty($billerName)
            ? Outlet::where('partner_type', \App\Enums\PartnerType::Biller)->where('name', $billerName)->first()
            : null;

        if (! empty($billerName) && ! $biller) {
            $this->skipped[] = "Baris {$this->rowNumber} ({$row['username']}): Biller \"{$billerName}\" tidak ditemukan — pelanggan tetap disimpan, tanpa Biller";
        }

        // KOLOM YANG ADA TAPI KOSONG (mis. sel Excel dikosongkan, bukan
        // dihapus) datang sebagai STRING KOSONG dari Maatwebsite Excel,
        // BUKAN null -- jadi `?? nilai_bawaan` TIDAK menangkapnya (null
        // coalescing cuma bereaksi ke null/tidak-ada-kunci). Baris yang
        // "No Pelanggan"/"Tgl Jatuh Tempo"/dst-nya dikosongkan lolos ke
        // database sebagai '' apa adanya, dan itu yang bikin gagal:
        // customer_number UNIQUE (dua baris kosong = duplikat), status di-
        // cast ke enum CustomerStatus (crash begitu pelanggannya dibuka),
        // billing_day kolom integer (SQL menolak '' -- inilah error yang
        // dilaporkan user, tenant goodnet).
        //
        // $isi() menutup celah itu: string kosong DIPERLAKUKAN SAMA
        // dengan kolom yang tidak diisi sama sekali.
        $isi = fn ($nilai, $bawaan) => ($nilai === null || $nilai === '') ? $bawaan : $nilai;

        $customerNumber = $isi($row['no_pelanggan'] ?? null, $row['username']);

        // Dicocokkan lewat No Pelanggan ATAU Username. Customer SUDAH
        // TIDAK PAKAI SoftDeletes lagi — jadi tidak ada lagi baris
        // "tersembunyi" yang bisa bikin bentrok, tidak perlu withTrashed().
        $existing = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('customer_number', $customerNumber)
            ->orWhere('username', $row['username'])
            ->first();

        // Status: kalau kosong ATAU isinya tidak dikenali (typo, huruf
        // besar, dst.), jatuh ke "active" -- daripada dibiarkan apa
        // adanya dan meledak nanti saat Eloquent meng-cast ke enum
        // CustomerStatus (baris yang tersimpan jadi tidak bisa dibuka
        // sama sekali sampai diperbaiki manual lewat database).
        $statusMentah = strtolower(trim((string) ($row['status'] ?? '')));
        $statusDikenal = in_array($statusMentah, ['active', 'suspended', 'isolir'], true);

        if ($statusMentah !== '' && ! $statusDikenal) {
            $this->skipped[] = "Baris {$this->rowNumber} ({$row['username']}): Status \"{$row['status']}\" tidak dikenali (harus active/suspended/isolir) — dipakai \"active\"";
        }

        $data = [
            'username' => $row['username'],
            'customer_number' => $customerNumber,
            'name' => $isi($row['nama'] ?? null, $row['username']),
            'password' => $isi($row['password'] ?? null, $row['username']),
            'phone' => $row['telepon'] ?? null,
            'address' => $row['alamat'] ?? null,
            'type' => CustomerType::Pppoe,
            'service_package_id' => $package->id,
            'outlet_id' => $biller?->id,
            'status' => $statusDikenal ? $statusMentah : 'active',
            // billing_day HARUS integer 1-28 (lihat migrasi customers) --
            // (int) '' = 0, dan 0 dianggap tidak diisi juga (?: 1).
            'billing_day' => max(1, min(28, (int) ($row['tgl_jatuh_tempo'] ?? 1) ?: 1)),
        ];

        if ($existing) {
            $existing->update($data);
            $this->customerRadiusService->provisionCustomer($existing, $package);

            return $existing;
        }

        $customer = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->create($data);
        $this->customerRadiusService->provisionCustomer($customer, $package);

        return $customer;
    }
}
