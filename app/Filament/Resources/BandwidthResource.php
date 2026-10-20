<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BandwidthResource\Pages;
use App\Models\Bandwidth;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BandwidthResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Bandwidth::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Bandwidth';

    protected static ?int $navigationSort = 0;

    public static function getModelLabel(): string
    {
        return 'Bandwidth';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Bandwidth';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // Section terpisah (BUKAN bersarang di dalam Section lain)
            // -- Section di dalam Section adalah PENYEBAB kotak putih
            // aneh di judul "Pengaturan Kecepatan" pada screenshot lama
            // (bug rendering tema gelap Filament untuk Section
            // bersarang). Dipisah jadi 2 Section sejajar sekaligus lebih
            // rapi: "Info dasar" & "Kecepatan" jelas terpisah.
            Section::make('Informasi Dasar')
                ->icon('heroicon-o-identification')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Bandwidth')
                        ->placeholder('contoh: 10 Mbps, Paket Gold, dll.')
                        ->prefixIcon('heroicon-o-tag')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('parent_queue_name')
                        ->label('Nama Parent Queue di MikroTik')
                        ->placeholder('mis. PARENT-VOUCHER-2000')
                        ->prefixIcon('heroicon-o-signal'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Section::make('Pengaturan Kecepatan')
                ->description('Min = kecepatan terjamin (limit-at). Max = kecepatan maksimal (max-limit). Isi 0 untuk Unlimited.')
                ->icon('heroicon-o-bolt')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('min_upload')
                        ->label('Min Upload')
                        ->numeric()
                        ->default(0)
                        ->suffix('Mbps')
                        ->required(),
                    Forms\Components\TextInput::make('max_upload')
                        ->label('Max Upload')
                        ->numeric()
                        ->default(0)
                        ->suffix('Mbps')
                        ->required(),
                    Forms\Components\TextInput::make('min_download')
                        ->label('Min Download')
                        ->numeric()
                        ->default(0)
                        ->suffix('Mbps')
                        ->required(),
                    Forms\Components\TextInput::make('max_download')
                        ->label('Max Download')
                        ->numeric()
                        ->default(0)
                        ->suffix('Mbps')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')->label('Nama Bandwidth')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('max_download')->label('Max Download')->numeric(decimalPlaces: 0)->suffix(' Mbps'),
                Tables\Columns\TextColumn::make('max_upload')->label('Max Upload')->numeric(decimalPlaces: 0)->suffix(' Mbps'),
                Tables\Columns\TextColumn::make('min_download')->label('Min Download')->numeric(decimalPlaces: 0)->suffix(' Mbps')->toggleable(),
                Tables\Columns\TextColumn::make('min_upload')->label('Min Upload')->numeric(decimalPlaces: 0)->suffix(' Mbps')->toggleable(),
                Tables\Columns\TextColumn::make('voucher_profiles_count')->label('Dipakai Voucher')->counts('voucherProfiles'),
                Tables\Columns\TextColumn::make('service_packages_count')->label('Dipakai Paket')->counts('servicePackages'),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
                        ->headerActions([
                Actions\CreateAction::make()->label('Tambah')->modalDescription('Tambahkan profil bandwidth baru'),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBandwidths::route('/'),
            'edit' => Pages\EditBandwidth::route('/{record}/edit'),
        ];
    }
}
