<?php

namespace App\Filament\Resources;

use App\Enums\RadiusUserType;
use App\Filament\Resources\RadiusSessionLogResource\Pages;
use App\Models\RadiusSessionLog;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class RadiusSessionLogResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = RadiusSessionLog::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Log';

    protected static ?string $navigationLabel = 'Log Koneksi User';

    protected static ?int $navigationSort = 102;

    protected static ?string $slug = 'log/koneksi';

    public static function getModelLabel(): string
    {
        return 'Log Koneksi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Log Koneksi User';
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
                Tables\Columns\TextColumn::make('event_at')->label('Waktu')->dateTime('d M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Peristiwa')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'login' ? 'Login' : 'Logout')
                    ->color(fn (string $state) => $state === 'login' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('username')->label('Username')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('user_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (RadiusUserType $state) => $state->label())
                    ->color(fn (RadiusUserType $state) => $state->color()),
                Tables\Columns\TextColumn::make('framedipaddress')->label('IP')->placeholder('-'),
                Tables\Columns\TextColumn::make('callingstationid')->label('MAC')->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('session_time')
                    ->label('Durasi')
                    ->formatStateUsing(fn ($state) => $state ? gmdate('H:i:s', (int) $state) : '-'),
                Tables\Columns\TextColumn::make('download_mb')->label('Download')->suffix(' MB')->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('upload_mb')->label('Upload')->suffix(' MB')->placeholder('-')->toggleable(),
                Tables\Columns\TextColumn::make('terminate_cause')->label('Sebab Putus')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_type')->label('Jenis User')->options(RadiusUserType::options()),
                Tables\Filters\SelectFilter::make('event')->label('Peristiwa')->options([
                    'login' => 'Login',
                    'logout' => 'Logout',
                ]),
                Tables\Filters\Filter::make('event_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('event_at', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('event_at', '<=', $d));
                    }),
            ])
            ->defaultSort('event_at', 'desc')
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRadiusSessionLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
