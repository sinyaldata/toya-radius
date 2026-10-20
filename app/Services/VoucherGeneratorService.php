<?php

namespace App\Services;

use App\DTO\VoucherGenerationData;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoleType;
use App\Enums\VoucherLoginMethod;
use App\Enums\VoucherStatus;
use App\Models\Radacct;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLog;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\VoucherRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VoucherGeneratorService
{
    public function __construct(
        protected VoucherRepositoryInterface $voucherRepository,
        protected FreeRadiusService $freeRadiusService,
        protected TransactionRepositoryInterface $transactionRepository,
    ) {}

    /**
     * Generate voucher secara batch. Seluruh proses (insert vouchers + radcheck +
     * radreply + radusergroup) dibungkus dalam satu DB transaction agar atomik —
     * kalau satu langkah gagal, semua di-rollback, tidak ada data setengah jadi.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Voucher>
     */
    // Batas atas jumlah generate SEKALI JALAN. Dinaikkan dari 1000 ke
    // 3000 (permintaan user, contoh: generate 2000 voucher sekaligus
    // lewat bot Telegram) -- di atas itu satu request/webhook bisa makan
    // waktu terlalu lama (tiap voucher butuh 1 insert + provisioning
    // FreeRADIUS), jadi tetap dibatasi supaya tidak time-out.
    public const MAX_QUANTITY = 3000;

    public function generate(VoucherGenerationData $data): \Illuminate\Support\Collection
    {
        if ($data->quantity < 1 || $data->quantity > self::MAX_QUANTITY) {
            throw new RuntimeException('Jumlah generate harus antara 1 - '.self::MAX_QUANTITY.'.');
        }

        $profile = ServicePackage::findOrFail($data->servicePackageId);
        $batchCode = 'BATCH-'.now()->format('YmdHis').'-'.strtoupper($this->randomFromCharset('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', 4));

        // Keterangan batch yang enak dibaca manusia -- BEDA dari
        // $batchCode di atas (kode acak, cuma buat pengelompokan
        // internal). Formatnya "Voucher {HARGA}" (permintaan user,
        // diluruskan: "2000" di sini maksudnya harga voucher, mis.
        // Rp2.000, BUKAN jumlah yang digenerate) -- dipakai bareng
        // "{$batchLabel} Berhasil dibuat {jumlah}pcs" di balasan bot,
        // dan supaya begitu di-scroll di riwayat batch (/batch di bot
        // Telegram) langsung kelihatan itu voucher harga berapa.
        $batchLabel = 'Voucher '.number_format((float) $profile->sale_price, 0, ',', '');

        return DB::transaction(function () use ($data, $profile, $batchCode, $batchLabel) {
            $created = collect();

            for ($i = 0; $i < $data->quantity; $i++) {
                $username = $this->generateUsername($data);
                $password = $data->loginMethod === VoucherLoginMethod::UserOnly
                    ? $username
                    : $this->randomFromCharset($data->codeCombination->charset(), $data->passwordLength);

                $voucher = $profile->vouchers()->create([
                    'username' => $username,
                    'password' => $password,
                    'batch_code' => $batchCode,
                    'batch_label' => $batchLabel,
                    'voucher_profile_id' => $profile->id,
                    'outlet_id' => $data->outletId,
                    'status' => VoucherStatus::BelumTerjual,
                    'cost_price' => $profile->cost_price,
                    'sale_price' => $profile->sale_price,
                    'generated_by' => $data->generatedBy,
                    'generated_via' => $data->generatedVia,
                    'login_method' => $data->loginMethod->value,
                    'code_combination' => $data->codeCombination->value,
                    'auto_bind_mac' => $data->autoBindMac,
                ]);

                $this->freeRadiusService->provisionVoucher($voucher, $profile);

                VoucherLog::create([
                    'voucher_id' => $voucher->id,
                    'action' => 'generated',
                    'user_id' => $data->generatedBy,
                    'meta' => ['batch_code' => $batchCode],
                ]);

                $created->push($voucher);
            }

            return $created;
        });
    }

    protected function generateUsername(VoucherGenerationData $data): string
    {
        $prefix = $data->prefix !== '' ? strtoupper($data->prefix) : '';
        $charset = $data->codeCombination->charset();

        do {
            $randomLength = max(4, $data->usernameLength - strlen($prefix));
            $username = $prefix.$this->randomFromCharset($charset, $randomLength);
        } while ($this->voucherRepository->findByUsername($username));

        return $username;
    }

    protected function randomFromCharset(string $charset, int $length): string
    {
        $result = '';
        $max = strlen($charset) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $charset[random_int(0, $max)];
        }

        return $result;
    }

    public function sell(Voucher $voucher): void
    {
        $this->voucherRepository->updateStatus($voucher, VoucherStatus::Terjual);
        $voucher->update(['sold_at' => now()]);

        VoucherLog::create([
            'voucher_id' => $voucher->id,
            'action' => 'sold',
        ]);
    }

    public function disable(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $this->voucherRepository->updateStatus($voucher, VoucherStatus::Disabled);
            $this->freeRadiusService->disableVoucher($voucher);

            VoucherLog::create(['voucher_id' => $voucher->id, 'action' => 'disabled']);
        });
    }

    public function enable(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $this->voucherRepository->updateStatus($voucher, VoucherStatus::Terjual);
            $this->freeRadiusService->enableVoucher($voucher);
        });
    }

    /**
     * Dipanggil dari Scheduled Job: tandai voucher aktif yang sudah lewat
     * expired_at, blokir di FreeRADIUS, DAN putus paksa sesinya di router
     * kalau user-nya masih online (kalau cuma diblokir di radius, sesi yang
     * sudah terlanjur jalan tetap nyambung sampai dia logout sendiri).
     */
    public function markExpiredVouchers(): int
    {
        $expired = $this->voucherRepository->expiredButNotMarked();
        $count = 0;

        foreach ($expired as $voucher) {
            try {
                DB::transaction(function () use ($voucher) {
                    $this->voucherRepository->updateStatus($voucher, VoucherStatus::Expired);
                    // expireVoucher = blokir + pesan "kadaluarsa, beli voucher baru
                    // di outlet terdekat" yang tampil di halaman login hotspot.
                    $this->freeRadiusService->expireVoucher($voucher);
                    VoucherLog::create(['voucher_id' => $voucher->id, 'action' => 'expired']);
                });

                $this->forceDisconnect($voucher->username);

                $count++;
            } catch (Throwable $e) {
                Log::error('Gagal menandai voucher expired', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /**
     * Putus paksa sesi aktif user di router MikroTik (best-effort — kegagalan
     * di sini tidak boleh membatalkan penandaan expired di database).
     */
    protected function forceDisconnect(string $username): void
    {
        try {
            $session = Radacct::query()->online()->where('username', \App\Support\RadiusUsername::forRadius($username))->first();

            if (! $session) {
                return;
            }

            $router = app(RouterResolver::class)->resolveForNasIp($session->nasipaddress);

            if ($router) {
                app(MikrotikService::class)->disconnectUser($router, $username);
            }
        } catch (Throwable $e) {
            Log::warning('Gagal memutus sesi voucher expired', ['username' => $username, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sinkronisasi status "sudah dipakai / belum" — baca tabel radacct (diisi
     * otomatis oleh FreeRADIUS setiap ada login) untuk mendeteksi voucher yang
     * sudah login pertama kali, lalu ubah jadi Aktif, hitung expired_at, dan
     * kalau opsi Auto-bind MAC aktif — kunci voucher ke MAC yang dipakai
     * saat login pertama itu.
     *
     * PENTING: voucher fisik biasanya TIDAK pernah diklik "Jual" satu-satu di
     * panel (dicetak lalu dijual langsung ke pelanggan). Jadi login pertama
     * kali JUGA dianggap sebagai bukti transaksi — kalau voucher masih
     * berstatus "Belum Terjual" saat terdeteksi login, sistem otomatis buat
     * catatan Transaksi (supaya masuk Laporan Penjualan & Dashboard) sekaligus
     * menandainya Terjual+Aktif dalam satu langkah.
     */
    public function syncActivations(): int
    {
        $count = 0;

        // DIPROSES PER POTONGAN 500, bukan sekali ambil semua.
        //
        // Sebelumnya baris ini memuat SELURUH voucher yang belum aktif
        // sebagai model Eloquent lengkap dengan relasi profile-nya --
        // sekaligus, ke dalam memori. Di instalasi dengan ratusan ribu
        // voucher menganggur, satu kali jalan bisa menelan 150-300 MB,
        // dan perintah ini dijadwalkan tiap 5 menit untuk SETIAP tenant.
        // Itu penyebab lonjakan memori berkala yang terlihat di `ps`.
        //
        // chunkById (bukan chunk biasa) dipakai karena perulangan di
        // dalamnya MENGUBAH activated_at, sehingga baris keluar dari
        // hasil saringan sambil diproses. chunk biasa memakai OFFSET dan
        // akan melewati baris diam-diam saat itu terjadi; chunkById
        // menelusuri berdasarkan id sehingga tetap utuh.
        Voucher::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
            ->whereIn('status', [VoucherStatus::BelumTerjual, VoucherStatus::Terjual])
            ->whereNull('activated_at')
            ->with('profile')
            ->chunkById(500, function ($candidates) use (&$count) {
                $count += $this->prosesAktivasi($candidates);
            });

        return $count;
    }

    /**
     * Memproses SATU potongan voucher calon-aktivasi. Dipisah dari
     * syncActivations() supaya setiap potongan dilepas dari memori
     * begitu selesai.
     */
    protected function prosesAktivasi(\Illuminate\Support\Collection $candidates): int
    {
        $count = 0;

        $usernames = $candidates->pluck('username')->all();

        // Ambil sesi login pertama (paling awal) tiap username, sekalian MAC-nya.
        // PENTING: radacct itu tabel PUSAT (dipakai bersama semua tenant) —
        // username yang BENERAN tersimpan di situ kena awalan tenant (lihat
        // App\Support\RadiusUsername). Cari pakai bentuk yang sudah kena
        // awalan, TAPI kunci hasilnya balik ke username ASLI (tanpa awalan)
        // biar cocok sama $voucher->username di bawah.
        $usernamesRadius = collect($usernames)->mapWithKeys(
            fn ($u) => [\App\Support\RadiusUsername::forRadius($u) => $u]
        );

        $firstLogins = Radacct::query()
            ->whereIn('username', $usernamesRadius->keys())
            ->selectRaw('username, MIN(acctstarttime) as first_login')
            ->groupBy('username')
            ->pluck('first_login', 'username')
            ->mapWithKeys(fn ($v, $k) => [$usernamesRadius[$k] ?? $k => $v]);

        foreach ($candidates as $voucher) {
            $voucher->update(['activation_checked_at' => now()]);

            $firstLogin = $firstLogins[$voucher->username] ?? null;

            if (! $firstLogin) {
                continue;
            }

            try {
                DB::transaction(function () use ($voucher, $firstLogin) {
                    // PENTING: sebutkan zona waktu EKSPLISIT di sini (jangan
                    // andalkan default PHP begitu saja) — kode ini jalan lewat
                    // command terjadwal (cron), yang ternyata tidak selalu
                    // ikut pengaturan zona waktu yang sama seperti request web
                    // biasa. Tanpa ini, waktu login (dari FreeRADIUS, jam
                    // WITA) sempat kesimpan sebagai UTC — beda 8 jam, bikin
                    // transaksi "hilang" dari laporan hari yang salah.
                    $activatedAt = Carbon::parse($firstLogin, config('app.timezone', 'Asia/Makassar'));
                    $profile = $voucher->profile;
                    $wasBelumTerjual = $voucher->status === VoucherStatus::BelumTerjual;

                    // Hitung kedaluwarsa sesuai satuan aslinya. Paket berbasis waktu
                    // (mis. "6 JAM") pakai detik dari time_limit_value/unit (Batas
                    // Waktu = total durasi pemakaian). Kalau tidak, pakai Masa Berlaku
                    // (validity_days + validity_unit, bisa Jam atau Hari) — sebelumnya
                    // selalu dipaksa hari meski satuannya jam, sudah diperbaiki.
                    $sessionSeconds = $profile?->session_timeout_seconds ?? 0;

                    if ($sessionSeconds > 0) {
                        $expiredAt = $activatedAt->copy()->addSeconds($sessionSeconds);
                    } else {
                        $validityValue = $profile->validity_days ?? 1;
                        $validityUnit = $profile->validity_unit ?? \App\Enums\TimeUnit::Hari;

                        $expiredAt = $validityUnit === \App\Enums\TimeUnit::Jam
                            ? $activatedAt->copy()->addHours($validityValue)
                            : $activatedAt->copy()->addDays($validityValue);
                    }

                    $voucher->update([
                        'status' => VoucherStatus::Aktif,
                        'sold_at' => $wasBelumTerjual ? $activatedAt : $voucher->sold_at,
                        'activated_at' => $activatedAt,
                        'expired_at' => $expiredAt,
                    ]);

                    // Tanggal kadaluarsa baru saja dihitung — kirim juga ke
                    // FreeRADIUS supaya RADIUS sendiri yang menolak login
                    // begitu lewat tanggal, tanpa menunggu tugas terjadwal.
                    $this->freeRadiusService->syncExpiration($voucher);

                    if ($wasBelumTerjual) {
                        // Login pertama = bukti voucher ini sudah terjual (dijual fisik,
                        // tidak lewat klik "Jual" manual) — buat transaksi otomatis
                        // supaya masuk Laporan Penjualan & Dashboard Pendapatan.
                        $this->transactionRepository->create([
                            'voucher_id' => $voucher->id,
                            'outlet_id' => $voucher->outlet_id,
                            'cashier_id' => $voucher->generated_by
                                ?? User::where('role', RoleType::SuperAdmin)->value('id'),
                            'price' => $voucher->sale_price,
                            'discount_amount' => 0,
                            'total_amount' => $voucher->sale_price,
                            'payment_method' => PaymentMethod::Tunai,
                            'payment_status' => PaymentStatus::Lunas,
                            'transaction_date' => $activatedAt,
                            'notes' => 'Transaksi otomatis — terdeteksi dari login pertama voucher.',
                        ]);

                        VoucherLog::create([
                            'voucher_id' => $voucher->id,
                            'action' => 'sold',
                            'meta' => ['detected_from' => 'radacct', 'note' => 'auto-sold on first login'],
                        ]);
                    }

                    VoucherLog::create([
                        'voucher_id' => $voucher->id,
                        'action' => 'activated',
                        'meta' => ['detected_from' => 'radacct', 'first_login' => $activatedAt->toDateTimeString()],
                    ]);

                    if ($voucher->auto_bind_mac && ! $voucher->bound_mac_address) {
                        $firstSession = Radacct::query()
                            ->where('username', \App\Support\RadiusUsername::forRadius($voucher->username))
                            ->orderBy('acctstarttime')
                            ->first();

                        if ($firstSession && $firstSession->callingstationid) {
                            $this->freeRadiusService->bindMac($voucher, $firstSession->callingstationid);
                            $voucher->update(['bound_mac_address' => $firstSession->callingstationid]);
                        }
                    }
                });
                $count++;
            } catch (Throwable $e) {
                Log::error('Gagal sinkronisasi aktivasi voucher', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
