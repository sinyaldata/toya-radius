#!/usr/bin/env python3
"""
pasang-laporan-penjualan.py -- desain ulang halaman Laporan > Penjualan
(HP & PC, terang & gelap):
  - 3 tombol Cetak / Export Excel / Export PDF sejajar di HP
  - kartu "Filter Laporan" bisa dilipat, kotak ikon tiap isian,
    Bulan|Tahun dan Dari|Sampai Tanggal berdampingan di HP
  - chip rentang tanggal + kartu ringkasan berwarna dengan persen
    naik/turun dibanding periode sebelumnya
  - ringkasan invoice & rincian per outlet dirapikan

Yang diubah:
  resources/views/filament/pages/sales-report-page.blade.php  (diganti)
  app/Filament/Pages/SalesReportPage.php  (ikon isian, susunan kolom,
                                           + fungsi getSummarySebelumnya)

  python3 tools/pasang-laporan-penjualan.py --dry-run
  python3 tools/pasang-laporan-penjualan.py
  python3 tools/pasang-laporan-penjualan.py --kembalikan
"""
import argparse, glob, hashlib, os, shutil, subprocess, sys, time

SINI = os.path.dirname(os.path.abspath(__file__))
VIEW = 'resources/views/filament/pages/sales-report-page.blade.php'
PAGE = 'app/Filament/Pages/SalesReportPage.php'
VIEW_ASLI_MD5 = ['748cfa278f297c91700b982344cd48d6', 'dc4e922382c1e58344cd8d66f953e250', '6fe01d1cf03c345d42808b77e896463b', 'bcf16f2cc8dfecc9f1b5e4282b5cfa8e']  # asli + versi sebelumnya
SFX = '.bak-lappenj-'
TANDA = 'LAPORAN-PENJUALAN-V2'
IKON = ['calendar-days', 'calendar', 'store', 'map-pin', 'list-filter']

GANTI = [
    ("""                ->native(false)
                ->live()
                ->afterStateUpdated(fn (callable $set, callable $get) => static::terapkanBulanTahun($set, $get)),
            Forms\\Components\\Select::make('tahun')""",
     """                ->prefixIcon('lucide-calendar-days')
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (callable $set, callable $get) => static::terapkanBulanTahun($set, $get)),
            Forms\\Components\\Select::make('tahun')"""),
    ("""                    ->mapWithKeys(fn ($t) => [$t => $t]))
                ->native(false)""",
     """                    ->mapWithKeys(fn ($t) => [$t => $t]))
                ->prefixIcon('lucide-calendar')
                ->native(false)"""),
    ("""                ->options(fn () => Outlet::pluck('name', 'id'))
                ->disabled(fn () => Auth::user()?->isOutlet())
                ->searchable(),""",
     """                ->placeholder('Semua outlet')
                ->options(fn () => Outlet::pluck('name', 'id'))
                ->prefixIcon('lucide-store')
                ->columnSpan(['default' => 2, 'lg' => 1])
                ->disabled(fn () => Auth::user()?->isOutlet())
                ->searchable(),"""),
    ("""                ->options(fn () => \\App\\Models\\Area::orderBy('name')->pluck('name', 'id'))""",
     """                ->options(fn () => \\App\\Models\\Area::orderBy('name')->pluck('name', 'id'))
                ->prefixIcon('lucide-map-pin')
                ->columnSpan(['default' => 2, 'lg' => 1])"""),
    ("""            Forms\\Components\\DatePicker::make('from')->label('Dari Tanggal'),
            Forms\\Components\\DatePicker::make('to')->label('Sampai Tanggal'),
        ])->statePath('filters')->columns(3);""",
     """            Forms\\Components\\DatePicker::make('from')->label('Dari Tanggal')->prefixIcon('lucide-calendar'),
            Forms\\Components\\DatePicker::make('to')->label('Sampai Tanggal')->prefixIcon('lucide-calendar'),
        ])->statePath('filters')->columns(['default' => 2, 'lg' => 3]);"""),
    ("""    public function getOutletBreakdown()""",
     """    /**
     * LAPORAN-PENJUALAN-V2: ringkasan periode SEBELUMNYA dengan panjang hari
     * yang sama (mis. 1-17 Okt dibanding 14-30 Sep), dipakai untuk persen
     * naik/turun di kartu ringkasan. null kalau tanggal belum lengkap.
     */
    public function getSummarySebelumnya(): ?array
    {
        $dari = $this->filters['from'] ?? null;
        $sampai = $this->filters['to'] ?? null;

        if (blank($dari) || blank($sampai)) {
            return null;
        }

        try {
            $awal = \\Carbon\\Carbon::parse($dari)->startOfDay();
            $akhir = \\Carbon\\Carbon::parse($sampai)->startOfDay();

            if ($akhir->lt($awal)) {
                return null;
            }

            $hari = $awal->diffInDays($akhir) + 1;
            $akhirLalu = $awal->copy()->subDay();
            $awalLalu = $akhirLalu->copy()->subDays($hari - 1);

            $filterLalu = array_merge($this->filters, [
                'from' => $awalLalu->toDateString(),
                'to' => $akhirLalu->toDateString(),
                'bulan' => (int) $awalLalu->month,
                'tahun' => (int) $awalLalu->year,
            ]);

            return [
                'from' => $filterLalu['from'],
                'to' => $filterLalu['to'],
                'summary' => app(ReportService::class)->summarize($filterLalu),
            ];
        } catch (\\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function getOutletBreakdown()"""),
]


