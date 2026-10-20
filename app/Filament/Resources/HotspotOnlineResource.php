<?php

namespace App\Filament\Resources;

use App\Actions\DisconnectUserAction;
use App\Filament\Resources\HotspotOnlineResource\Pages;
use App\Models\Radacct;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\MikrotikService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class HotspotOnlineResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Radacct::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Hotspot';

    protected static ?string $navigationLabel = 'User Online';

    // Menu DIKEMBALIKAN LAGI (permintaan user 2026-09-19: "kenapa
    // halaman voucher online tidak ada, tolong tampilkan lagi") --
    // sebelumnya sempat disembunyikan dengan alasan "indikator lampu
    // di tabel Member sudah cukup", tapi ternyata halaman INI
    // (bukan cuma tabel Member) yang punya tombol "Putus Koneksi"
    // (DisconnectUserAction) -- kemampuan itu HILANG kalau halaman
    // ini disembunyikan, indikator lampu doang tidak bisa memutus
    // koneksi siapa pun. Jadi dikembalikan lagi.

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'hotspot/online';

    public static function getModelLabel(): string
    {
        return 'User Online';
    }

    public static function getPluralModelLabel(): string
    {
        return 'User Online';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Halaman "User Online" (hotspot): sesi milik tenant ini yang
            // BUKAN pelanggan tetap -- sisanya berarti voucher.
            //
            // Dulu di sini seluruh username voucher dimuat ke memori lalu
            // dikirim sebagai daftar IN(...). Di instalasi dengan ratusan
            // ribu voucher, satu kali buka halaman ini saja bisa menelan
            // ratusan MB. Sekarang disaring lewat awalan username di SQL
            // (lihat Radacct::scopeMilikTenantIni()), dan pengurangnya
            // cuma daftar pelanggan tetap yang jumlahnya ratusan.
            ->query(function () {
                $usernamePelanggan = \App\Models\Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->pluck('username')
                    ->map(fn ($u) => \App\Support\RadiusUsername::forRadius($u))
                    ->all();

                return Radacct::query()
                    ->online()
                    ->milikTenantIni()
                    ->when(! empty($usernamePelanggan), fn ($q) => $q->whereNotIn('username', $usernamePelanggan));
            })
            ->headerActions([
            ])
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('username')->label('Username')->searchable()
                    ->formatStateUsing(fn (?string $state) => $state ? \App\Support\RadiusUsername::stripPrefix($state) : $state),
                Tables\Columns\TextColumn::make('framedipaddress')->label('IP Address'),
                Tables\Columns\TextColumn::make('nasipaddress')
                    ->label('NAS / Router')
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) {
                            return '-';
                        }

                        return \App\Models\Nas::where('nasname', $state)->value('shortname') ?: $state;
                    }),
                Tables\Columns\TextColumn::make('acctstarttime')->label('Login Sejak')->dateTime('d M Y H:i'),
                Tables\Columns\TextColumn::make('download_mb')->label('Download')->suffix(' MB'),
                Tables\Columns\TextColumn::make('upload_mb')->label('Upload')->suffix(' MB'),
                Tables\Columns\TextColumn::make('acctsessiontime')
                    ->label('Durasi Sesi')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('H:i:s', (int) $state) : '-'),
            ])
            ->recordActions([
                Actions\Action::make('disconnect')
                    ->label('Kick Koneksi')
                    ->icon('lucide-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalDescription('Memutuskan koneksi user ini secara langsung dari router (lewat API MikroTik). Kalau berhasil, user harus login ulang untuk connect lagi.')
                    ->action(function (Radacct $record) {
                        $router = app(\App\Services\RouterResolver::class)->resolveForNasIp($record->nasipaddress);

                        if (! $router) {
                            Notification::make()->title('Router tidak ditemukan untuk NAS '.$record->nasipaddress)
                                ->body('Daftarkan IP ini di menu NAS dan hubungkan ke Router yang sesuai.')->danger()->send();

                            return;
                        }

                        // $record dikirim juga -- begitu MikroTik konfirmasi berhasil,
                        // baris sesi ini LANGSUNG ditutup di database saat itu juga
                        // (tidak menunggu Accounting-Stop dari router). Lihat
                        // App\Actions\DisconnectUserAction.
                        $result = app(DisconnectUserAction::class)->execute($router, $record->username, $record);

                        Notification::make()
                            ->title($result['success'] ? 'User berhasil di-kick' : 'Gagal kick koneksi')
                            ->body($result['error'] ?? null)
                            ->status($result['success'] ? 'success' : 'danger')
                            ->send();
                    }),

                Actions\Action::make('delete_session')
                    ->label('Hapus Session')
                    ->icon('lucide-trash-2')
                    ->color('gray')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalDescription('Menghapus CATATAN sesi ini dari database (bukan memutus koneksi di router). Pakai ini kalau sesi sudah tidak aktif lagi di router tapi datanya masih "nyangkut" di sini.')
                    ->action(function (Radacct $record) {
                        $record->delete();

                        Notification::make()->title('Catatan session berhasil dihapus')->success()->send();
                    }),
            ])
            ->poll('15s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotspotOnline::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
