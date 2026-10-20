<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoucherPrintController extends Controller
{
    public const TEMPLATES = [
        1 => 'Klasik (Garis Putus-putus)',
        2 => 'Tiket (Aksen Warna Kiri)',
        3 => 'Banner ISP (Kop Warna Solid)',
        4 => 'Kotak Tegas (Border Solid + Header Gelap)',
        5 => 'Minimalis (Tanpa Kotak)',
        6 => 'Detail Dua Kolom',
        // Template 7 BEDA SENDIRI: bukan cuma gaya kartunya, tapi juga
        // TATA LETAK LEMBARNYA -- 4 kolom x 15 baris = 60 voucher per
        // lembar A4 (template lain 5 kolom x 20 baris = 100 per lembar).
        // Kartunya jadi lebih besar sehingga kode voucher lebih mudah
        // dibaca & digunting. Pengaturan kolom/jumlah per lembar ada di
        // resources/views/vouchers/print.blade.php (dibaca dari nomor
        // template ini).
        7 => '4 Kolom — 60 Voucher per Lembar A4',
    ];

    /**
     * Langkah 1: tampilkan halaman pilih template (6 pilihan) sebelum benar-benar cetak.
     * Query yang diteruskan (batch=... atau ids=...) dibawa apa adanya ke tiap link template.
     */
    public function chooseTemplate(Request $request): View
    {
        $query = $this->baseQuery($request);
        $count = $query->count();

        abort_if($count === 0, 404, 'Tidak ada voucher untuk dicetak.');

        return view('vouchers.choose-template', [
            'templates' => self::TEMPLATES,
            'count' => $count,
            'params' => array_filter($request->only(['batch', 'ids'])),
        ]);
    }

    /**
     * Langkah 2: halaman cetak sesungguhnya, sesuai template yang dipilih (1-6).
     *
     * OutletScope Model Voucher tetap aktif (tidak withoutGlobalScopes) supaya
     * user outlet HANYA bisa mencetak voucher miliknya sendiri.
     */
    public function show(Request $request): View
    {
        $query = $this->baseQuery($request)
            ->with(['profile', 'outlet'])
            ->orderBy('id');

        // Total voucher yang TERSEDIA (sebelum dipotong jumlah/mulai) --
        // dipakai buat keterangan di toolbar halaman cetak.
        $total = (clone $query)->count();

        abort_if($total === 0, 404, 'Tidak ada voucher untuk dicetak.');

        // Cetak sebagian: user boleh menentukan mau cetak berapa lembar
        // voucher dan mulai dari urutan ke berapa. Berguna kalau satu
        // batch berisi ratusan voucher tapi kertas/tinta cuma cukup buat
        // 50-70 dulu, lalu sisanya dicetak menyusul.
        //
        // "mulai" berbasis 1 (voucher pertama = 1), bukan 0 -- ini yang
        // dilihat user di kertas, jadi jangan diubah ke basis 0.
        $mulai = max(1, (int) $request->integer('mulai', 1));
        $jumlah = max(0, (int) $request->integer('jumlah', 0));

        // Kalau nomor awalnya sudah lewat dari jumlah voucher yang ada,
        // hasilnya pasti kosong -- kasih tahu apa adanya, jangan biarkan
        // halaman kosong tanpa penjelasan.
        abort_if($mulai > $total, 404, "Nomor awal ({$mulai}) melebihi jumlah voucher yang tersedia ({$total}).");

        // PENTING: skip() TIDAK BOLEH dipakai tanpa take() -- MySQL error
        // kalau ada OFFSET tanpa LIMIT. Jadi kalau jumlahnya dikosongkan,
        // pakai sisa voucher setelah titik mulai sebagai limitnya.
        $ambil = $jumlah > 0 ? $jumlah : ($total - $mulai + 1);

        $vouchers = $query->skip($mulai - 1)->take($ambil)->get();

        abort_if($vouchers->isEmpty(), 404, 'Tidak ada voucher untuk dicetak.');

        $template = (int) $request->integer('template', 1);
        $template = array_key_exists($template, self::TEMPLATES) ? $template : 1;

        return view('vouchers.print', [
            'vouchers' => $vouchers,
            'ispName' => Setting::get('isp_name', 'Toya Radius'),
            'template' => $template,
            // Nomor urut yang dicetak di tiap kartu dimulai dari sini,
            // BUKAN selalu dari 1 -- supaya kalau user mencetak 51-100,
            // nomor di kertas benar-benar 51 sampai 100 dan nyambung
            // dengan cetakan sebelumnya.
            'nomorAwal' => $mulai,
            'totalTersedia' => $total,
        ]);
    }

    protected function baseQuery(Request $request)
    {
        $query = Voucher::query();

        if ($request->filled('batch')) {
            $query->where('batch_code', $request->string('batch'));
        } elseif ($request->filled('ids')) {
            $ids = array_filter(explode(',', (string) $request->query('ids')));

            abort_if(empty($ids), 400, 'Tidak ada voucher yang dipilih. Centang dulu voucher yang mau dicetak.');

            $query->whereIn('id', $ids);
        } else {
            abort(400, 'Tidak ada voucher yang dipilih. Centang dulu voucher yang mau dicetak.');
        }

        return $query;
    }
}
