<?php

namespace App\Filament\Resources;

use App\Actions\DisconnectUserAction;
use App\Filament\Resources\HotspotSessionResource\Pages;
use App\Models\Radacct;
use App\Services\RouterResolver;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class HotspotSessionResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Radacct::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Hotspot';

    protected static ?string $navigationLabel = 'Session';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'hotspot/session';

    public static function getModelLabel(): string
    {
        return 'Session';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Hotspot Session';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Radacct itu tabel accounting FreeRADIUS BERSAMA (dipakai
            // Hotspot voucher MAUPUN PPPoE pelanggan) — tanpa filter ini,
            // session PPPoE ikut muncul di sini. Dibatasi cuma username
            // yang memang terdaftar sebagai Voucher (Hotspot).
            ->query(fn () => Radacct::query()->whereIn(
                'username',
                \App\Models\Voucher::select('username')
            ))
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('acctsessionid')->label('Session')->limit(18)->tooltip(fn ($state) => $state)->copyable(),
                Tables\Columns\TextColumn::make('username')->label('Username')->searchable()->copyable()->weight('bold')
                    ->formatStateUsing(fn (?string $state) => $state ? \App\Support\RadiusUsername::stripPrefix($state) : $state),
                Tables\Columns\TextColumn::make('nasipaddress')->label('NAS')->searchable(),
                Tables\Columns\TextColumn::make('framedipaddress')->label('IP')->placeholder('-'),
                Tables\Columns\TextColumn::make('callingstationid')->label('MAC')->placeholder('-'),
                Tables\Columns\TextColumn::make('acctsessiontime')
                    ->label('Uptime')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('H:i:s', (int) $state) : '-'),
                Tables\Columns\TextColumn::make('download_mb')->label('Download')->suffix(' MB'),
                Tables\Columns\TextColumn::make('upload_mb')->label('Upload')->suffix(' MB'),
                Tables\Columns\TextColumn::make('acctstarttime')->label('Login')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('acctstoptime')
                    ->label('Logout')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Masih online')
                    ->badge()
                    ->color(fn ($state) => $state ? 'gray' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('nasipaddress')
                    ->label('NAS')
                    ->options(fn () => Radacct::query()->distinct()->pluck('nasipaddress', 'nasipaddress')->filter()->toArray()),
                Tables\Filters\TernaryFilter::make('status_online')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Online')
                    ->falseLabel('Offline')
                    ->queries(
                        true: fn ($query) => $query->whereNull('acctstoptime'),
                        false: fn ($query) => $query->whereNotNull('acctstoptime'),
                    ),
                Tables\Filters\Filter::make('acctstarttime')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('acctstarttime', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('acctstarttime', '<=', $d));
                    }),
            ])
            ->headerActions([
                // Dipindah ke sini (bukan lagi di header halaman) supaya
                // sejajar dengan Search, sesuai permintaan.
                Actions\Action::make('clean_offline')
                    ->label('Bersihkan Data Offline')
                    ->icon('lucide-trash-2')
                    ->color('primary')
                    ->extraAttributes(['class' => 'tya-btn-outline-orange'])
                    ->requiresConfirmation()
                    ->modalHeading('Bersihkan Riwayat Sesi Offline')
                    ->modalDescription('Menghapus seluruh baris sesi yang sudah berakhir (logout) dari tabel radacct. Sesi yang masih online TIDAK ikut terhapus. Log koneksi di menu "Log Koneksi User" tetap tersimpan.')
                    ->action(function () {
                        $deleted = \App\Models\Radacct::query()->whereNotNull('acctstoptime')->delete();

                        \Filament\Notifications\Notification::make()
                            ->title(number_format($deleted).' data sesi offline dibersihkan')
                            ->success()
                            ->send();
                    }),

                // Aksi massal "Kick Koneksi" -- dipindah ke sini dari
                // ->toolbarActions() supaya SATU baris/kartu yang sama
                // dengan tombol header di atas, sama seperti halaman User
                // Hotspot & User PPPoE. Tetap Actions\BulkAction asli
                // (Filament resmi mendukung BulkAction di headerActions),
                // jadi logic baris tercentangnya 100% bawaan Filament.
                Actions\BulkAction::make('kick_selected')
                    ->extraAttributes([
                        // Tombol aksi massal ini JANGAN disembunyikan total
                        // oleh Filament waktu belum ada baris dicentang
                        // (bawaannya x-show="getSelectedRecordsCount()"),
                        // tapi tetap tampil dalam keadaan pudar. Class
                        // "tya-mute" dipasang/dilepas otomatis oleh Alpine
                        // mengikuti jumlah baris tercentang -- jadi begitu
                        // checkbox dicentang, tombolnya LANGSUNG menyala
                        // penuh lagi tanpa reload.
                        'x-show' => 'true',
                        'x-bind:class' => "! getSelectedRecordsCount() ? 'tya-mute' : ''",
                    ])
                    ->label('Kick Koneksi')
                    ->icon('lucide-power')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                        $ok = 0;
                        $fail = 0;

                        foreach ($records as $record) {
                            if ($record->acctstoptime !== null) {
                                continue; // sudah offline, lewati
                            }

                            $router = app(RouterResolver::class)->resolveForNasIp($record->nasipaddress);

                            if (! $router) {
                                $fail++;

                                continue;
                            }

                            // $record dikirim juga -- sama seperti tombol Kick satuan,
                            // supaya sesi yang berhasil diputus lewat aksi massal ini
                            // juga langsung tertutup di database, bukan menunggu router.
                            $result = app(DisconnectUserAction::class)->execute($router, $record->username, $record);
                            $result['success'] ? $ok++ : $fail++;
                        }

                        Notification::make()
                            ->title("{$ok} koneksi diputus".($fail > 0 ? ", {$fail} gagal" : ''))
                            ->status($ok > 0 ? 'success' : 'warning')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordActions([
                Actions\Action::make('disconnect')
                    ->label('Kick')
                    ->icon('lucide-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->visible(fn (Radacct $record) => $record->acctstoptime === null)
                    ->action(function (Radacct $record) {
                        $router = app(RouterResolver::class)->resolveForNasIp($record->nasipaddress);

                        if (! $router) {
                            Notification::make()
                                ->title('Router tidak ditemukan untuk NAS '.$record->nasipaddress)
                                ->body('Daftarkan IP ini di menu NAS FreeRADIUS dan hubungkan ke Router yang sesuai.')
                                ->danger()->send();

                            return;
                        }

                        // $record dikirim juga -- begitu MikroTik konfirmasi
                        // berhasil, baris sesi ini LANGSUNG ditutup di database saat
                        // itu juga (tidak menunggu Accounting-Stop dari router).
                        // Lihat App\Actions\DisconnectUserAction.
                        $result = app(DisconnectUserAction::class)->execute($router, $record->username, $record);

                        Notification::make()
                            ->title($result['success'] ? 'Koneksi diputus' : 'Gagal memutus koneksi')
                            ->body($result['error'] ?? null)
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),
            ])
            // Checkbox baris tetap perlu dinyalakan manual karena aksi
            // massalnya sudah tidak didaftarkan lewat ->toolbarActions()
            // lagi (dipindah ke ->headerActions() di atas).
            ->selectable()
            ->defaultSort('acctstarttime', 'desc')
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotspotSessions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
