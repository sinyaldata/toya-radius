<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    /**
     * Rincian pendapatan dari INVOICE (tagihan bulanan pelanggan Hotspot
     * tetap & PPPoE) — beda sumber dari penjualan Voucher di atas
     * (Transaction). Difilter berdasarkan tanggal DIBAYAR (paid_at), bukan
     * tanggal jatuh tempo — karena yang dihitung di sini pendapatan yang
     * BENAR-BENAR MASUK di periode itu.
     */
    public function invoiceSummary(array $filters): array
    {
        $query = Invoice::query()
            ->where('status', InvoiceStatus::Paid);

        if (! empty($filters['from'])) {
            $query->whereDate('paid_at', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('paid_at', '<=', Carbon::parse($filters['to']));
        }

        if (! empty($filters['outlet_id'])) {
            $query->whereHas('customer', fn ($q) => $q->where('outlet_id', $filters['outlet_id']));
        }

        // Saring per AREA/WILAYAH — lewat outlet/biller yang menaungi
        // pelanggannya. Kalau outlet_id juga diisi, keduanya berlaku
        // bersamaan (area mempersempit, bukan menimpa).
        if (! empty($filters['area_id'])) {
            $query->whereHas('customer.outlet', fn ($q) => $q->where('area_id', $filters['area_id']));
        }

        $rows = $query->get();
        $total = (float) $rows->sum('amount_paid');

        return [
            'total_invoice' => $rows->count(),
            'total_pendapatan' => $total,
            'rata_rata_invoice' => $rows->count() > 0 ? $total / $rows->count() : 0,
        ];
    }

    public function query(array $filters): Builder
    {
        $query = Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->with(['voucher.profile', 'outlet', 'cashier'])
            ->where('payment_status', PaymentStatus::Lunas);

        if (! empty($filters['from'])) {
            $query->whereDate('transaction_date', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->whereDate('transaction_date', '<=', Carbon::parse($filters['to']));
        }

        if (! empty($filters['outlet_id'])) {
            $query->where('outlet_id', $filters['outlet_id']);
        }

        // Saring per AREA/WILAYAH — semua transaksi dari outlet yang
        // terdaftar di area tsb. Transaksi tanpa outlet (stok pusat)
        // otomatis tidak ikut, karena areanya memang tidak ada.
        if (! empty($filters['area_id'])) {
            $query->whereHas('outlet', fn ($q) => $q->where('area_id', $filters['area_id']));
        }

        if (! empty($filters['cashier_id'])) {
            $query->where('cashier_id', $filters['cashier_id']);
        }

        if (! empty($filters['voucher_profile_id'])) {
            $query->whereHas('voucher', fn ($q) => $q->where('voucher_profile_id', $filters['voucher_profile_id']));
        }

        return $query->orderByDesc('transaction_date');
    }

    /**
     * Rincian pendapatan PER OUTLET — dipakai untuk tabel di bagian bawah
     * halaman Laporan Penjualan (No, Nama Outlet, Pendapatan Total, Komisi,
     * Profit).
     */
    public function outletBreakdown(array $filters): \Illuminate\Support\Collection
    {
        $rows = $this->query($filters)->get();

        return $rows
            ->groupBy(fn ($t) => $t->outlet?->id ?? 0)
            ->map(function ($group) {
                $outlet = $group->first()->outlet;
                $total = (float) $group->sum('total_amount');

                // "Bebas Saldo" cuma soal generate voucher tanpa potong
                // saldo -- outlet tsb tetap dapat komisi seperti biasa
                // sesuai commission_type-nya (lihat Outlet::hitungKomisi()).
                //
                // FIX (permintaan user: dukung komisi flat/nominal
                // tetap, bukan cuma persentase): SEBELUMNYA rumusnya
                // "$total * persen" -- itu SUDAH BENAR untuk tipe
                // Percent (persentase dari GABUNGAN semua transaksi
                // menghasilkan angka yang SAMA dengan menjumlah persen
                // dari tiap transaksi satu-satu, jadi tidak berubah).
                // TAPI untuk tipe Flat, komisinya per-TRANSAKSI, bukan
                // per-KELOMPOK -- kalau cuma dipakai sekali ke $total,
                // outlet dengan 100 transaksi bakal dapat komisi flat
                // yang SAMA dengan outlet 1 transaksi, jelas salah.
                // Makanya dijumlah PER TRANSAKSI di dalam $group ini
                // (Outlet::hitungKomisi() sendiri sudah otomatis pilih
                // rumus mana yang dipakai per outlet).
                $komisi = $outlet ? (float) $group->sum(fn ($t) => $outlet->hitungKomisi((float) $t->total_amount, $t->voucher)) : 0;

                return [
                    'nama' => $outlet?->name ?? 'Stok Pusat',
                    'total_transaksi' => $group->count(),
                    'pendapatan_total' => $total,
                    'komisi' => $komisi,
                    'pendapatan_bersih' => $total - $komisi,
                ];
            })
            ->sortByDesc('pendapatan_total')
            ->values();
    }

    public function summarize(array $filters): array
    {
        $rows = $this->query($filters)->get();

        // Komisi dihitung per transaksi lewat Outlet::hitungKomisi()
        // milik outlet transaksi itu sendiri (otomatis pilih rumus
        // sesuai commission_type-nya) — supaya benar walau laporan
        // mencakup banyak outlet berbeda sekaligus. Sudah per-transaksi
        // dari awal, jadi bisa langsung dipakai apa adanya di sini
        // tanpa penyesuaian tambahan.
        $totalKomisi = (float) $rows->sum(fn ($t) => $t->outlet
            ? $t->outlet->hitungKomisi((float) $t->total_amount, $t->voucher)
            : 0);

        $totalPendapatan = (float) $rows->sum('total_amount');

        return [
            'total_transaksi' => $rows->count(),
            'total_pendapatan' => $totalPendapatan,
            'total_diskon' => (float) $rows->sum('discount_amount'),
            'total_komisi' => $totalKomisi,
            'hasil_setelah_komisi' => $totalPendapatan - $totalKomisi,
            'rata_rata_transaksi' => $rows->count() > 0 ? $totalPendapatan / $rows->count() : 0,
        ];
    }
}
