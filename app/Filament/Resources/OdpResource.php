<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OdpResource\Pages;
use App\Models\Odp;
use App\Models\Olt;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * ODP — kotak pembagi di lapangan.
 */
class OdpResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = Odp::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'ODP';

    protected static string|\UnitEnum|null $navigationGroup = 'FTTH';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'ftth/odp';

    public static function getModelLabel(): string
    {
        return 'ODP';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ODP';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Forms\Components\TextInput::make('name')
                ->label('Nama ODP')
                ->placeholder('mis. ODP-AKM-012')
                ->prefixIcon('lucide-box')
                ->required()
                ->maxLength(100),
            Forms\Components\TextInput::make('code')
                ->label('Kode')
                ->placeholder('opsional')
                ->maxLength(30),
            Forms\Components\Select::make('olt_id')
                ->label('OLT Induk')
                ->placeholder('Belum ditentukan')
                ->options(fn () => Olt::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            Forms\Components\TextInput::make('pon_port')
                ->label('Port PON di OLT')
                ->placeholder('mis. 1/2/3')
                ->maxLength(30),
            Forms\Components\TextInput::make('total_ports')
                ->label('Kapasitas Port')
                ->numeric()
                ->minValue(1)
                ->maxValue(64)
                ->default(8)
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Forms\Components\TextInput::make('latitude')
                ->label('Latitude')
                ->placeholder('mis. -8.5041234')
                ->numeric(),
            Forms\Components\TextInput::make('longitude')
                ->label('Longitude')
                ->placeholder('mis. 116.5231234')
                ->numeric(),
            Forms\Components\TextInput::make('address')
                ->label('Alamat / Patokan')
                ->columnSpanFull()
                ->maxLength(255),
            Forms\Components\Textarea::make('notes')
                ->label('Catatan')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // withCount di sini PENTING: kolom "Port Terpakai" di bawah
            // membaca customers_count. Tanpa ini, menampilkan 50 ODP
            // berarti 50 query hitung terpisah.
            ->modifyQueryUsing(fn ($query) => $query->withCount('customers'))
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama ODP')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Odp $r) => $r->code ?: null),
                Tables\Columns\TextColumn::make('olt.name')
                    ->label('OLT')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pon_port')
                    ->label('Port PON')
                    ->placeholder('-')
                    ->toggleable(),
                // Warna diambil dari SISA port, bukan dari yang terpakai:
                // yang perlu langsung terlihat teknisi adalah "masih muat
                // berapa lagi", bukan "sudah terisi berapa".
                Tables\Columns\TextColumn::make('kapasitas')
                    ->label('Port')
                    ->state(fn (Odp $r) => $r->portTerpakai().' / '.$r->total_ports)
                    ->badge()
                    ->color(fn (Odp $r) => match (true) {
                        $r->sisaPort() <= 0 => 'danger',
                        $r->sisaPort() <= 2 => 'warning',
                        default => 'primary',
                    })
                    ->tooltip(fn (Odp $r) => 'Sisa '.$r->sisaPort().' port'),
                Tables\Columns\TextColumn::make('titik')
                    ->label('Titik')
                    ->state(fn (Odp $r) => $r->latitude !== null && $r->longitude !== null ? 'Ada' : '-')
                    ->formatStateUsing(fn ($state) => $state === 'Ada'
                        ? new HtmlString('<span style="color:#ea580c;font-weight:700;">Ada</span>')
                        : '-')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->onColor('primary'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('olt_id')
                    ->label('OLT')
                    ->options(fn () => Olt::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Tambah ODP')
                    ->modalHeading('Tambah ODP')
                    ->modalWidth('2xl'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->modalHeading('Edit ODP')
                    ->modalWidth('2xl'),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->before(function (Odp $record, Actions\DeleteAction $action) {
                        if ($record->customers()->exists()) {
                            Notification::make()
                                ->title('ODP masih dipakai')
                                ->body('Masih ada pelanggan yang terpasang di ODP ini. Pindahkan dulu pelanggannya, baru ODP ini bisa dihapus.')
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
            'index' => Pages\ListOdps::route('/'),
        ];
    }
}
