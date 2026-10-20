<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\RadiusUserType;
use App\Models\Customer;
use App\Models\Radacct;
use App\Models\RadiusSessionLog;
use App\Models\Voucher;
use App\Support\RadiusUsername;
use Illuminate\Support\Facades\DB;

/**
 * Menyalin peristiwa LOGIN & LOGOUT dari tabel sesi FreeRADIUS (radacct)
 * menjadi baris log permanen di radius_session_logs.
 *
 * Aman dijalankan berulang-ulang: pakai updateOrCreate dengan kunci
 * (acctsessionid + event), jadi tidak akan pernah dobel walau job-nya
 * kebetulan jalan dua kali atau di-run manual.
 *
 * PENTING: radacct itu tabel PUSAT (dipakai bersama semua tenant) —
 * WAJIB difilter cuma milik tenant yang SEDANG AKTIF, kalau tidak sesi
 * tenant LAIN ikut tercatat di log tenant ini. Penyaringannya sekarang
 * dilakukan PER BARIS lewat resolveOwner(): baris yang pemiliknya tidak
 * dikenali dilewati. Cara lama (memuat semua username lalu whereIn)
 * dibuang karena memakan ratusan MB memori di server kecil.
 */
class RadiusSessionLogService
{
    public function sync(int $lookbackMinutes = 180): array
    {
        $since = now()->subMinutes($lookbackMinutes);

        // PERBAIKAN-INGATAN-TENANT: ingatan pemilik dikosongkan tiap sync() (per tenant)
        $this->ingatanPemilik = [];

        return [
            'login' => $this->syncLogins($since),
            'logout' => $this->syncLogouts($since),
        ];
    }

    protected function syncLogins($since): int
    {
        $count = 0;

        Radacct::query()
            ->whereNotNull('acctstarttime')
            ->where('acctstarttime', '>=', $since)
            ->orderBy('radacctid')
            ->chunk(200, function ($sessions) use (&$count) {
                foreach ($sessions as $session) {
                    $username = RadiusUsername::stripPrefix($session->username);
                    $owner = $this->resolveOwner($username);

                    // Baris yang pemiliknya TIDAK dikenali = milik tenant
                    // lain (radacct dipakai bersama). Dilewati, supaya
                    // isolasi antar tenant tetap terjaga persis seperti
                    // waktu penyaringannya masih memakai whereIn.
                    if ($owner['type'] === RadiusUserType::Unknown->value) {
                        continue;
                    }

                    $log = RadiusSessionLog::updateOrCreate(
                        ['acctsessionid' => $session->acctsessionid, 'event' => 'login'],
                        [
                            'username' => $username,
                            'user_type' => $owner['type'],
                            'framedipaddress' => $session->framedipaddress,
                            'callingstationid' => $session->callingstationid,
                            'nasipaddress' => $session->nasipaddress,
                            'voucher_id' => $owner['voucher_id'],
                            'customer_id' => $owner['customer_id'],
                            'event_at' => $session->acctstarttime,
                        ]
                    );

                    if ($log->wasRecentlyCreated) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    protected function syncLogouts($since): int
    {
        $count = 0;

        Radacct::query()
            ->whereNotNull('acctstoptime')
            ->where('acctstoptime', '>=', $since)
            ->orderBy('radacctid')
            ->chunk(200, function ($sessions) use (&$count) {
                foreach ($sessions as $session) {
                    $username = RadiusUsername::stripPrefix($session->username);
                    $owner = $this->resolveOwner($username);

                    // Baris yang pemiliknya TIDAK dikenali = milik tenant
                    // lain (radacct dipakai bersama). Dilewati, supaya
                    // isolasi antar tenant tetap terjaga persis seperti
                    // waktu penyaringannya masih memakai whereIn.
                    if ($owner['type'] === RadiusUserType::Unknown->value) {
                        continue;
                    }

                    $log = RadiusSessionLog::updateOrCreate(
                        ['acctsessionid' => $session->acctsessionid, 'event' => 'logout'],
                        [
                            'username' => $username,
                            'user_type' => $owner['type'],
                            'framedipaddress' => $session->framedipaddress,
                            'callingstationid' => $session->callingstationid,
                            'nasipaddress' => $session->nasipaddress,
                            'session_time' => $session->acctsessiontime,
                            'input_octets' => $session->acctinputoctets,
                            'output_octets' => $session->acctoutputoctets,
                            'terminate_cause' => $session->acctterminatecause,
                            'voucher_id' => $owner['voucher_id'],
                            'customer_id' => $owner['customer_id'],
                            'event_at' => $session->acctstoptime,
                        ]
                    );

                    if ($log->wasRecentlyCreated) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Tentukan pemilik username: voucher, pelanggan hotspot tetap, atau PPPoE.
     */
    /**
     * Ingatan sementara SELAMA satu kali jalan saja (tidak disimpan
     * antar-proses). Satu pelanggan bisa punya puluhan baris sesi dalam
     * rentang waktu yang dipindai; tanpa ini, tiap barisnya jadi dua
     * query berulang untuk jawaban yang sama persis.
     *
     * Aman dibiarkan tumbuh: isinya sebatas username yang benar-benar
     * muncul di rentang waktu tadi (bawaannya 3 jam), bukan seluruh
     * voucher yang pernah ada.
     */
    protected array $ingatanPemilik = [];

    protected function resolveOwner(?string $username): array
    {
        $default = ['type' => RadiusUserType::Unknown->value, 'voucher_id' => null, 'customer_id' => null];

        if (! $username) {
            return $default;
        }

        if (array_key_exists($username, $this->ingatanPemilik)) {
            return $this->ingatanPemilik[$username];
        }

        $voucher = Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->first(['id']);

        if ($voucher) {
            return $this->ingatanPemilik[$username] = [
                'type' => RadiusUserType::Voucher->value,
                'voucher_id' => $voucher->id,
                'customer_id' => null,
            ];
        }

        $customer = Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)->where('username', $username)->first(['id', 'type']);

        if ($customer) {
            return $this->ingatanPemilik[$username] = [
                'type' => $customer->type === CustomerType::Pppoe
                    ? RadiusUserType::Pppoe->value
                    : RadiusUserType::Hotspot->value,
                'voucher_id' => null,
                'customer_id' => $customer->id,
            ];
        }

        // Yang tidak ketemu pun diingat: username milik tenant lain bisa
        // muncul puluhan kali dalam satu rentang, dan tanpa ini semuanya
        // ditanyakan ulang ke database berkali-kali untuk jawaban
        // "bukan punya kita" yang sama.
        return $this->ingatanPemilik[$username] = $default;
    }
}
