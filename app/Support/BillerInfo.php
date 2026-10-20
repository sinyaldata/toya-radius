<?php

namespace App\Support;

use App\Enums\PartnerType;
use App\Models\Customer;
use App\Models\Outlet;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kolom & filter "Biller" untuk tabel pelanggan (User PPPoE, User Member).
 *
 * KENAPA PERLU: di aplikasi ini "siapa Billernya" tersimpan di TIGA tempat
 * yang berbeda, dan sebelumnya kolom Biller di tabel cuma membaca SATU
 * (customers.outlet_id):
 *
 *  1. Pivot `biller_customer_commissions` (Customer::billers()) -- diisi
 *     lewat menu Biller > "Kelola Pelanggan & Komisi" dan lewat bot
 *     Telegram (/pppoe, /addmember). INI yang dipakai untuk komisi.
 *  2. Kolom `customers.outlet_id` yang menunjuk mitra bertipe BILLER --
 *     diisi dari field "Biller (opsional)" di form pelanggan.
 *  3. Pivot `biller_outlet_commissions` (Outlet::billers()) -- Biller yang
 *     ditugaskan menagih SELURUH pelanggan milik satu Outlet.
 *
 * Akibatnya pelanggan yang ditambahkan lewat menu Biller tidak pernah
 * muncul di kolom Biller. Sekarang kolom ini menggabungkan ketiganya
 * (nama yang sama ditampilkan sekali), jadi otomatis terisi tanpa perlu
 * mengisi ulang di form pelanggan.
 *
 * PENTING -- WAJIB eager load, kalau tidak setiap baris memicu query
 * sendiri: tabel harus memakai ->modifyQueryUsing(fn ($q) =>
 * BillerInfo::eagerLoad($q)).
 */
class BillerInfo
{
    /** Relasi yang dibaca kolom ini. */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->with(['billers', 'outlet.billers']);
    }

    /**
     * Nama-nama Biller pelanggan ini (tanpa duplikat), urutan: penugasan
     * langsung ke pelanggan, Biller sebagai pemilik (outlet_id), lalu
     * Biller yang menagih outlet pelanggan.
     *
     * @return Collection<int,string>
     */
    public static function names(Customer $customer): Collection
    {
        $names = collect();

        foreach ($customer->billers as $biller) {
            $names->push($biller->name);
        }

        $outlet = $customer->outlet;

        if ($outlet) {
            if ($outlet->partner_type === PartnerType::Biller) {
                $names->push($outlet->name);
            }

            foreach ($outlet->billers as $biller) {
                $names->push($biller->name);
            }
        }

        return $names->filter()->unique()->values();
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('biller_penagih')
            ->label('Biller')
            ->placeholder('-')
            ->visibleFrom('md')
            ->getStateUsing(fn (Customer $record): ?string => static::names($record)->implode(', ') ?: null);
    }

    /** Filter berdasarkan Biller -- memakai definisi yang sama dengan kolom. */
    public static function filter(): SelectFilter
    {
        return SelectFilter::make('biller_penagih')
            ->label('Biller')
            ->options(fn () => Outlet::where('partner_type', PartnerType::Biller)->orderBy('name')->pluck('name', 'id'))
            ->query(function (Builder $query, array $data): Builder {
                $id = $data['value'] ?? null;

                if (! $id) {
                    return $query;
                }

                // whereKey(), BUKAN where('outlets.id'): relasi outlet.billers itu
                // self-join (outlets ke outlets), dan Laravel memberi tabel kedua
                // alias acak -- 'outlets.id' akan menyaring outlet PELANGGAN,
                // bukan Billernya.
                $kolomOutlet = $query->getModel()->qualifyColumn('outlet_id');

                return $query->where(function (Builder $q) use ($id, $kolomOutlet) {
                    $q->whereHas('billers', fn ($b) => $b->whereKey($id))
                        ->orWhere($kolomOutlet, $id)
                        ->orWhereHas('outlet.billers', fn ($b) => $b->whereKey($id));
                });
            });
    }
}
