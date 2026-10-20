<?php

namespace App\Support;

use App\Models\Customer;
use App\Services\StaticIpOnlineService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kolom, sort, filter & tombol "Cek Status" untuk tabel User Static IP.
 * Tampilannya sama dengan kolom Status di PPPoE/Member (titik hijau =
 * Online, oranye = Offline), tapi datanya dari hasil ARP-ping router --
 * lihat App\Services\StaticIpOnlineService untuk cara kerjanya.
 */
class StaticIpStatus
{
    public static function column(): TextColumn
    {
        return TextColumn::make('status_koneksi')
            ->label('Status')
            ->getStateUsing(fn (Customer $record): string => StaticIpOnlineService::stateOf($record->id))
            ->formatStateUsing(fn (string $state) => OnlineStatus::indicator($state))
            ->tooltip(function (Customer $record): string {
                $t = StaticIpOnlineService::checkedAt($record->id);

                return $t
                    ? 'Dicek '.Carbon::createFromTimestamp($t)->diffForHumans().' (otomatis tiap 5 menit)'
                    : 'Belum pernah dicek (otomatis tiap 5 menit, atau tekan Cek Status)';
            })
            ->sortable(query: fn (Builder $query, string $direction): Builder => static::sortQuery($query, $direction));
    }

    /** ASC = Offline dulu, lalu tidak diketahui, lalu Online. */
    public static function sortQuery(Builder $query, string $direction): Builder
    {
        $set = StaticIpOnlineService::freshSets();
        $arah = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $kolom = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('id'));

        $online = $set['online'] ? implode(',', array_map('intval', $set['online'])) : null;
        $offline = $set['offline'] ? implode(',', array_map('intval', $set['offline'])) : null;

        if (! $online && ! $offline) {
            return $query->orderBy('name');
        }

        $case = 'CASE';
        if ($online) {
            $case .= " WHEN {$kolom} IN ({$online}) THEN 2";
        }
        if ($offline) {
            $case .= " WHEN {$kolom} IN ({$offline}) THEN 0";
        }
        $case .= ' ELSE 1 END';

        return $query->orderByRaw("{$case} {$arah}")->orderBy('name');
    }

    public static function filter(): SelectFilter
    {
        // Label "Koneksi" (bukan "Status") karena tabel ini sudah punya
        // filter Status (aktif/suspend).
        return SelectFilter::make('sesi_online')
            ->label('Koneksi')
            ->options(['online' => 'Online', 'offline' => 'Offline', 'unknown' => 'Tidak diketahui'])
            ->query(function (Builder $query, array $data): Builder {
                $nilai = $data['value'] ?? null;

                if (! in_array($nilai, ['online', 'offline', 'unknown'], true)) {
                    return $query;
                }

                $set = StaticIpOnlineService::freshSets();
                $kolom = $query->getModel()->qualifyColumn('id');

                return match ($nilai) {
                    'online' => $query->whereIn($kolom, $set['online']),
                    'offline' => $query->whereIn($kolom, $set['offline']),
                    default => $query->whereNotIn($kolom, array_merge($set['online'], $set['offline'])),
                };
            });
    }

    /** Tombol per baris: ARP-ping SEKARANG, tanpa menunggu jadwal. */
    public static function checkAction(): Action
    {
        return Action::make('cekStatus')
            ->label('Cek Status')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->iconButton()
            ->action(function (Customer $record, StaticIpOnlineService $service) {
                $r = $service->checkCustomer($record);

                match ($r['state']) {
                    'online' => Notification::make()
                        ->title('Online')
                        ->body("{$record->name} ({$record->static_ip}) menjawab ARP-ping lewat interface {$r['interface']}.")
                        ->success()->send(),
                    'offline' => Notification::make()
                        ->title('Offline')
                        ->body("{$record->name} ({$record->static_ip}) tidak menjawab ARP-ping lewat interface {$r['interface']}.")
                        ->warning()->send(),
                    default => Notification::make()
                        ->title('Gagal mengecek')
                        ->body((string) $r['message'])
                        ->danger()->send(),
                };
            });
    }
}
