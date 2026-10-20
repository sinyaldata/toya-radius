<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Membaca kondisi server (CPU, RAM, penyimpanan, bandwidth) untuk widget
 * "Status Server" di Dashboard.
 *
 * PRINSIP UTAMA: TIDAK memakai shell_exec/exec sama sekali.
 * Di banyak hosting fungsi itu dimatikan demi keamanan, dan kalaupun
 * hidup, memanggil perintah shell tiap kali Dashboard dibuka itu lambat.
 * Semua angka di sini diambil dari fungsi bawaan PHP dan berkas
 * /proc milik Linux, yang cukup dibaca seperti file biasa.
 *
 * KENAPA ADA CACHE: CPU dan bandwidth TIDAK bisa dibaca sekali jalan.
 * /proc/stat dan /proc/net/dev hanya menyimpan angka KUMULATIF sejak
 * server menyala — bukan "berapa persen sekarang". Nilai sekarang cuma
 * bisa didapat dengan membandingkan DUA pembacaan pada waktu berbeda.
 * Karena itu pembacaan sebelumnya disimpan di Cache, lalu dibandingkan
 * dengan pembacaan sekarang. Konsekuensinya: saat Dashboard dibuka
 * PERTAMA KALI (belum ada pembanding), CPU & bandwidth tampil 0 —
 * angkanya benar mulai pemuatan kedua.
 *
 * Semua pembacaan dibungkus pengaman: kalau berkasnya tidak ada
 * (mis. server Windows atau /proc dibatasi open_basedir), nilainya
 * dikembalikan null dan widget menampilkan "-", bukan error.
 */
class ServerStatusService
{
    protected const CACHE_CPU = 'server_status:cpu_sample';

    protected const CACHE_NET = 'server_status:net_sample';

    public function all(): array
    {
        return [
            'cpu' => $this->cpu(),
            'ram' => $this->ram(),
            'disk' => $this->disk(),
            'net' => $this->network(),
            'uptime' => $this->uptime(),
        ];
    }

    /**
     * Pemakaian CPU dalam persen, dari selisih dua pembacaan /proc/stat.
     *
     * Baris pertama /proc/stat berisi total "jiffies" yang dihabiskan CPU
     * per keadaan (user, nice, system, idle, iowait, ...). Persentase
     * pemakaian = (waktu non-idle yang bertambah) / (total yang
     * bertambah).
     */
    public function cpu(): ?float
    {
        $baris = $this->bacaBerkas('/proc/stat');

        if (! $baris) {
            return null;
        }

        $pertama = strtok($baris, "\n");

        if (! str_starts_with($pertama, 'cpu ')) {
            return null;
        }

        $angka = preg_split('/\s+/', trim(substr($pertama, 4)));
        $angka = array_map('intval', array_filter($angka, 'is_numeric'));

        if (count($angka) < 4) {
            return null;
        }

        $total = array_sum($angka);
        // Indeks 3 = idle, indeks 4 = iowait (kalau ada). Keduanya
        // dihitung sebagai "menganggur".
        $idle = ($angka[3] ?? 0) + ($angka[4] ?? 0);

        $sebelumnya = Cache::get(self::CACHE_CPU);
        Cache::put(self::CACHE_CPU, ['total' => $total, 'idle' => $idle], now()->addMinutes(10));

        if (! $sebelumnya || $total <= $sebelumnya['total']) {
            return 0.0;
        }

        $selisihTotal = $total - $sebelumnya['total'];
        $selisihIdle = $idle - $sebelumnya['idle'];

        if ($selisihTotal <= 0) {
            return 0.0;
        }

        return round(max(0, min(100, (1 - ($selisihIdle / $selisihTotal)) * 100)), 1);
    }

    /**
     * Pemakaian RAM dari /proc/meminfo.
     *
     * Dipakai MemAvailable (bukan MemFree) karena itu angka yang benar-
     * benar mencerminkan memori yang MASIH BISA dipakai aplikasi — cache
     * dan buffer sebenarnya bisa direbut kembali oleh sistem, jadi kalau
     * memakai MemFree, RAM akan selalu terlihat hampir penuh padahal
     * server baik-baik saja.
     */
    public function ram(): ?array
    {
        $isi = $this->bacaBerkas('/proc/meminfo');

        if (! $isi) {
            return null;
        }

        $ambil = function (string $kunci) use ($isi): ?int {
            if (preg_match('/^'.$kunci.':\s+(\d+) kB/m', $isi, $c)) {
                return (int) $c[1] * 1024;
            }

            return null;
        };

        $total = $ambil('MemTotal');
        $tersedia = $ambil('MemAvailable') ?? $ambil('MemFree');

        if (! $total || $tersedia === null) {
            return null;
        }

        $terpakai = max(0, $total - $tersedia);

        return [
            'total' => $total,
            'used' => $terpakai,
            'percent' => round($total > 0 ? ($terpakai / $total) * 100 : 0, 1),
        ];
    }

