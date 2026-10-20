<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OltResource\Pages;
use App\Models\Olt;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * OLT — perangkat induk jaringan FTTH.
 */
class OltResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Olt::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'OLT';

    protected static string|\UnitEnum|null $navigationGroup = 'FTTH';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'ftth/olt';

    public static function getModelLabel(): string
    {
        return 'OLT';
    }

    public static function getPluralModelLabel(): string
    {
        return 'OLT';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Forms\Components\TextInput::make('name')
                ->label('Nama OLT')
                ->placeholder('mis. OLT Aikmel Pusat')
                ->prefixIcon('lucide-server')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('code')
                ->label('Kode')
                ->placeholder('mis. OLT-AKM-01')
                ->maxLength(30),
            Forms\Components\TextInput::make('brand')
                ->label('Merek')
                ->placeholder('mis. ZTE, Huawei')
                ->maxLength(50),
            Forms\Components\TextInput::make('model')
                ->label('Model')
                ->placeholder('mis. C320')
                ->maxLength(50),
            Forms\Components\TextInput::make('ip_address')
                ->label('IP Address')
                ->placeholder('mis. 192.168.1.10')
                ->maxLength(45),
            Forms\Components\TextInput::make('pon_ports')
                ->label('Jumlah Port PON')
                ->numeric()
                ->minValue(1)
                ->maxValue(512),
            Forms\Components\TextInput::make('latitude')
                ->label('Latitude')
                ->placeholder('mis. -8.5041234')
                ->numeric(),
            Forms\Components\TextInput::make('longitude')
                ->label('Longitude')
                ->placeholder('mis. 116.5231234')
                ->numeric(),
            Forms\Components\TextInput::make('address')
                ->label('Alamat / Lokasi')
                ->columnSpanFull()
                ->maxLength(255),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama OLT')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Olt $r) => $r->code ?: null),
                Tables\Columns\TextColumn::make('brand')
                    ->label('Merek / Model')
                    ->formatStateUsing(fn ($state, Olt $r) => trim(($state ?: '-').' '.($r->model ?: '')))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('-')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('odps_count')
                    ->label('Jumlah ODP')
                    ->counts('odps')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('pon_ports')
                    ->label('Port PON')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah OLT')
                    ->modalHeading('Tambah OLT')
                    ->modalWidth('2xl'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit OLT')
                    ->modalWidth('2xl'),
                Actions\DeleteAction::make()
                    ->iconButton()
                    // OLT yang masih menaungi ODP tidak boleh dihapus —
                    // kalau dipaksa, ODP-nya kehilangan induk diam-diam.
                    ->before(function (Olt $record, Actions\DeleteAction $action) {
                        if ($record->odps()->exists()) {
                            Notification::make()
                                ->title('OLT masih dipakai')
                                ->body('Masih ada ODP di bawah OLT ini. Pindahkan dulu ODP-nya, baru OLT ini bisa dihapus.')
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
            'index' => Pages\ListOlts::route('/'),
        ];
    }
}
