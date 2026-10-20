<?php

namespace App\Support;

use App\Models\Radacct;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Status koneksi (Online / Offline) untuk tabel pelanggan -- dipakai
 * bersama oleh menu "User PPPoE" dan "User Member".
 *
 * DEFINISI "ONLINE" sengaja SAMA PERSIS dengan kartu "Sesi Online" di
 * atas tabel & menu Online: ada baris di radacct (database PUSAT) yang
 * acctstoptime-nya NULL. Jadi angka kartu, filter, dan kolom ini selalu
 * cocok satu sama lain.
 *
 * KENAPA TIDAK PAKAI JOIN: tabel pelanggan ada di database milik tenant,
 * sedangkan radacct ada di database pusat (koneksi 'central') -- dua
 * database berbeda, jadi tidak bisa di-JOIN begitu saja. Sebagai gantinya
 * SEMUA username yang sedang online milik tenant ini diambil SATU KALI
 * per request (Radacct::milikTenantIni(), disaring lewat awalan username
 * tenant -- lihat RadiusUsername), lalu tiap baris tabel cuma mengecek
 * ke daftar itu di memori (tanpa query per baris).
 *
 * Perbandingan username dibuat TIDAK peka huruf besar/kecil, sama seperti
 * collation MySQL, supaya "Budi01" di pelanggan tetap cocok dengan
 * "budi01" yang tercatat di radacct.
 */
class OnlineStatus
{
    /** @var array<string, array<string,bool>|null> ingatan per request, per tenant */
    protected static array $memo = [];

    /**
     * Daftar username (tanpa awalan tenant, huruf kecil) yang sedang
     * online. NULL = radacct gagal dibaca -- status TIDAK diketahui,
     * BUKAN "semua offline" (supaya tidak menyesatkan).
     *
     * @return array<string,bool>|null
     */
    public static function onlineSet(): ?array
    {
        $key = RadiusUsername::currentPrefix() ?? '__utama__';

        if (array_key_exists($key, static::$memo)) {
            return static::$memo[$key];
        }

        try {
            $set = [];

            $usernames = Radacct::query()
                ->online()
                ->milikTenantIni()
                ->distinct()
                ->pluck('username');

            foreach ($usernames as $u) {
                $set[mb_strtolower(RadiusUsername::stripPrefix((string) $u))] = true;
            }

            return static::$memo[$key] = $set;
        } catch (\Throwable $e) {
            Log::warning('OnlineStatus: gagal membaca radacct: '.$e->getMessage());

            return static::$memo[$key] = null;
        }
    }

    /** true = online, false = offline, null = tidak diketahui. */
    public static function isOnline(?string $username): ?bool
    {
        $set = static::onlineSet();

        if ($set === null) {
            return null;
        }

        return isset($set[mb_strtolower((string) $username)]);
    }

    /**
     * Kolom "Status": titik berwarna + tulisan. Hijau = Online,
     * Oranye = Offline. Bisa di-sort: klik judul kolom sekali =
     * OFFLINE di atas, klik lagi = ONLINE di atas.
     */
    public static function column(): TextColumn
    {
        return TextColumn::make('status_koneksi')
            ->label('Status')
            ->getStateUsing(fn ($record): string => match (static::isOnline($record->username)) {
                true => 'online',
                false => 'offline',
                default => 'unknown',
            })
            ->formatStateUsing(fn (string $state): HtmlString => static::indicator($state))
            ->sortable(query: fn (Builder $query, string $direction): Builder => static::sortQuery($query, $direction));
    }

    /**
     * Filter "Koneksi" (Online/Offline). Nama filter 'sesi_online' SAMA
     * dengan yang dipakai kartu di atas tabel PPPoE, jadi link
     * ?tableFilters[sesi_online][value]=offline juga jalan di sini.
     */
    public static function filter(): SelectFilter
    {
        return SelectFilter::make('sesi_online')
            ->label('Koneksi')
            ->options(['online' => 'Online', 'offline' => 'Offline'])
            ->query(function (Builder $query, array $data): Builder {
                $nilai = $data['value'] ?? null;

                if (! in_array($nilai, ['online', 'offline'], true)) {
                    return $query;
                }

                $set = static::onlineSet();

                // Status tidak terbaca -> jangan menyaring apa pun.
                if ($set === null) {
                    return $query;
                }

                if (empty($set)) {
                    return $nilai === 'online' ? $query->whereRaw('1 = 0') : $query;
                }

                [$kolom, $daftar] = static::sqlParts($query, $set);

                return $nilai === 'online'
                    ? $query->whereRaw("LOWER({$kolom}) IN ({$daftar})")
                    : $query->whereRaw("LOWER({$kolom}) NOT IN ({$daftar})");
            });
    }

    /** ASC = Offline dulu, DESC = Online dulu; urutan sekunder: nama. */
    public static function sortQuery(Builder $query, string $direction): Builder
    {
        $set = static::onlineSet();

        if (empty($set)) {
            return $query->orderBy('name');
        }

        $arah = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        [$kolom, $daftar] = static::sqlParts($query, $set);

        return $query
            ->orderByRaw("CASE WHEN LOWER({$kolom}) IN ({$daftar}) THEN 1 ELSE 0 END {$arah}")
            ->orderBy('name');
    }

    /**
     * Nama kolom username (sudah di-quote & diberi nama tabel) dan daftar
     * nilai untuk IN (...). Nilai di-quote lewat PDO dan ditulis langsung
     * ke SQL (bukan binding), supaya daftar yang panjang tidak menabrak
     * batas jumlah placeholder.
     *
     * @param  array<string,bool>  $set
     * @return array{0:string,1:string}
     */
    protected static function sqlParts(Builder $query, array $set): array
    {
        $model = $query->getModel();
        $pdo = $model->getConnection()->getPdo();

        $kolom = $query->getQuery()->getGrammar()->wrap($model->qualifyColumn('username'));

        $daftar = implode(',', array_map(
            fn ($u) => $pdo->quote((string) $u),
            array_keys($set)
        ));

        return [$kolom, $daftar];
    }

    /**
     * Titik + tulisan. Style-nya INLINE (dan !important) supaya tidak
     * ikut tertimpa aturan CSS global tema yang banyak & bertumpuk.
     */
    public static function indicator(string $state): HtmlString
    {
        [$titik, $teks, $warnaTeks, $cahaya] = match ($state) {
            'online' => ['#22c55e', 'Online', '#16a34a', 'rgba(34,197,94,.22)'],
            'offline' => ['#f97316', 'Offline', '#ea580c', 'rgba(249,115,22,.22)'],
            default => ['#a1a1aa', 'Tidak diketahui', '#71717a', 'rgba(161,161,170,.22)'],
        };

        return new HtmlString(
            '<span style="display:inline-flex!important;align-items:center;gap:.5rem;font-weight:600!important;font-size:.8125rem;color:'.$warnaTeks.'!important;white-space:nowrap">'
            .'<span style="display:inline-block!important;flex:none;width:.65rem!important;height:.65rem!important;border-radius:9999px!important;background:'.$titik.'!important;box-shadow:0 0 0 3px '.$cahaya.'"></span>'
            .$teks
            .'</span>'
        );
    }
}
