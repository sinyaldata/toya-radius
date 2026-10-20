<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoginLogResource\Pages;
use App\Models\LoginLog;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LoginLogResource extends Resource
{
    use \App\Filament\Concerns\ChecksGroupAccess;

    protected static ?string $model = LoginLog::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Log';

    protected static ?string $navigationLabel = 'Log Login Pengguna';

    protected static ?int $navigationSort = 103;

    protected static ?string $slug = 'log/login';

    public static function getModelLabel(): string
    {
        return 'Log Login';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Log Login Pengguna';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Akun OUTLET hanya boleh melihat riwayat login dirinya sendiri —
     * bukan log pengguna/outlet lain.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $user = \Illuminate\Support\Facades\Auth::user();

        if ($user?->isOutlet()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('logged_in_at')->label('Waktu Login')->dateTime('d M Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Nama')->placeholder('-')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('user.role')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->placeholder('-'),
                Tables\Columns\IconColumn::make('successful')
                    ->label('Berhasil')
                    ->boolean(),
                Tables\Columns\TextColumn::make('logged_out_at')
                    ->label('Waktu Logout')
                    ->dateTime('d M Y H:i:s')
                    ->placeholder('Masih aktif / tidak tercatat'),
                Tables\Columns\TextColumn::make('ip_address')->label('IP')->placeholder('-'),
                Tables\Columns\TextColumn::make('user_agent')->label('Perangkat')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('successful')->label('Status Login'),
                Tables\Filters\Filter::make('logged_in_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('logged_in_at', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('logged_in_at', '<=', $d));
                    }),
            ])
            ->defaultSort('logged_in_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoginLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
