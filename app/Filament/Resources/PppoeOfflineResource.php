<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\PppoeOfflineResource\Pages;
use App\Models\Customer;
use App\Models\Radacct;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PppoeOfflineResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Customer::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Offline';

    // Disembunyikan dari menu (permintaan user 2026-09-19): status
    // online/offline sekarang sudah kelihatan langsung sebagai lampu
    // indikator di tabel PPPoE & Member, jadi halaman terpisah ini
    // jadi kurang perlu lagi. Route & fungsinya TETAP ADA (tidak
    // dihapus) -- cuma disembunyikan dari sidebar, biar tautan lain
    // yang mungkin masih mengarah ke sini (mis. link di kartu
    // Dashboard) tetap jalan normal.
    protected static bool $shouldRegisterNavigation = false;

    // FIX: lihat catatan lengkap di HotspotOnlineResource.php (alasan
    // sama persis kenapa method langsung ditambahkan di sini juga).
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'pppoe/offline';

    public static function getModelLabel(): string
    {
        return 'Pelanggan Offline';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pelanggan Offline';
    }

    public static function getEloquentQuery(): Builder
    {
        // Pelanggan PPPoE yang username-nya TIDAK punya sesi radacct terbuka
        // (belum/tidak sedang connect). radacct itu tabel PUSAT (dipakai
        // bersama semua tenant) — cocokkan pakai bentuk username yang
        // BENERAN tersimpan di situ (kena awalan tenant kalau bukan
        // instalasi utama), baru dibandingkan balik ke username asli.
        $onlineUsernames = Radacct::query()->online()->pluck('username')
            ->map(fn ($u) => \App\Support\RadiusUsername::stripPrefix($u));

        return parent::getEloquentQuery()
            ->where('type', CustomerType::Pppoe)
            ->whereNotIn('username', $onlineUsernames);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('customer_number')->label('No. Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('username')->label('Username')->copyable(),
                Tables\Columns\TextColumn::make('servicePackage.name')->label('Profile'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerStatus $state) => $state->label())
                    ->color(fn (CustomerStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('updated_at')->label('Terakhir Update')->dateTime('d M Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(CustomerStatus::options()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPppoeOffline::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
