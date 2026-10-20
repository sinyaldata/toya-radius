<?php

namespace App\Filament\Resources;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Pengeluaran';

    protected static ?int $navigationSort = 2;

    // Data pengeluaran perusahaan (gaji, sewa, dll) khusus internal — Outlet/Reseller
    // TIDAK boleh melihat menu maupun datanya, hanya boleh lihat laporan Penjualan sendiri.
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
        return 'Pengeluaran';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pengeluaran';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Data Pengeluaran')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Keterangan')
                        ->placeholder('mis. Gaji Karyawan Agustus 2026')
                        ->prefixIcon('heroicon-o-document-text')
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\DatePicker::make('expense_date')
                        ->label('Tanggal')
                        ->prefixIcon('heroicon-o-calendar')
                        ->default(now())
                        ->required(),
                    Forms\Components\Select::make('category')
                        ->label('Kategori')
                        ->prefixIcon('heroicon-o-tag')
                        ->options(ExpenseCategory::options())
                        ->required()
                        ->searchable(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Jumlah')
                        ->numeric()
                        ->prefix('Rp')
                        ->required(),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metode Pembayaran')
                        ->prefixIcon('heroicon-o-credit-card')
                        ->options(PaymentMethod::options())
                        ->default('tunai')
                        ->required(),
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
                Tables\Columns\TextColumn::make('expense_number')->label('No.')->searchable(),
                Tables\Columns\TextColumn::make('expense_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseCategory $state) => $state->label())
                    ->color(fn (ExpenseCategory $state) => $state->color()),
                Tables\Columns\TextColumn::make('title')->label('Keterangan')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('recipient')->label('Penerima')->placeholder('-'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Jumlah')
                    ->money('IDR', decimalPlaces: 0)->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('IDR', decimalPlaces: 0)->label('Total')),
                Tables\Columns\TextColumn::make('creator.name')->label('Dicatat Oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(ExpenseCategory::options()),
                Tables\Filters\Filter::make('expense_date')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('expense_date', '<=', $d));
                    }),
            ])
                        ->headerActions([
                Actions\CreateAction::make()->label('Catat Pengeluaran')->modalDescription('Catat pengeluaran baru ke sistem'),
            ])
            ->recordActions([
                Actions\EditAction::make()->iconButton(),
                Actions\DeleteAction::make()->iconButton(),
            ])
            ->defaultSort('expense_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