def md5(p):
    return hashlib.md5(open(p, 'rb').read()).hexdigest()


def lint(p):
    for php in ('php8.4', 'php'):
        if shutil.which(php):
            r = subprocess.run([php, '-l', p], capture_output=True, text=True)
            return r.returncode == 0, (r.stdout + r.stderr).strip()
    return None, ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='/var/www/toya-radius')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--kembalikan', action='store_true')
    ap.add_argument('--paksa', action='store_true', help='timpa view walau sudah berubah sejak dikirim')
    a = ap.parse_args()
    view = os.path.join(a.dir, VIEW)
    page = os.path.join(a.dir, PAGE)

    if a.kembalikan:
        for p, rel in ((view, VIEW), (page, PAGE)):
            cad = sorted(glob.glob(p + SFX + '*'))
            if cad:
                shutil.copy2(cad[-1], p)
                print(f'DIKEMBALIKAN: {rel}')
            else:
                print(f'(tidak ada cadangan) {rel}')
        return 0

    view_baru = os.path.join(SINI, 'laporan-penjualan', VIEW)
    src = open(page, encoding='utf-8').read()
    page_sudah = TANDA in src
    view_sudah = md5(view) == md5(view_baru)

    if page_sudah and view_sudah:
        print('Sudah terpasang -- tidak ada yang diubah.')
        return 0

    if not view_sudah and md5(view) not in VIEW_ASLI_MD5 and not a.paksa:
        print(f'GAGAL: {VIEW} sudah berubah sejak dikirim. Kirim ulang filenya, atau pakai --paksa.', file=sys.stderr)
        return 1

    # Pastikan ikon yang dipakai memang ada (ikon tidak dikenal = halaman error).
    svgdir = glob.glob(os.path.join(a.dir, 'vendor', '*', '*lucide*', 'resources', 'svg'))
    if svgdir:
        hilang = [n for n in IKON if not os.path.exists(os.path.join(svgdir[0], n + '.svg'))]
        if hilang:
            print('GAGAL: ikon Lucide tidak ditemukan: ' + ', '.join(hilang) + '. Tidak ada yang diubah.', file=sys.stderr)
            return 1
    else:
        print('(peringatan) folder ikon Lucide tidak ditemukan -- ikon tidak bisa dicek.')

    baru = src
    if not page_sudah:
        for lama, ganti in GANTI:
            n = baru.count(lama)
            if n != 1:
                print(f'GAGAL: titik ubah di {PAGE} ditemukan {n}x (harus 1):\n{lama[:120]}...\nTidak ada yang diubah.', file=sys.stderr)
                return 1
            baru = baru.replace(lama, ganti, 1)

    print(f'{PAGE}: {"sudah" if page_sudah else ("AKAN DIUBAH" if a.dry_run else "diubah")}')
    print(f'{VIEW}: {"sudah" if view_sudah else ("AKAN DIGANTI" if a.dry_run else "diganti")}')
    if a.dry_run:
        return 0

    stamp = time.strftime('%Y%m%d%H%M%S')
    if not page_sudah:
        shutil.copy2(page, page + SFX + stamp)
        open(page, 'w', encoding='utf-8').write(baru)
        ok, pesan = lint(page)
        if ok is False:
            shutil.copy2(page + SFX + stamp, page)
            print(f'GAGAL: sintaks rusak ({pesan}). Dikembalikan.', file=sys.stderr)
            return 1
    if not view_sudah:
        shutil.copy2(view, view + SFX + stamp)
        shutil.copyfile(view_baru, view)

    print('\nSelesai. Lalu: php8.4 artisan view:clear && php8.4 artisan filament:optimize')
    return 0


if __name__ == '__main__':
    sys.exit(main())
