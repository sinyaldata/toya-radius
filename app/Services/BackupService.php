<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BackupService
{
    /**
     * Jalankan `mysqldump` lewat shell (bukan generate SQL manual di PHP —
     * jauh lebih cepat & akurat untuk database besar) memakai kredensial
     * yang sama dengan config/database.php aplikasi ini. Hasilnya file .sql
     * di storage lokal, siap di-download.
     */
    public function createDatabaseBackup(): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backup otomatis saat ini cuma mendukung database MySQL/MariaDB.');
        }

        $dir = storage_path('app/backups');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $filename = 'backup-toya-radius-'.now()->format('Ymd-His').'.sql';
        $path = $dir.'/'.$filename;

        // Password lewat file config sementara (bukan argumen CLI) supaya
        // tidak kelihatan di daftar proses server (mis. lewat `ps aux`).
        $mysqlConfigFile = tempnam(sys_get_temp_dir(), 'mysqldump_cnf_');
        file_put_contents($mysqlConfigFile, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $config['username'],
            $config['password'],
            $config['host'],
            $config['port'] ?? 3306
        ));
        chmod($mysqlConfigFile, 0600);

        $result = Process::timeout(300)->run([
            'mysqldump',
            '--defaults-extra-file='.$mysqlConfigFile,
            '--single-transaction',
            '--quick',
            '--result-file='.$path,
            $config['database'],
        ]);

        @unlink($mysqlConfigFile);

        if (! $result->successful() || ! File::exists($path) || File::size($path) === 0) {
            throw new RuntimeException('Gagal membuat backup: '.$result->errorOutput());
        }

        return $path;
    }

    /**
     * Bersihkan file backup lama di server supaya storage tidak penuh.
     */
    public function pruneOldBackups(int $keepDays = 7): int
    {
        $dir = storage_path('app/backups');

        if (! File::isDirectory($dir)) {
            return 0;
        }

        $deleted = 0;

        foreach (File::files($dir) as $file) {
            if ($file->getMTime() < now()->subDays($keepDays)->timestamp) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }
}
