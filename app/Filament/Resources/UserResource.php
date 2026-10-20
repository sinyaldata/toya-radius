<?php

namespace App\Filament\Resources;

use App\Enums\RoleType;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Outlet;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'User';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'User';
    }

    public static function getPluralModelLabel(): string
    {
        return 'User';
    }

    // Hanya Super Admin yang boleh kelola akun login (Super Admin/Outlet/Reseller/Teknisi).
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->prefixIcon('heroicon-o-identification')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('role')
                ->label('Peran')
                ->prefixIcon('heroicon-o-shield-check')
                ->options([
                    RoleType::SuperAdmin->value => RoleType::SuperAdmin->label(),
                    RoleType::Outlet->value => RoleType::Outlet->label(),
                    RoleType::Teknisi->value => RoleType::Teknisi->label(),
                    RoleType::Admin->value => RoleType::Admin->label(),
                ])
                ->required()
                ->live(),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->prefixIcon('heroicon-o-envelope')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),

            Forms\Components\TextInput::make('username')
                ->label('Username')
                ->prefixIcon('heroicon-o-user')
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->maxLength(50),

            Forms\Components\Select::make('outlet_id')
                ->label('Outlet Terkait')
                ->prefixIcon('heroicon-o-building-storefront')
                ->options(fn () => Outlet::pluck('name', 'id'))
                ->visible(fn (callable $get) => $get('role') === RoleType::Outlet->value)
                ->required(fn (callable $get) => $get('role') === RoleType::Outlet->value),

            Forms\Components\TextInput::make('password')
                ->label('Password')
                ->prefixIcon('heroicon-o-lock-closed')
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state)),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif (boleh login)')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')->label('No')->rowIndex(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('username')->label('Username')->placeholder('-')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn (RoleType $state) => $state->label()),
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet')->placeholder('-'),
                // ToggleColumn: status bisa diubah LANGSUNG dari tabel
                // dengan sekali klik, tanpa membuka form Edit sama sekali
                // (permintaan user). Perubahannya langsung disimpan ke
                // database oleh Filament.
                //
                // ->onColor('primary') membuat tombolnya ORANYE, karena
                // warna "primary" panel ini memang oranye.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')->onColor('primary'),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->label('Peran')->options([
                    RoleType::SuperAdmin->value => RoleType::SuperAdmin->label(),
                    RoleType::Outlet->value => RoleType::Outlet->label(),
                    RoleType::Teknisi->value => RoleType::Teknisi->label(),
                    RoleType::Admin->value => RoleType::Admin->label(),
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
                        ->headerActions([
                Actions\CreateAction::make()->label('Tambah User')->modalDescription('Tambahkan user pengelola sistem baru'),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