    /**
     * Pemakaian penyimpanan pada partisi tempat aplikasi berada.
     */
    public function disk(): ?array
    {
        $jalur = base_path();
        $total = @disk_total_space($jalur);
        $bebas = @disk_free_space($jalur);

        if (! $total || $bebas === false) {
            return null;
        }

        $terpakai = max(0, $total - $bebas);

        return [
            'total' => (int) $total,
            'used' => (int) $terpakai,
            'percent' => round($total > 0 ? ($terpakai / $total) * 100 : 0, 1),
        ];
    }

    /**
     * Kecepatan jaringan (byte per detik) dari selisih dua pembacaan
     * /proc/net/dev. Antarmuka "lo" (loopback) dilewati karena itu lalu
     * lintas server ke dirinya sendiri, bukan trafik sungguhan.
     */
    public function network(): ?array
    {
        $isi = $this->bacaBerkas('/proc/net/dev');

        if (! $isi) {
            return null;
        }

        $rxTotal = 0;
        $txTotal = 0;

        foreach (explode("\n", $isi) as $baris) {
            if (! str_contains($baris, ':')) {
                continue;
            }

            [$nama, $data] = explode(':', $baris, 2);
            $nama = trim($nama);

            if ($nama === 'lo' || str_starts_with($nama, 'veth') || str_starts_with($nama, 'docker')) {
                continue;
            }

            $kolom = preg_split('/\s+/', trim($data));

            if (count($kolom) < 9) {
                continue;
            }

            $rxTotal += (int) $kolom[0];   // byte diterima
            $txTotal += (int) $kolom[8];   // byte dikirim
        }

        $sekarang = microtime(true);
        $sebelumnya = Cache::get(self::CACHE_NET);
        Cache::put(self::CACHE_NET, ['rx' => $rxTotal, 'tx' => $txTotal, 'at' => $sekarang], now()->addMinutes(10));

        if (! $sebelumnya) {
            return ['rx' => 0, 'tx' => 0, 'total_rx' => $rxTotal, 'total_tx' => $txTotal];
        }

        $jeda = max(0.001, $sekarang - $sebelumnya['at']);

        return [
            'rx' => max(0, (int) (($rxTotal - $sebelumnya['rx']) / $jeda)),
            'tx' => max(0, (int) (($txTotal - $sebelumnya['tx']) / $jeda)),
            'total_rx' => $rxTotal,
            'total_tx' => $txTotal,
        ];
    }

    /**
     * Lama server menyala, dari /proc/uptime (detik pertama = uptime).
     */
    public function uptime(): ?string
    {
        $isi = $this->bacaBerkas('/proc/uptime');

        if (! $isi) {
            return null;
        }

        $detik = (int) (float) strtok(trim($isi), ' ');

        $hari = intdiv($detik, 86400);
        $jam = intdiv($detik % 86400, 3600);
        $menit = intdiv($detik % 3600, 60);

        if ($hari > 0) {
            return "{$hari} hari {$jam} jam";
        }

        if ($jam > 0) {
            return "{$jam} jam {$menit} menit";
        }

        return "{$menit} menit";
    }

    /**
     * Format byte jadi teks pendek (KB/MB/GB/TB).
     */
    public static function formatBytes(?int $bytes, int $desimal = 1): string
    {
        if ($bytes === null) {
            return '-';
        }

        $satuan = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $nilai = (float) $bytes;

        while ($nilai >= 1024 && $i < count($satuan) - 1) {
            $nilai /= 1024;
            $i++;
        }

        return number_format($nilai, $i <= 1 ? 0 : $desimal, ',', '.').' '.$satuan[$i];
    }

    /**
     * Membaca berkas dengan aman — mengembalikan null (bukan error)
     * kalau berkasnya tidak ada / tidak boleh dibaca. Ini yang bikin
     * widget tetap tampil walau server bukan Linux atau /proc dibatasi.
     */
    protected function bacaBerkas(string $jalur): ?string
    {
        try {
            if (! @is_readable($jalur)) {
                return null;
            }

            $isi = @file_get_contents($jalur);

            return $isi === false ? null : $isi;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
