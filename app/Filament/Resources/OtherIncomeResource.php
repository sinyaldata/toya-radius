<?php

namespace App\Filament\Resources;

use App\Enums\IncomeCategory;
use App\Enums\PaymentMethod;
use App\Filament\Resources\OtherIncomeResource\Pages;
use App\Models\OtherIncome;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OtherIncomeResource extends Resource
{
    protected static ?string $model = OtherIncome::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Pendapatan Lain';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'keuangan/pendapatan-lain';

    // Data keuangan internal — Outlet/Reseller tidak boleh melihat.
    public static function shouldRegisterNavigation(): bool
    {
        return ! (auth()->user()?->isOutlet() ?? false);
    }

    public static function canViewAny(): bool
    {
        return ! (auth()->user()?->isOutlet() ?? false);
    }

    public static function getModelLabel(): string
    {
        return 'Pendapatan Lain';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pendapatan Lain';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Data Pendapatan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Keterangan')
                        ->placeholder('mis. Biaya pasang baru - Budi')
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\DatePicker::make('income_date')
                        ->label('Tanggal')
                        ->default(now())
                        ->required(),
                    Forms\Components\Select::make('category')
                        ->label('Kategori')
                        ->options(IncomeCategory::options())
                        ->default('instalasi')
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Jumlah')
                        ->numeric()
                        ->prefix('Rp')
                        ->required(),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metode Bayar')
                        ->options(PaymentMethod::options())
                        ->default('tunai')
                        ->required(),
                    Forms\Components\TextInput::make('source')
                        ->label('Diterima Dari')
                        ->placeholder('nama pelanggan / pihak lain')
                        ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('income_number')->label('No. Bukti')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('income_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (IncomeCategory $state) => $state->label()),
                Tables\Columns\TextColumn::make('title')->label('Keterangan')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('source')->label('Dari')->placeholder('-'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                Tables\Columns\TextColumn::make('creator.name')->label('Dicatat Oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->label('Kategori')->options(IncomeCategory::options()),
                Tables\Filters\Filter::make('income_date')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('income_date', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('income_date', '<=', $d));
                    }),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Tambah'),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton(),
            ])
            ->defaultSort('income_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOtherIncomes::route('/'),
        ];
    }
}
