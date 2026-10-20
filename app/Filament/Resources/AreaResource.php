<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AreaResource\Pages;
use App\Models\Area;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Master data Area/Wilayah — dipakai untuk mengelompokkan mitra
 * (Outlet, Reseller, Biller) per wilayah, supaya laporan bisa
 * disaring per wilayah.
 */
class AreaResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Area::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Area / Wilayah';

    protected static string|\UnitEnum|null $navigationGroup = 'Area / Wilayah';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'mitra/area';

    public static function getModelLabel(): string
    {
        return 'Area';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Area';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Forms\Components\TextInput::make('name')
                ->label('Nama Area')
                ->placeholder('mis. Aikmel')
                ->prefixIcon('heroicon-o-map-pin')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Forms\Components\TextInput::make('code')
                ->label('Kode (opsional)')
                ->placeholder('mis. AKM')
                ->prefixIcon('heroicon-o-hashtag')
                ->unique(ignoreRecord: true)
                ->maxLength(20),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Forms\Components\Textarea::make('description')
                ->label('Keterangan')
                ->placeholder('Keterangan wilayah (opsional)')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Area')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('outlets_count')
                    ->label('Jumlah Mitra')
                    ->counts('outlets')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->placeholder('-')
                    ->limit(40)
                    ->toggleable(),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah Area')
                    ->modalHeading('Tambah Area / Wilayah')
                    ->modalWidth('lg'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit Area / Wilayah')
                    ->modalWidth('lg'),
                Actions\DeleteAction::make()
                    ->iconButton()
                    // Area yang masih dipakai mitra TIDAK boleh dihapus —
                    // supaya data mitra tidak kehilangan wilayahnya diam-diam.
                    ->before(function (Area $record, Actions\DeleteAction $action) {
                        if ($record->outlets()->exists()) {
                            Notification::make()
                                ->title('Area masih dipakai')
                                ->body('Masih ada mitra (outlet/reseller/biller) di area ini. Pindahkan dulu mitranya ke area lain, baru area ini bisa dihapus.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAreas::route('/'),
        ];
    }
}
