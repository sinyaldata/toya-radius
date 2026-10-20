<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Radacct;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        protected TransactionRepositoryInterface $transactionRepository,
    ) {}

    public function summary(?int $outletId = null): array
    {
        $today = Carbon::today();

        // Hitung user online sekali jalan, lalu pisahkan hotspot vs pppoe
        // berdasarkan username yang terdaftar sebagai pelanggan PPPoE.
        //
        // PENTING: radacct itu SEKARANG tabel PUSAT (dipakai bersama SEMUA
        // tenant, tidak ada kolom tenant_id — cuma dibedakan lewat awalan
        // username). TANPA penyaringan ini, dashboard tenant manapun bakal
        // ikut menghitung user online milik tenant LAIN juga (bug yang
        // sempat ketemu: tenant baru tanpa NAS sama sekali tetap tampil
        // "online" karena ikut kehitung sesi tenant utama).
        $pppoeUsernames = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', CustomerType::Pppoe)
            ->pluck('username');

        $onlineUsernamesRaw = Radacct::query()->online()
            ->milikTenantIni()
            ->pluck('username')
            ->map(fn ($u) => \App\Support\RadiusUsername::stripPrefix($u));

        $onlinePppoe = $onlineUsernamesRaw->intersect($pppoeUsernames)->count();
        $onlineTotal = $onlineUsernamesRaw->count();

        // Pendapatan bulan berjalan, dipecah per sumber — dipakai kartu
        // "Pendapatan Bulanan" Dashboard.
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $pendapatanVoucherBulanan = (float) $this->transactionRepository->totalRevenueBetween(
            $monthStart, $monthEnd, $outletId
        );

        $pendapatanInvoiceBulanan = (float) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->sum('amount');

        // Pendapatan HARI INI: voucher hotspot (tabel transactions) DIGABUNG
        // dengan pembayaran invoice pelanggan PPPoE (tabel invoices).
        //
        // Sebelumnya kartu ini cuma menghitung voucher — pembayaran invoice
        // (baik yang dicatat lewat halaman aplikasi maupun lewat bot
        // Telegram /bayar, yang keduanya sama-sama lewat
        // InvoiceService::recordPayment()) tidak pernah ikut kehitung di
        // sini, walau kartu BULANAN di atas sudah benar menggabung
        // keduanya. Akibatnya pembayaran hari ini seolah "hilang" padahal
        // sebenarnya sudah tercatat — cuma tidak muncul di kartu ini.
        $pendapatanVoucherHariIni = (float) $this->transactionRepository->totalRevenueBetween(
            $today->copy()->startOfDay(),
            $today->copy()->endOfDay(),
            $outletId
        );

        $pendapatanInvoiceHariIni = (float) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->sum('amount');

        // ---- Data TREN (persen naik/turun vs periode sebelumnya) --
        // permintaan user, buat badge di pojok kanan atas tiap kartu
        // (contoh gambar referensi: "+12,5%" dengan panah hijau/merah).

        // 1) Pendapatan HARI INI vs KEMARIN.
        $kemarin = $today->copy()->subDay();
        $pendapatanVoucherKemarin = (float) $this->transactionRepository->totalRevenueBetween(
            $kemarin->copy()->startOfDay(), $kemarin->copy()->endOfDay(), $outletId
        );
        $pendapatanInvoiceKemarin = (float) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$kemarin->copy()->startOfDay(), $kemarin->copy()->endOfDay()])
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->sum('amount');
        $pendapatanHariIniKemarin = $pendapatanVoucherKemarin + $pendapatanInvoiceKemarin;
        $pendapatanHariIniSekarang = $pendapatanVoucherHariIni + $pendapatanInvoiceHariIni;
        // Batas minimal Rp50.000 -- di bawah itu dianggap "hampir tidak
        // ada transaksi kemarin", persentasenya tidak bermakna buat
        // ditampilkan (lihat catatan lengkap di hitungTrenPersen()).
        $trenPendapatanHarian = $this->hitungTrenPersen($pendapatanHariIniSekarang, $pendapatanHariIniKemarin, batasMinimal: 50_000);

        // 2) Pendapatan BULAN INI vs BULAN LALU.
        $bulanLaluAwal = $monthStart->copy()->subMonthNoOverflow();
        $bulanLaluAkhir = $bulanLaluAwal->copy()->endOfMonth();
        $pendapatanVoucherBulanLalu = (float) $this->transactionRepository->totalRevenueBetween(
            $bulanLaluAwal, $bulanLaluAkhir, $outletId
        );
        $pendapatanInvoiceBulanLalu = (float) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$bulanLaluAwal, $bulanLaluAkhir])
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->sum('amount');
        $pendapatanBulanLalu = $pendapatanVoucherBulanLalu + $pendapatanInvoiceBulanLalu;
        $pendapatanBulanIniSekarang = $pendapatanVoucherBulanan + $pendapatanInvoiceBulanan;
        // Batas minimal Rp200.000 buat bulanan (skala wajarnya lebih
        // besar dari harian).
        $trenPendapatanBulanan = $this->hitungTrenPersen($pendapatanBulanIniSekarang, $pendapatanBulanLalu, batasMinimal: 200_000);

        // 3) Invoice belum dibayar: dibandingkan lewat jumlah tagihan
        // BARU TERBIT yang belum lunas minggu ini vs minggu lalu (bukan
        // total belum-dibayar keseluruhan -- itu angka "saldo berjalan",
        // tidak ada "kemarin"-nya yang bermakna buat dibandingkan
        // langsung, beda dengan pendapatan yang memang angka per-
        // periode).
        $mingguIniAwal = Carbon::now()->startOfWeek();
        $mingguLaluAwal = $mingguIniAwal->copy()->subWeek();
        $mingguLaluAkhir = $mingguIniAwal->copy()->subSecond();

        $invoiceBaruMingguIni = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->where('created_at', '>=', $mingguIniAwal)
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->count();
        $invoiceBaruMingguLalu = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->whereBetween('created_at', [$mingguLaluAwal, $mingguLaluAkhir])
            ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
            ->count();
        $trenInvoice = $this->hitungTrenPersen($invoiceBaruMingguIni, $invoiceBaruMingguLalu);

        return [
            'pendapatan_hari_ini' => $pendapatanHariIniSekarang,
            'pendapatan_hari_ini_voucher' => $pendapatanVoucherHariIni,
            'pendapatan_hari_ini_invoice' => $pendapatanInvoiceHariIni,
            'pendapatan_hari_ini_tren' => $trenPendapatanHarian,
            'pendapatan_bulanan' => $pendapatanBulanIniSekarang,
            'pendapatan_bulanan_voucher' => $pendapatanVoucherBulanan,
            'pendapatan_bulanan_invoice' => $pendapatanInvoiceBulanan,
            'pendapatan_bulanan_tren' => $trenPendapatanBulanan,
            'invoice_belum_dibayar' => (float) Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
                ->sum('amount'),
            'invoice_belum_dibayar_jumlah' => Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                ->when($outletId, fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('outlet_id', $outletId)))
                ->count(),
            'invoice_belum_dibayar_tren' => $trenInvoice,
            'total_pelanggan' => Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->count(),
            'pelanggan_aktif' => Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                ->where('status', CustomerStatus::Active)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->count(),
            'online_total' => $onlineTotal,
            'online_pppoe' => $onlinePppoe,
            'online_hotspot' => $onlineTotal - $onlinePppoe,
        ];
    }

    /**
     * Hitung persen perubahan dari $lama ke $baru, dibulatkan 1 desimal.
     * Balik null kalau $lama nol (persennya jadi tidak terhingga/tidak
     * bermakna) -- PEMANGGIL (widget) menangani null ini dengan
     * menyembunyikan badge tren-nya sama sekali, bukan menampilkan
     * angka yang menyesatkan seperti "+999%".
     */
    protected function hitungTrenPersen(float $baru, float $lama, float $batasMinimal = 0.0): ?float
    {
        // FIX (user melihat angka tidak masuk akal: "-99,1%" dan
        // "+385.637,5%"): sebelumnya cuma dicek $lama == 0 -- padahal
        // kalau $lama itu KECIL TAPI BUKAN NOL (mis. pendapatan bulan
        // lalu kebetulan cuma Rp8), hasil baginya tetap menghasilkan
        // persentase yang matematis benar tapi TIDAK BERMAKNA buat
        // dibaca (naik ratusan ribu persen). $batasMinimal (beda-beda
        // per pemanggil, lihat summary()) menyaring itu -- kalau angka
        // pembandingnya di bawah batas wajar buat metrik itu, badge
        // tren-nya disembunyikan sama sekali (balik null) daripada
        // menampilkan angka yang menyesatkan.
        if ($lama <= $batasMinimal) {
            return null;
        }

        return round((($baru - $lama) / $lama) * 100, 1);
    }

    /**
     * Data tren 7 hari terakhir untuk sparkline di tiap kartu Dashboard.
     * Semua angka diambil dari data NYATA (bukan dummy), disusun per hari
     * berurutan supaya garis grafiknya bermakna.
     */
    public function sparklines(int $days = 7, ?int $outletId = null): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $to = Carbon::now()->endOfDay();
        $period = collect(\Carbon\CarbonPeriod::create($from->copy(), $to->copy()->startOfDay()));

        $fill = fn ($keyed) => $period->map(fn ($d) => (float) ($keyed[$d->toDateString()] ?? 0))->values()->toArray();

        // 1) Pendapatan harian dari transaksi voucher yang LUNAS.
        $revenue = \App\Models\Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->selectRaw('DATE(transaction_date) as d, SUM(total_amount) as t')
            ->where('payment_status', \App\Enums\PaymentStatus::Lunas)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('transaction_date', [$from, $to])
            ->groupBy(DB::raw('DATE(transaction_date)'))
            ->pluck('t', 'd');

        // 2) Nilai tagihan BELUM LUNAS yang terbit tiap hari.
        $unpaid = Invoice::query()
            ->selectRaw('DATE(created_at) as d, SUM(amount) as t')
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('t', 'd');

        // 3) Pelanggan baru per hari (OutletScope dilepas SPESIFIK, supaya
        //    proteksi soft-delete bawaan tetap aktif — lihat catatan di
        //    summary() soal bug hitung-ganda).
        $customers = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as t')
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('t', 'd');

        // 4) Jumlah sesi koneksi yang dimulai tiap hari — difilter cuma
        //    punya tenant ini (radacct tabel pusat, lihat catatan di summary()).
        $sessions = Radacct::query()
            ->milikTenantIni()
            ->selectRaw('DATE(acctstarttime) as d, COUNT(*) as t')
            ->whereBetween('acctstarttime', [$from, $to])
            ->groupBy(DB::raw('DATE(acctstarttime)'))
            ->pluck('t', 'd');

        return [
            'revenue' => $fill($revenue),
            'unpaid' => $fill($unpaid),
            'customers' => $fill($customers),
            'sessions' => $fill($sessions),
        ];
    }

    /**
     * Ringkasan Pendapatan vs Pengeluaran untuk periode berjalan, plus
     * persentase perubahan dibanding periode SEBELUMNYA yang sama panjang
     * (mis. 30 hari terakhir vs 30 hari sebelumnya). Dipakai kotak ringkasan
     * di bawah grafik "Ringkasan Pendapatan".
     */
    public function revenueSummary(int $days = 30): array
    {
        $to = Carbon::now()->endOfDay();
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days);

        $sumRevenue = fn ($a, $b) => (float) \App\Models\Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('payment_status', \App\Enums\PaymentStatus::Lunas)
            ->whereBetween('transaction_date', [$a, $b])
            ->sum('total_amount');

        $sumExpense = fn ($a, $b) => (float) \App\Models\Expense::query()
            ->whereBetween('expense_date', [$a, $b])
            ->sum('amount');

        $revenue = $sumRevenue($from, $to);
        $expense = $sumExpense($from, $to);
        $prevRevenue = $sumRevenue($prevFrom, $prevTo);
        $prevExpense = $sumExpense($prevFrom, $prevTo);

        // Kalau periode sebelumnya 0, persentase tidak bermakna -> null
        // (ditampilkan sebagai "-", bukan angka mengada-ada).
        $change = function (float $now, float $prev): ?float {
            if ($prev <= 0.0) {
                return null;
            }

            return (($now - $prev) / $prev) * 100;
        };

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'revenue_change' => $change($revenue, $prevRevenue),
            'expense_change' => $change($expense, $prevExpense),
            'days' => $days,
        ];
    }

    /**
     * Jumlah pelanggan ONLINE per jam selama beberapa jam terakhir,
     * dipisah Hotspot vs PPPoE — dipakai widget "Pelanggan Online".
     *
     * CARA HITUNGNYA (penting kalau nanti diubah): radacct TIDAK
     * menyimpan "berapa yang online jam sekian". Yang ada cuma satu
     * baris per SESI, dengan waktu mulai (acctstarttime) dan waktu
     * berhenti (acctstoptime, kosong kalau masih jalan). Jadi "online
     * pada jam X" = jumlah sesi yang RENTANGNYA MELEWATI jam X: mulai
     * sebelum jam X berakhir, DAN berhenti sesudah jam X mulai (atau
     * belum berhenti sama sekali).
     *
     * Perhitungan tumpang-tindih itu dikerjakan di PHP, bukan lewat 24
     * query terpisah per jam — sekali ambil sesi yang menyentuh rentang
     * waktunya, lalu dihitung ember per ember.
     *
     * Penyaringan lewat scope milikTenantIni() WAJIB, karena radacct
     * dipakai bersama semua tenant dan hanya dibedakan lewat awalan
     * username (lihat Radacct::scopeMilikTenantIni()).
     */
    public function onlineChart(int $hours = 24): array
    {
        // FIX (user melapor: grafik lemot muncul pas pertama buka
        // Dashboard) -- method ini menghitung dari SEMUA baris sesi
        // radacct yang tumpang-tindih rentang waktunya (bisa banyak
        // buat tenant yang ramai), lalu memilah-milah ke tiap jam/hari
        // satu per satu. Dulu dihitung ULANG dari NOL setiap kali ada
        // yang buka Dashboard -- sekarang hasilnya disimpan sebentar
        // (cache 90 detik) supaya buka Dashboard BERIKUTNYA (oleh siapa
        // pun) dalam rentang waktu itu langsung dapat hasil yang sudah
        // dihitung, tidak perlu dihitung ulang dari nol. 90 detik
        // dipilih supaya angkanya tetap terasa "hidup" (masih ikut
        // berubah beberapa kali per menit), bukan kaku diam berjam-jam.
        $namespace = app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'central';

        return Cache::remember("dashboard_online_chart:{$namespace}:{$hours}", 90, function () use ($hours) {
            return $this->onlineChartUncached($hours);
        });
    }

    protected function onlineChartUncached(int $hours): array
    {
        $akhir = Carbon::now()->startOfHour();
        $awal = $akhir->copy()->subHours($hours - 1);

        $pppoeUsernames = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('type', CustomerType::Pppoe)
            ->pluck('username');

        // Dipetakan ke bentuk username RADIUS (berawalan tenant) lalu
        // dibalik jadi kunci array, supaya pengecekan "ini PPPoE bukan?"
        // untuk tiap sesi jadi instan.
        $pppoeRadius = $pppoeUsernames
            ->map(fn ($u) => \App\Support\RadiusUsername::forRadius($u))
            ->flip();

        $labels = [];
        $hotspot = [];
        $pppoe = [];

        // FIX (permintaan user: tambah opsi "1 Bulan"): kalau rentang
        // yang diminta lebih dari 3 hari (72 jam), dikelompokkan per
        // HARI (bukan per jam) -- selain grafik per-jam buat sebulan
        // bakal kepadatan (720 titik, tidak terbaca), loop per-jam
        // seberat itu juga lambat. Per-jam (loop asli) TETAP dipakai
        // apa adanya untuk rentang pendek (Hari Ini / 12 Jam Terakhir),
        // tidak diubah sama sekali.
        $perHari = $hours > 72;

        if ($perHari) {
            // FIX PENTING (2026-09-17): versi awal "1 Bulan" ini masih
            // pakai cara yang SAMA seperti per-jam -- ambil SEMUA baris
            // sesi sebulan ke PHP dulu (->get()), baru dihitung satu-
            // satu di loop. Buat tenant dengan banyak pelanggan, itu
            // bisa puluhan/ratusan ribu baris sekaligus ditarik ke
            // memori, dan combine dengan loop 30x di atasnya jadi
            // BENAR-BENAR LAMBAT -- sampai kena "Maximum execution time
            // of 30 seconds exceeded" (dikonfirmasi user beneran
            // kejadian). Sekarang, KHUSUS jalur per-hari ini, diganti
            // total: TIDAK ambil baris sesi ke PHP sama sekali --
            // masing-masing hari cukup 2 query COUNT() ringan langsung
            // ke database (satu buat PPPoE, satu buat Hotspot), jadi
            // database sendiri yang menghitung, bukan PHP yang menarik
            // lalu menghitung manual. Jalur per-jam (Hari Ini/12 Jam
            // Terakhir) di bawah TIDAK disentuh sama sekali -- itu
            // sudah cukup ringan apa adanya (rentangnya pendek).
            $pppoeUsernameList = $pppoeRadius->keys()->all();
            $jumlahEmber = (int) ceil($hours / 24);

            for ($i = 0; $i < $jumlahEmber; $i++) {
                $emberAwal = $awal->copy()->addDays($i)->startOfDay();
                $emberAkhir = $emberAwal->copy()->endOfDay();

                $queryDasar = fn () => Radacct::query()
                    ->milikTenantIni()
                    ->where('acctstarttime', '<=', $emberAkhir)
                    ->where(function ($q) use ($emberAwal) {
                        $q->whereNull('acctstoptime')->orWhere('acctstoptime', '>=', $emberAwal);
                    });

                $jumlahPppoe = $queryDasar()->whereIn('username', $pppoeUsernameList)->count();
                $jumlahHotspot = $queryDasar()->whereNotIn('username', $pppoeUsernameList)->count();

                $labels[] = $emberAwal->format('d M');
                $pppoe[] = $jumlahPppoe;
                $hotspot[] = $jumlahHotspot;
            }

            return [
                'labels' => $labels,
                'hotspot' => $hotspot,
                'pppoe' => $pppoe,
            ];
        }

        // ---- Jalur ASLI per-jam (Hari Ini / 12 Jam Terakhir) -- TIDAK
        // diubah sama sekali, sengaja tetap ambil semua sesi ke PHP dulu
        // karena rentangnya pendek (maks 3 hari), jadi jumlah barisnya
        // wajar dan caranya sudah lama terbukti benar. ----
        $sesi = Radacct::query()
            ->milikTenantIni()
            ->where('acctstarttime', '<=', $akhir->copy()->endOfHour())
            ->where(function ($q) use ($awal) {
                $q->whereNull('acctstoptime')->orWhere('acctstoptime', '>=', $awal);
            })
            ->get(['username', 'acctstarttime', 'acctstoptime']);

        for ($i = 0; $i < $hours; $i++) {
            $emberAwal = $awal->copy()->addHours($i);
            $emberAkhir = $emberAwal->copy()->endOfHour();

            $jumlahPppoe = 0;
            $jumlahHotspot = 0;

            foreach ($sesi as $s) {
                $mulai = $s->acctstarttime;
                $berhenti = $s->acctstoptime;

                if (! $mulai || $mulai->gt($emberAkhir)) {
                    continue;
                }

                if ($berhenti && $berhenti->lt($emberAwal)) {
                    continue;
                }

                if ($pppoeRadius->has($s->username)) {
                    $jumlahPppoe++;
                } else {
                    $jumlahHotspot++;
                }
            }

            $labels[] = $emberAwal->format('H:i');
            $pppoe[] = $jumlahPppoe;
            $hotspot[] = $jumlahHotspot;
        }

        return [
            'labels' => $labels,
            'hotspot' => $hotspot,
            'pppoe' => $pppoe,
        ];
    }

    public function salesChart(int $days = 30): array
    {
        $from = Carbon::now()->subDays($days)->startOfDay();
        $to = Carbon::now()->endOfDay();

        $rows = $this->transactionRepository->salesChartData($from, $to);

        // Pengeluaran per hari, dalam rentang tanggal yang sama, supaya bisa
        // dijejerkan sebagai garis kedua di grafik "Ringkasan Pendapatan".
        $expenseByDate = \App\Models\Expense::query()
            ->selectRaw('DATE(expense_date) as date, SUM(amount) as total')
            ->whereBetween('expense_date', [$from, $to])
            ->groupBy(DB::raw('DATE(expense_date)'))
            ->pluck('total', 'date');

        // Bangun daftar tanggal LENGKAP hari-per-hari (bukan cuma tanggal yang
        // ada transaksinya) supaya garis Pendapatan & Pengeluaran sejajar di
        // sumbu-X yang persis sama, tidak geser satu sama lain.
        $revenueByDate = $rows->pluck('total', 'date');

        $period = collect(\Carbon\CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()));

        return [
            'labels' => $period->map(fn ($d) => $d->format('d M'))->toArray(),
            'revenue' => $period->map(fn ($d) => (float) ($revenueByDate[$d->toDateString()] ?? 0))->toArray(),
            'expense' => $period->map(fn ($d) => (float) ($expenseByDate[$d->toDateString()] ?? 0))->toArray(),
            'count' => $rows->pluck('count')->map(fn ($v) => (int) $v)->toArray(),
        ];
    }

    /**
     * Data "Grafik Pendapatan" versi baru (permintaan user 2026-09-18,
     * gantikan Grafik Pengguna Online -- widget itu lemot karena harus
     * mengecek tumpang-tindih sesi login satu-satu, sedangkan ini
     * "cuma" menjumlahkan transaksi per tanggal/bulan, jauh lebih
     * ringan). Voucher & Invoice DIPISAH (bukan digabung jadi satu
     * angka "revenue" seperti salesChart() di atas) -- permintaan user
     * eksplisit minta rinciannya kelihatan, sama seperti kartu
     * "Pendapatan Hari Ini" yang juga menampilkan "Voucher RpX .
     * Invoice RpY" di keterangannya.
     *
     * $mode 'harian' = 14 hari terakhir, dikelompokkan per HARI.
     * $mode 'bulanan' = 12 bulan terakhir, dikelompokkan per BULAN
     * (bukan per hari -- 365 batang harian bakal terlalu padat/tidak
     * kebaca buat rentang setahun, sama pertimbangannya dengan fix
     * "1 Bulan" di Grafik Pengguna Online sebelumnya).
     */
    public function revenueChart(string $mode = 'harian'): array
    {
        $perBulan = $mode === 'bulanan';

        $awal = $perBulan
            ? Carbon::now()->subMonthsNoOverflow(11)->startOfMonth()
            : Carbon::now()->subDays(13)->startOfDay();
        $akhir = $perBulan ? Carbon::now()->endOfMonth() : Carbon::now()->endOfDay();

        $formatSql = $perBulan ? '%Y-%m' : '%Y-%m-%d';

        $voucherByPeriode = Transaction::query()
            ->withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('payment_status', PaymentStatus::Lunas)
            ->whereBetween('transaction_date', [$awal, $akhir])
            ->selectRaw("DATE_FORMAT(transaction_date, '{$formatSql}') as periode, SUM(total_amount) as total")
            ->groupBy('periode')
            ->pluck('total', 'periode');

        $invoiceByPeriode = Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$awal, $akhir])
            ->selectRaw("DATE_FORMAT(paid_at, '{$formatSql}') as periode, SUM(amount) as total")
            ->groupBy('periode')
            ->pluck('total', 'periode');

        // Bangun daftar periode LENGKAP (bukan cuma yang ada transaksinya)
        // supaya batang Voucher & Invoice sejajar di sumbu-X yang sama,
        // termasuk periode yang kebetulan nol pendapatannya.
        $labels = [];
        $voucher = [];
        $invoice = [];

        if ($perBulan) {
            for ($i = 0; $i < 12; $i++) {
                $bulan = $awal->copy()->addMonthsNoOverflow($i);
                $kunci = $bulan->format('Y-m');
                $labels[] = $bulan->translatedFormat('M Y');
                $voucher[] = (float) ($voucherByPeriode[$kunci] ?? 0);
                $invoice[] = (float) ($invoiceByPeriode[$kunci] ?? 0);
            }
        } else {
            $periode = collect(\Carbon\CarbonPeriod::create($awal->copy()->startOfDay(), Carbon::now()->startOfDay()));

            foreach ($periode as $hari) {
                $kunci = $hari->format('Y-m-d');
                $labels[] = $hari->translatedFormat('d M');
                $voucher[] = (float) ($voucherByPeriode[$kunci] ?? 0);
                $invoice[] = (float) ($invoiceByPeriode[$kunci] ?? 0);
            }
        }

        return [
            'labels' => $labels,
            'voucher' => $voucher,
            'invoice' => $invoice,
        ];
    }

    /**
     * Daftar SEMUA username tenant ini (voucher hotspot + pelanggan PPPoE)
     * dalam bentuk yang BENERAN tersimpan di tabel radacct pusat (sudah
     * kena awalan tenant kalau bukan instalasi utama). Dipakai buat
     * memfilter query ke radacct supaya tidak ikut menghitung data tenant
     * lain — lihat App\Support\RadiusUsername.
     *
     * @param  \Illuminate\Support\Collection<int, string>|null  $pppoeUsernames  kalau sudah punya di tangan, hindari query ulang
     */
    // ownRadiusUsernames() DIHAPUS. Dulu method ini memuat SELURUH
    // username voucher + pelanggan ke memori setiap kali Dashboard
    // dibuka, lalu mengirimkannya sebagai daftar IN(...) raksasa ke
    // MariaDB. Itu penyebab utama proses PHP membengkak di server kecil.
    // Penggantinya: Radacct::scopeMilikTenantIni(), yang menyaring lewat
    // AWALAN username langsung di SQL.
}
