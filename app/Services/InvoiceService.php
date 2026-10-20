<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentLog;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvoiceService
{
    public function __construct(protected CustomerRadiusService $customerRadiusService) {}

    /**
     * Generate tagihan bulan berjalan untuk semua pelanggan aktif yang belum
     * punya invoice di periode ini. Dipanggil dari scheduled command tiap
     * tanggal 1 (atau jalan ulang aman kapan saja karena idempotent lewat
     * unique constraint customer_id+period_month+period_year).
     */
    public function generateMonthlyInvoices(?Carbon $forMonth = null): int
    {
        $period = $forMonth ?? now();
        $created = 0;

        Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->where('status', '!=', CustomerStatus::Suspended) // suspend manual = tidak ditagih otomatis
            ->with('servicePackage')
            ->chunkById(100, function ($customers) use ($period, &$created) {
                foreach ($customers as $customer) {
                    if ($this->buatInvoicePeriode($customer, $period)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * Buat invoice SATU pelanggan untuk SATU periode -- dipakai oleh
     * generateMonthlyInvoices() (tanggal 1) DAN recordPayment() (lihat
     * pastikanInvoiceBulanBerjalan()). Satu-satunya tempat aturan
     * "saldo di muka dipakai otomatis / paket Rp0 langsung lunas" hidup,
     * supaya dua jalur itu tidak pernah berbeda perilaku.
     *
     * @return Invoice|null null = dilewati (sudah ada / tidak punya paket)
     */
    public function buatInvoicePeriode(Customer $customer, Carbon $period): ?Invoice
    {
        // Lewati pelanggan yang tidak punya Paket terhubung
        // sama sekali (service_package_id kosong/rusak) �
        // tidak ada harga yang bisa dipakai untuk nagih,
        // daripada bikin seluruh proses generate berhenti
        // gara-gara satu pelanggan bermasalah.
        if (! $customer->servicePackage) {
            return null;
        }

        $exists = Invoice::where('customer_id', $customer->id)
            ->where('period_month', $period->month)
            ->where('period_year', $period->year)
            ->exists();

        if ($exists) {
            return null;
        }

        $dueDate = $period->copy()->startOfMonth()->day(min($customer->billing_day, $period->daysInMonth));
        $amount = (float) $customer->servicePackage->sale_price;

        // Kondisi #1 (bayar dimuka sampai berbulan-bulan ke
        // depan): kalau pelanggan masih punya saldo lebih bayar
        // dari pembayaran sebelumnya, PAKAI OTOMATIS buat
        // melunasi invoice baru ini � tidak perlu tagih ulang
        // untuk bulan yang sebenarnya sudah dibayar di muka.
        $amountPaid = 0;
        $status = InvoiceStatus::Unpaid;
        $paidAt = null;

        if ($customer->credit_balance > 0) {
            $used = min((float) $customer->credit_balance, $amount);
            $amountPaid = $used;
            $customer->decrement('credit_balance', $used);

            if ($used >= $amount) {
                $status = InvoiceStatus::Paid;
                $paidAt = now();
            }
        }

        // Pelanggan GRATIS (paket Rp0 � dipakai untuk keluarga/
        // outlet yang dibebaskan biaya): invoice tetap DIBUAT
        // (supaya tercatat rapi di riwayat, bisa dilaporkan),
        // tapi LANGSUNG dianggap Lunas otomatis. Kalau tidak
        // begini, invoice Rp0 yang tidak pernah "dibayar" akan
        // jatuh tempo dan otomatis diisolir oleh
        // markOverdueAndIsolir() � padahal harusnya memang
        // tidak pernah ditagih.
        if ($amount <= 0) {
            $status = InvoiceStatus::Paid;
            $paidAt = now();
            $amountPaid = 0;
        }

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period_month' => $period->month,
            'period_year' => $period->year,
            'amount' => $amount,
            'amount_paid' => $amountPaid,
            'due_date' => $dueDate,
            'status' => $status,
            'paid_at' => $paidAt,
            'payment_method' => $paidAt ? ($amount <= 0 ? 'gratis' : 'saldo_dimuka') : null,
        ]);

        return $invoice;
    }

    /**
     * PERBAIKAN (2026-10-01): pelanggan yang ditambahkan DI TENGAH BULAN
     * tidak punya invoice bulan itu (invoice hanya dibuat tanggal 1 /
     * tombol Generate). Dulu, kalau mereka bayar bulan itu, uangnya tidak
     * punya invoice untuk dilunasi -> masuk saldo -> terpakai invoice
     * bulan DEPAN. Akibatnya bulan pertama tidak pernah tertagih dan bulan
     * berikutnya tampak "sudah lunas" padahal belum dibayar (kasus nyata:
     * 6 pelanggan dibuat 15-21 September, invoice Oktober langsung lunas).
     *
     * Sekarang, sebelum mengalokasikan pembayaran, invoice BULAN BERJALAN
     * dibuat dulu kalau belum ada, sehingga pembayaran melunasinya.
     *
     * Sengaja TIDAK membuat invoice kalau: pengaturan Tagihan Otomatis
     * dimatikan (tenant menagih manual), pelanggan disuspend (sama seperti
     * generate tanggal 1), tidak punya paket, atau paketnya Rp0.
     * Bulan DEPAN tetap tidak dibuat di sini -- itu memang saldo di muka.
     */
    protected function pastikanInvoiceBulanBerjalan(Customer $customer): void
    {
        if (Setting::get('billing_auto_generate', '1') !== '1') {
            return;
        }

        if ($customer->status === CustomerStatus::Suspended) {
            return;
        }

        $paket = $customer->servicePackage;

        if (! $paket || (float) $paket->sale_price <= 0) {
            return;
        }

        try {
            $invoice = $this->buatInvoicePeriode($customer, now());

            if ($invoice) {
                Log::info('Invoice bulan berjalan dibuat otomatis saat pembayaran dicatat', [
                    'customer_id' => $customer->id,
                    'periode' => now()->format('m/Y'),
                ]);
            }
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Pembayaran lain membuatnya lebih dulu (balapan) -- invoice itu
            // sudah ada, lanjut saja.
        }
    }

    /**
     * Tandai invoice yang lewat jatuh tempo sebagai overdue, dan isolir
     * pelanggannya otomatis di FreeRADIUS. Dipanggil harian lewat scheduler.
     */
    public function markOverdueAndIsolir(): int
    {
        $count = 0;

        // Kebijakan "Isolir Otomatis Saat Jatuh Tempo" bisa dimatikan per
        // tenant/instalasi lewat Pengaturan > Tagihan Otomatis. Kalau
        // dimatikan: invoice TETAP ditandai Overdue di bawah (supaya
        // laporan & kartu "Jatuh Tempo" tetap benar dan pelanggan tetap
        // kelihatan menunggak), tapi pelanggan TIDAK diputus otomatis --
        // koneksinya tetap menyala sampai admin memutuskan sendiri lewat
        // menu Isolir di aplikasi atau `php artisan pelanggan:isolir`.
        $isolirOtomatisAktif = Setting::get('billing_auto_isolir', '1') === '1';

        Invoice::where('status', InvoiceStatus::Unpaid)
            ->where('due_date', '<', now()->startOfDay())
            ->with('customer')
            ->chunkById(100, function ($invoices) use (&$count, $isolirOtomatisAktif) {
                foreach ($invoices as $invoice) {
                    try {
                        DB::transaction(function () use ($invoice, $isolirOtomatisAktif) {
                            $invoice->update(['status' => InvoiceStatus::Overdue]);

                            if (! $isolirOtomatisAktif) {
                                return;
                            }

                            $customer = $invoice->customer;
                            if ($customer && $customer->status === CustomerStatus::Active) {
                                $customer->update(['status' => CustomerStatus::Isolir]);
                                $this->customerRadiusService->suspend($customer);
                            }
                        });
                        $count++;
                    } catch (Throwable $e) {
                        Log::error('Gagal proses overdue/isolir', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $count;
    }

    public function pay(Invoice $invoice, string $paymentMethod, ?string $notes = null): void
    {
        DB::transaction(function () use ($invoice, $paymentMethod, $notes) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'amount_paid' => $invoice->amount,
                'paid_at' => now(),
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ]);

            $customer = $invoice->customer;

            // Kalau pelanggan lagi isolir gara-gara invoice ini, aktifkan lagi
            // HANYA kalau tidak ada tagihan lain yang masih overdue/unpaid.
            if ($customer && $customer->status === CustomerStatus::Isolir) {
                $stillHasOverdue = $customer->invoices()
                    ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                    ->where('due_date', '<', now())
                    ->exists();

                if (! $stillHasOverdue) {
                    $customer->update(['status' => CustomerStatus::Active]);
                    $this->customerRadiusService->reactivate($customer);
                }
            }
        });
    }

    /**
     * Catat SATU pembayaran (satu jumlah uang) dari pelanggan, dan alokasikan
     * OTOMATIS ke tagihan-tagihannya � menangani 3 kondisi sekaligus:
     *
     * 1. BAYAR DIMUKA (borongan banyak bulan): kalau jumlah bayar melebihi
     *    semua tagihan yang ada sekarang, kelebihannya disimpan sebagai
     *    "saldo lebih bayar" (credit_balance) di data pelanggan � nanti
     *    OTOMATIS dipakai buat melunasi invoice bulan berikutnya begitu
     *    invoice itu dibuat (lihat generateMonthlyInvoices() di atas).
     *
     * 2. BAYAR SEBAGIAN (nunggak berbulan-bulan, bayar cuma cukup untuk
     *    sebagian): dialokasikan ke tagihan PALING LAMA dulu (urut dari
     *    bulan paling awal yang belum lunas). Tagihan yang jumlahnya cukup
     *    ditutup UTUH jadi Lunas; kalau uangnya habis di tengah jalan,
     *    tagihan terakhir yang kena jadi "lunas sebagian" (amount_paid
     *    naik, tapi status tetap Unpaid/Overdue sampai dilunasi penuh).
     *    Sisa tagihan bisa dilihat lewat $invoice->remainingAmount() per
     *    invoice, atau dijumlah semua lewat getOutstandingBalance().
     *
     * 3. BAYAR TEPAT WAKTU (satu bulan pas): otomatis tertangani juga oleh
     *    logika yang sama � satu invoice yang jumlahnya pas dibayar penuh.
     *
     * @return array{invoices_paid: int[], invoices_partial: int[], credit_added: float}
     */
    public function recordPayment(Customer $customer, float $amount, string $paymentMethod, ?string $notes = null, ?int $billerId = null): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Jumlah bayar harus lebih dari 0.');
        }

        // BARU (permintaan user: "kebanyakan input lewat telegram", jadi
        // tidak realistis minta kasir/bot tanya ulang manual biller-nya
        // tiap kali ada pembayaran) -- kalau $billerId TIDAK dikirim
        // eksplisit oleh pemanggil, DICARI OTOMATIS lewat penugasan yang
        // sudah ada (lihat Customer::resolveBillerId()). Pemanggil yang
        // MEMANG SENGAJA ingin kosong (mis. form web dulu, "Tanpa
        // Biller" dipilih user) TETAP bisa -- itu kasus beda, di situ
        // $billerId dikirim eksplisit sebagai null lewat pemanggilnya
        // sendiri jadi tidak masuk sini... TAPI karena PHP tidak bisa
        // beda-in "tidak dikirim" vs "dikirim null" lewat parameter
        // biasa begini, konsekuensinya: kalau user MEMANG pilih "Tanpa
        // Biller" di form Bayar, sistem TETAP akan coba cari otomatis
        // dari penugasan yang ada -- ini SENGAJA dibiarkan begitu,
        // karena penugasan resmi (kalau ada) dianggap lebih dipercaya
        // daripada pilihan kosong di form (yang mungkin cuma dilewatkan
        // begitu saja oleh kasir, bukan keputusan sadar "memang tidak
        // ada biller-nya").
        if ($billerId === null) {
            $billerId = $customer->resolveBillerId();
        }

        return DB::transaction(function () use ($customer, $amount, $paymentMethod, $notes, $billerId) {
            // Bulan berjalan belum punya invoice (pelanggan baru di tengah
            // bulan)? Buat dulu supaya pembayaran ini melunasinya, bukan
            // menumpuk jadi saldo yang terpakai bulan depan.
            $this->pastikanInvoiceBulanBerjalan($customer);

            $remaining = $amount;
            $paidIds = [];
            $partialIds = [];
            $allocations = []; // dicatat per-invoice, dipakai untuk bisa dibatalkan nanti

            // Kondisi #1 lanjutan: kalau pelanggan MASIH punya saldo lebih
            // bayar dari sebelumnya, pembayaran baru ini digabung dulu
            // dengan saldo lama sebelum dialokasikan � supaya urutan
            // pelunasan tetap konsisten dari tagihan paling lama.
            $creditUsed = 0;

            // Urut dari tagihan PALING LAMA dulu (period_year, period_month
            // ascending) � supaya nunggakan lama dilunasi lebih dulu.
            $invoices = $customer->invoices()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                ->orderBy('period_year')
                ->orderBy('period_month')
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $owed = $invoice->remainingAmount();

                if ($remaining >= $owed) {
                    // Cukup untuk lunasi invoice ini SEPENUHNYA.
                    $invoice->update([
                        'amount_paid' => $invoice->amount,
                        'status' => InvoiceStatus::Paid,
                        'paid_at' => now(),
                        'payment_method' => $paymentMethod,
                        'notes' => $notes,
                        'biller_id' => $billerId,
                    ]);
                    $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $owed];
                    $remaining -= $owed;
                    $paidIds[] = $invoice->id;
                } else {
                    // Cuma cukup sebagian � invoice ini TETAP belum lunas,
                    // tapi amount_paid-nya naik (sisa tagihannya berkurang).
                    $invoice->update([
                        'amount_paid' => (float) $invoice->amount_paid + $remaining,
                    ]);
                    $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $remaining];
                    $partialIds[] = $invoice->id;
                    $remaining = 0;
                }
            }

            // Kondisi #1: masih ada sisa uang setelah SEMUA tagihan yang
            // ada sekarang lunas � simpan sebagai saldo lebih bayar, dipakai
            // otomatis untuk invoice bulan berikutnya yang belum dibuat.
            $creditAdded = $remaining;
            if ($creditAdded > 0) {
                $customer->increment('credit_balance', $creditAdded);
            }

            // Catat log-nya � supaya pembayaran ini bisa DIEDIT/DIBATALKAN
            // nanti kalau ternyata salah input, tanpa perlu utak-atik
            // invoice satu-satu secara manual.
            PaymentLog::create([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'notes' => $notes,
                'allocations' => $allocations,
                'credit_used' => $creditUsed,
                'credit_added' => $creditAdded,
                'recorded_by' => auth()->id(),
                'biller_id' => $billerId,
            ]);

            // Aktifkan lagi kalau tadinya isolir dan sekarang tidak ada lagi
            // tagihan yang overdue.
            if ($customer->status === CustomerStatus::Isolir) {
                $stillHasOverdue = $customer->invoices()
                    ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
                    ->where('due_date', '<', now())
                    ->exists();

                if (! $stillHasOverdue) {
                    $customer->update(['status' => CustomerStatus::Active]);
                    $this->customerRadiusService->reactivate($customer);
                }
            }

            return [
                'invoices_paid' => $paidIds,
                'invoices_partial' => $partialIds,
                'credit_added' => $creditAdded,
            ];
        });
    }

    /**
     * Batalkan SATU pembayaran yang sudah dicatat (dari tombol Hapus di
     * riwayat pembayaran) � kurangi lagi amount_paid di invoice-invoice
     * yang tadinya kena alokasi, kembalikan status ke Unpaid/Overdue kalau
     * tadinya jadi Lunas gara-gara pembayaran ini, dan kurangi saldo lebih
     * bayar kalau pembayaran ini yang menambahkannya.
     *
     * CATATAN: kalau invoice yang tadinya kena alokasi SUDAH keburu dilunasi
     * lagi oleh pembayaran LAIN setelahnya (kasus jarang, tapi bisa
     * terjadi), pembatalan tetap jalan tapi statusnya tidak dipaksa mundur
     * � supaya tidak menimpa pembayaran lain yang sudah sah.
     */
    public function undoPayment(PaymentLog $log): void
    {
        DB::transaction(function () use ($log) {
            $customer = $log->customer;

            foreach ($log->allocations as $alloc) {
                $invoice = Invoice::find($alloc['invoice_id']);

                if (! $invoice) {
                    continue;
                }

                $newAmountPaid = max(0, (float) $invoice->amount_paid - (float) $alloc['amount']);

                $invoice->amount_paid = $newAmountPaid;

                if ($newAmountPaid < (float) $invoice->amount && $invoice->status === InvoiceStatus::Paid) {
                    $invoice->status = $invoice->due_date < now() ? InvoiceStatus::Overdue : InvoiceStatus::Unpaid;
                    $invoice->paid_at = null;
                }

                $invoice->save();
            }

            if ($log->credit_added > 0 && $customer) {
                $customer->decrement('credit_balance', min((float) $log->credit_added, (float) $customer->credit_balance));
            }

            $log->delete();
        });
    }

    /**
     * Total semua tagihan yang MASIH BELUM lunas (Unpaid + Overdue),
     * dihitung dari sisa masing-masing invoice (bukan cuma jumlah invoice
     * yang belum lunas) � untuk kondisi #2, biar staff tahu persis berapa
     * "sisa tagihan" total pelanggan itu.
     */
    public function getOutstandingBalance(Customer $customer): float
    {
        return (float) $customer->invoices()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
    }
}
