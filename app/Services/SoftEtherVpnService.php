<?php

namespace App\Services;

/**
 * Eksekusi perintah "vpncmd" (tool bawaan SoftEther) langsung dari PHP,
 * lewat mode batch (/IN:file). Ini yang bikin akun BENERAN kepakai di
 * server VPN — sebelumnya cuma tercatat di database aplikasi doang.
 *
 * SYARAT: aplikasi ini harus jalan DI SERVER YANG SAMA dengan SoftEther
 * VPN Server (localhost), dan proses PHP-nya (biasanya user www-data)
 * harus punya izin exec ke binary vpncmd.
 */
class SoftEtherVpnService
{
    protected string $vpncmdPath;

    protected string $serverPassword;

    public function __construct()
    {
        $this->vpncmdPath = config('services.softether.vpncmd_path', '/usr/local/vpnserver/vpncmd');
        $this->serverPassword = (string) config('services.softether.password');
    }

    /**
     * Buat user baru di sebuah Virtual Hub SoftEther. Mengembalikan
     * ['success' => bool, 'output' => string] — SELALU cek 'success',
     * dan kalau gagal, 'output' berisi pesan asli dari vpncmd (biar
     * gampang di-debug, bukan cuma "gagal" doang).
     */
    public function createAccount(string $hubName, string $username, string $password): array
    {
        $script = implode("\n", [
            "Hub {$hubName}",
            "UserCreate {$username} /GROUP:none /REALNAME:none /NOTE:none",
            "UserPasswordSet {$username} /PASSWORD:{$password}",
        ]);

        return $this->runBatch($script);
    }

    public function deleteAccount(string $hubName, string $username): array
    {
        $script = implode("\n", [
            "Hub {$hubName}",
            "UserDelete {$username}",
        ]);

        return $this->runBatch($script);
    }

    /**
     * Buat BANYAK akun sekaligus dalam SATU kali panggilan vpncmd —
     * jauh lebih cepat dibanding createAccount() satu-satu (yang buka
     * proses baru tiap akun, bisa lama/timeout kalau jumlahnya banyak).
     * Dipakai fitur "Generate Banyak Sekaligus".
     *
     * CATATAN JUJUR: kalau SATU akun di tengah batch gagal (mis.
     * username bentrok), vpncmd tetap lanjut ke baris berikutnya —
     * 'success' di sini cuma nunjukin proses vpncmd-nya jalan sampai
     * selesai tanpa crash, BUKAN jaminan semua akun berhasil dibuat.
     * Selalu cek 'output' (isi asli dari vpncmd) buat pastiin per-akun,
     * atau verifikasi manual lewat UserList.
     *
     * @param  array<int, array{username: string, password: string}>  $accounts
     */
    public function createAccountsBatch(string $hubName, array $accounts): array
    {
        $lines = ["Hub {$hubName}"];

        foreach ($accounts as $account) {
            $lines[] = "UserCreate {$account['username']} /GROUP:none /REALNAME:none /NOTE:none";
            $lines[] = "UserPasswordSet {$account['username']} /PASSWORD:{$account['password']}";
        }

        return $this->runBatch(implode("\n", $lines));
    }

    protected function runBatch(string $script): array
    {
        if (! is_executable($this->vpncmdPath)) {
            return [
                'success' => false,
                'output' => "vpncmd tidak ditemukan atau tidak bisa dieksekusi di: {$this->vpncmdPath}. Pastikan path-nya benar dan izin file-nya sudah +x (chmod 700 vpncmd).",
            ];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'vpncmd_');
        file_put_contents($tmpFile, $script);

        $vpnDir = dirname($this->vpncmdPath);

        // Ganti dari exec() ke proc_open() — kontrolnya lebih penuh atas
        // working directory DAN environment variable proses anak. Dugaan
        // kuat kenapa lewat PHP-FPM beda dari SSH: proses PHP-FPM (web
        // server) biasanya TIDAK punya variabel $HOME sama sekali
        // (SSH shell selalu ada), dan vpncmd kemungkinan butuh itu buat
        // nentuin lokasi baca/tulis file konfigurasi/cache-nya sendiri —
        // tanpa itu, dia bisa "nyangkut" nunggu tanpa pernah selesai.
        $process = proc_open(
            ['timeout', '20', './vpncmd', 'localhost', '/SERVER', '/PASSWORD:'.$this->serverPassword, '/IN:'.$tmpFile],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $vpnDir,
            [
                'HOME' => $vpnDir,
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ]
        );

        if (! is_resource($process)) {
            @unlink($tmpFile);

            return [
                'success' => false,
                'output' => 'Gagal memulai proses vpncmd sama sekali (proc_open gagal) — kemungkinan fungsi proc_open diblokir di konfigurasi PHP server ini (disable_functions).',
            ];
        }

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        @unlink($tmpFile);

        if ($returnCode === 124) {
            return [
                'success' => false,
                'output' => "vpncmd tidak merespons dalam 20 detik (timeout), bahkan setelah dicoba dengan environment eksplisit. Output sejauh ini:\n{$output}",
            ];
        }

        return [
            'success' => $returnCode === 0,
            'output' => $output,
        ];
    }
}
