<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IpPoolResource\Pages;
use App\Models\IpPool;
use App\Models\Router;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class IpPoolResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = IpPool::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Group User';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'master-data/ip-pool';

    public static function getModelLabel(): string
    {
        return 'Group User';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Group User';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Data Group User')
                ->description('Satu Group User = satu rentang IP yang dibagikan otomatis ke pelanggan Hotspot dan/atau PPPoE, tergantung Tipe Layanan yang dipilih.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Group')
                        ->placeholder('mis. Group Sabah')
                        ->required(),
                    Forms\Components\Select::make('service_type')
                        ->label('Tipe Layanan')
                        ->options([
                            'pppoe' => 'PPPoE',
                            'hotspot' => 'Hotspot',
                            'both' => 'Hotspot & PPPoE',
                        ])
                        ->default('pppoe')
                        ->required(),
                    Forms\Components\Select::make('router_id')
                        ->label('Router (opsional)')
                        ->options(fn () => Router::pluck('name', 'id')),
                    Forms\Components\TextInput::make('range_ip')
                        ->label('Range IP')
                        ->placeholder('10.10.10.2 - 10.10.10.254')
                        ->required()
                        ->rule('regex:/^\s*\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\s*-\s*\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\s*$/')
                        ->validationMessages(['regex' => 'Format harus: IP awal - IP akhir, mis. 10.10.10.2 - 10.10.10.254'])
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, $record) {
                            if ($record) {
                                $component->state($record->start_ip.' - '.$record->end_ip);
                            }
                        })
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('gateway')
                        ->label('Gateway (Local Address router)')
                        ->placeholder('10.10.10.1')
                        ->ip(),
                    Forms\Components\TextInput::make('prefix_length')
                        ->label('Prefix (CIDR)')
                        ->numeric()
                        ->minValue(1)->maxValue(32)
                        ->default(24)
                        ->required()
                        ->suffix('/ 32'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')->label('Nama Group')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Tipe Layanan')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'pppoe' => 'PPPoE',
                        'hotspot' => 'Hotspot',
                        'both' => 'Hotspot & PPPoE',
                        default => '-',
                    }),
                Tables\Columns\TextColumn::make('router.name')->label('Router')->placeholder('-'),
                Tables\Columns\TextColumn::make('range_ip')
                    ->label('Range IP')
                    ->state(fn (IpPool $record) => $record->start_ip.' - '.$record->end_ip),
                Tables\Columns\TextColumn::make('used_addresses')
                    ->label('Terpakai')
                    ->formatStateUsing(fn (IpPool $record) => $record->used_addresses.' / '.$record->total_addresses),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Tambah')->mutateFormDataUsing(fn (array $data) => static::splitRangeIp($data)),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton()->mutateFormDataUsing(fn (array $data) => static::splitRangeIp($data)),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    protected static function splitRangeIp(array $data): array
    {
        if (! empty($data['range_ip'] ?? null)) {
            [$start, $end] = array_map('trim', explode('-', $data['range_ip'], 2));
            $data['start_ip'] = $start;
            $data['end_ip'] = $end;
        }
        unset($data['range_ip']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpPools::route('/'),
        ];
    }
}
