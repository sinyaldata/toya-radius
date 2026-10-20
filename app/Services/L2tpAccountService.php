<?php

namespace App\Services;

/**
 * Kelola akun L2TP lewat file /etc/ppp/chap-secrets yang dibaca xl2tpd —
 * jauh lebih simpel dari SoftEtherVpnService (tidak perlu shell exec sama
 * sekali, cuma tulis/hapus baris di file teks biasa).
 *
 * SYARAT: file /etc/ppp/chap-secrets harus writable oleh user PHP
 * (biasanya www-data) — defaultnya file ini cuma writable oleh root, jadi
 * kemungkinan besar perlu disesuaikan permission-nya sekali di server:
 *   sudo chown root:www-data /etc/ppp/chap-secrets
 *   sudo chmod 660 /etc/ppp/chap-secrets
 *
 * CATATAN: SENGAJA tidak pakai is_writable() buat cek duluan — fungsi itu
 * ternyata suka kasih hasil salah (bilang "tidak bisa ditulis" padahal
 * bisa) di beberapa konfigurasi PHP-FPM. Langsung COBA tulis, baru cek
 * hasilnya dari return value file_put_contents() itu sendiri.
 */
class L2tpAccountService
{
    protected string $path;

    public function __construct()
    {
        $this->path = config('services.l2tp.chap_secrets_path', '/etc/ppp/chap-secrets');
    }

    /**
     * Tambah/timpa baris akun di chap-secrets. Format baku pppd:
     * username  server  password  ip-tetap
     */
    public function upsertAccount(string $username, string $password, string $tunnelIp, string $serverName = 'l2tp-server'): array
    {
        $lines = $this->readLines();

        if ($lines === null) {
            return [
                'success' => false,
                'message' => "File {$this->path} tidak bisa dibaca oleh aplikasi. Cek permission-nya.",
            ];
        }

        // Buang baris lama punya username ini (kalau ada), baru tambah yang baru.
        $lines = array_values(array_filter($lines, function ($line) use ($username) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return true;
            }

            $parts = preg_split('/\s+/', $trimmed);

            return ($parts[0] ?? null) !== $username;
        }));

        $lines[] = "{$username}\t{$serverName}\t{$password}\t{$tunnelIp}";

        $written = @file_put_contents($this->path, implode("\n", $lines)."\n");

        if ($written === false) {
            $error = error_get_last();

            return [
                'success' => false,
                'message' => "Gagal menulis ke {$this->path}: ".($error['message'] ?? 'penyebab tidak diketahui').". Jalankan di server: sudo chown root:www-data {$this->path} && sudo chmod 660 {$this->path}",
            ];
        }

        return ['success' => true, 'message' => 'Akun berhasil disimpan ke chap-secrets. Restart xl2tpd supaya kepakai.'];
    }

    public function removeAccount(string $username): array
    {
        $lines = $this->readLines();

        if ($lines === null) {
            return [
                'success' => false,
                'message' => "File {$this->path} tidak bisa dibaca oleh aplikasi.",
            ];
        }

        $lines = array_values(array_filter($lines, function ($line) use ($username) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return true;
            }

            $parts = preg_split('/\s+/', $trimmed);

            return ($parts[0] ?? null) !== $username;
        }));

        $written = @file_put_contents($this->path, implode("\n", $lines)."\n");

        if ($written === false) {
            return ['success' => false, 'message' => "Gagal menulis ke {$this->path}."];
        }

        return ['success' => true, 'message' => 'Akun berhasil dihapus dari chap-secrets.'];
    }

    /**
     * @return array<int, string>|null null kalau file tidak bisa dibaca sama sekali
     */
    protected function readLines(): ?array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        $lines = @file($this->path, FILE_IGNORE_NEW_LINES);

        return $lines === false ? null : $lines;
    }

    /**
     * Cek LANGSUNG ke file chap-secrets (bukan ke database tenant mana
     * pun) — ini satu-satunya cara cek yang BENERAN lintas-tenant, karena
     * file ini dipakai bersama oleh SEMUA tenant sekaligus. Wajib dicek
     * ini SEBELUM bikin akun baru, supaya 2 tenant beda tidak bisa
     * kebetulan pakai username yang sama (bentrok di file).
     */
    public function usernameExists(string $username): bool
    {
        $lines = $this->readLines() ?? [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed);

            if (($parts[0] ?? null) === $username) {
                return true;
            }
        }

        return false;
    }
}
