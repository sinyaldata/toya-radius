<?php

namespace App\Filament\Resources;

use App\Actions\DisconnectUserAction;
use App\Enums\CustomerType;
use App\Filament\Resources\PppoeOnlineResource\Pages;
use App\Models\Customer;
use App\Models\Radacct;
use App\Models\Router;
use App\Services\MikrotikService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PppoeOnlineResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Radacct::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Online';

    // Disembunyikan dari menu -- lihat catatan lengkap di
    // PppoeOfflineResource.php (alasan sama persis). Route TETAP ADA,
    // link dari kartu Dashboard "Online Sekarang" tetap jalan normal.
    protected static bool $shouldRegisterNavigation = false;

    // FIX: lihat catatan lengkap di HotspotOnlineResource.php (alasan
    // sama persis kenapa method langsung ditambahkan di sini juga).
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'pppoe/online';

    public static function getModelLabel(): string
    {
        return 'Pelanggan Online';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pelanggan Online';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn () => Radacct::query()->online()->whereIn(
                'username',
                Customer::withoutGlobalScope(\App\Models\Scopes\OutletScope::class)
                    ->where('type', CustomerType::Pppoe)
                    ->pluck('username')
                    ->map(fn ($u) => \App\Support\RadiusUsername::forRadius($u))
            ))
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

                        // Tampilkan NAMA NAS-nya, bukan IP mentah. Kalau IP-nya
                        // tidak terdaftar sebagai NAS, tampilkan IP apa adanya
                        // supaya tetap ada informasi (bukan jadi kosong).
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
                                ->body('Daftarkan IP ini di menu NAS FreeRADIUS dan hubungkan ke Router yang sesuai.')->danger()->send();

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
            'index' => Pages\ListPppoeOnline::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
