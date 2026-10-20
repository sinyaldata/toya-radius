<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'period_month',
        'period_year',
        'amount',
        'amount_paid',
        'due_date',
        'status',
        'paid_at',
        'payment_method',
        'notes',
        'biller_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date' => 'date',
            'status' => InvoiceStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::nomorInvoiceBerikutnya((int) $invoice->period_month, (int) $invoice->period_year);
            }
        });
    }

    /**
     * Nomor invoice berikutnya untuk satu periode: INV-YYYYMM-XXXX.
     *
     * PERBAIKAN (2026-10-01): dulu nomor = JUMLAH invoice periode itu + 1.
     * Itu bentrok begitu ada invoice yang dihapus (fitur "Hapus invoice
     * salah generate"), karena urutan jadi berlubang: mis. tersisa
     * 0001,0002,0003,0005 -> jumlah 4 -> nomor baru 0005 sudah dipakai ->
     * error "Duplicate entry ... invoices_invoice_number_unique" dan
     * pembuatan invoice (termasuk generate tanggal 1) berhenti di tengah.
     *
     * Sekarang: nomor = NOMOR TERTINGGI yang ada + 1, dan dijaga sekali
     * lagi dengan pemeriksaan keunikan (kalau ada pembuatan bersamaan).
     * Nomor lama TIDAK diubah; lubang bekas invoice terhapus dibiarkan.
     */
    public static function nomorInvoiceBerikutnya(int $bulan, int $tahun): string
    {
        $periode = now()->createFromDate($tahun, $bulan, 1)->format('Ym');
        $awalan = "INV-{$periode}-";

        $tertinggi = static::query()
            ->where('invoice_number', 'like', $awalan.'%')
            ->pluck('invoice_number')
            ->map(fn ($nomor) => (int) substr((string) $nomor, strlen($awalan)))
            ->max() ?? 0;

        $urut = max($tertinggi, static::query()->where('period_month', $bulan)->where('period_year', $tahun)->count()) + 1;

        do {
            $nomor = $awalan.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::query()->where('invoice_number', $nomor)->exists());

        return $nomor;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Biller yang mencatat pelunasan invoice ini lewat bot Telegram
     * (null kalau dibayar/dicatat lewat cara lain).
     */
    public function biller(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'biller_id');
    }

    /**
     * Sisa yang belum dibayar untuk invoice ini (bisa dibayar sebagian
     * lewat InvoiceService::recordPayment(), bukan cuma lunas/belum).
     */
    public function remainingAmount(): float
    {
        return max(0, (float) $this->amount - (float) $this->amount_paid);
    }

    public function periodLabel(): string
    {
        return now()->createFromDate($this->period_year, $this->period_month, 1)->translatedFormat('F Y');
    }
}
