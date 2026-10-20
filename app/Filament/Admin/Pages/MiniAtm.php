<?php

namespace App\Filament\Admin\Pages;

use App\Models\MiniAtmTransaction;
use App\Services\MiniAtmService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Throwable;

class MiniAtm extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Kasir';

    protected static ?string $navigationLabel = 'Mini ATM';

    protected static ?string $title = 'Mini ATM';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.admin.pages.mini-atm';

    public string $date = '';

    public const BANKS = ['BRI', 'BNI', 'BCA', 'Mandiri', 'BSI', 'CIMB Niaga', 'Permata', 'BTN', 'Danamon', 'Bank Jatim', 'Bank Jateng', 'BPD', 'DANA', 'OVO', 'GoPay', 'ShopeePay', 'LinkAja'];

    public function mount(): void
    {
        $this->date = today()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->txAction('tarik', 'Tarik Tunai', Heroicon::OutlinedArrowDownTray, 'success'),
            $this->txAction('setor', 'Setor Tunai', Heroicon::OutlinedArrowUpTray, 'warning'),
            $this->txAction('transfer', 'Transfer', Heroicon::OutlinedArrowsRightLeft, 'primary'),
            ActionGroup::make([
                $this->moveAction('isi_saldo', 'Isi Saldo Rekening (dari Kas)'),
                $this->moveAction('tarik_saldo', 'Tarik Saldo ke Kas (dari Rekening)'),
                $this->pindahAction(),
                $this->correctionAction(),
                $this->addAccountAction(),
            ])->label('Saldo')->icon(Heroicon::OutlinedWallet)->button()->color('gray'),
        ];
    }

    /** Isian form menurut jenis transaksi (dipakai untuk Tambah maupun Edit). */
    protected function fieldsFor(string $type): array
    {
        if ($type === 'pindah') {
            return [
                $this->accountSelect('account_id', 'Dari Rekening', true),
                $this->accountSelect('to_account_id', 'Ke Rekening', true),
                TextInput::make('amount')->label('Nominal')->numeric()->minValue(1)->required()->prefix('Rp'),
                Textarea::make('notes')->label('Catatan')->rows(2),
            ];
        }

        if ($type === 'koreksi') {
            return [
                $this->accountSelect('account_id', 'Rekening yang dikoreksi'),
                TextInput::make('cash_effect')->label('Saldo Cash (+/−)')->numeric()->default(0)->prefix('Rp')->required(),
                TextInput::make('bank_effect')->label('Saldo Rekening (+/−)')->numeric()->default(0)->prefix('Rp')->required(),
                Textarea::make('notes')->label('Alasan')->rows(2)->required(),
        ];
        }

        if (in_array($type, ['isi_saldo', 'tarik_saldo'], true)) {
            return [
                $this->accountSelect('account_id', $type === 'isi_saldo' ? 'Isi ke Rekening' : 'Tarik dari Rekening'),
                TextInput::make('amount')->label('Nominal')->numeric()->minValue(1)->required()->prefix('Rp'),
                Textarea::make('notes')->label('Catatan')->rows(2),
        ];
        }

        $isTransfer = $type === 'transfer';

        return [
                $this->accountSelect('account_id', $type === 'tarik' ? 'Rekening Anda yang menerima dana' : 'Sumber Rekening Anda'),
                TextInput::make('bank_name')
                    ->label($isTransfer ? 'Bank Tujuan' : 'Bank')
                    ->datalist(self::BANKS)
                    ->required($isTransfer)
                    ->maxLength(60),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('account_number')
                        ->label($isTransfer ? 'No. Rekening Tujuan (opsional)' : ($type === 'setor' ? 'No. Rekening Penerima' : 'No. Kartu / Rekening'))
                        ->maxLength(40),
                    TextInput::make('account_name')
                        ->label($isTransfer ? 'Nama Penerima (opsional)' : 'Nama Pelanggan')
                        ->maxLength(100),
                ]),
                TextInput::make('amount')
                    ->label('Nominal')
                    ->numeric()->minValue(1)->required()->prefix('Rp')->live(onBlur: true),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('fee')
                        ->label('Nominal Jasa')
                        ->numeric()->minValue(0)->default(0)->required()->prefix('Rp')->live(onBlur: true)
                        ->helperText(function (Get $get) use ($type): string {
                            $amount = (float) $get('amount');
                            $fee = (float) $get('fee');
                            $fmt = fn (float $v): string => 'Rp '.number_format($v, 0, ',', '.');

                            return $type === 'tarik'
                                ? 'Uang keluar dari kas: '.$fmt(max($amount - $fee, 0))
                                : 'Uang diterima dari pelanggan: '.$fmt($amount + $fee);
                        }),
                    TextInput::make('bank_fee')
                        ->label('Biaya Admin Bank')
                        ->numeric()->minValue(0)->default(0)->prefix('Rp')
                        ->helperText('Potongan bank ke rekening Anda (opsional)'),
                ]),
                Textarea::make('notes')->label('Catatan')->rows(2),
        ];
    }

    protected function accountSelect(string $name = 'account_id', string $label = 'Sumber Rekening', bool $always = false): Select
    {
        $accounts = app(MiniAtmService::class)->accounts();

        return Select::make($name)
            ->label($label)
            ->options($accounts->pluck('name', 'id')->all())
            ->default($name === 'to_account_id' ? $accounts->skip(1)->first()?->id : $accounts->first()?->id)
            ->required()
            ->native(false)
            ->visible($always || $accounts->count() > 1);
    }

    protected function pindahAction(): Action
    {
        return Action::make('pindah')
            ->label('Pindah Antar Rekening')
            ->modalHeading('Pindah Antar Rekening')
            ->modalWidth(Width::Medium)
            ->schema($this->fieldsFor('pindah'))
            ->action(function (array $data, Action $action): void {
                $this->save($action, 'pindah', $data);
            });
    }

    protected function accountFields(): array
    {
        return [
            TextInput::make('name')->label('Nama Rekening')->placeholder('mis. BRI Utama, BNI Cadangan')->required()->maxLength(60),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('bank_name')->label('Bank')->datalist(self::BANKS)->maxLength(60),
                TextInput::make('account_number')->label('No. Rekening')->maxLength(40),
            ]),
        ];
    }

    protected function addAccountAction(): Action
    {
        return Action::make('addAccount')
            ->label('Tambah Rekening')
            ->modalHeading('Tambah Rekening')
            ->modalWidth(Width::Medium)
            ->schema($this->accountFields())
            ->action(function (array $data, Action $action): void {
                try {
                    $acc = app(MiniAtmService::class)->saveAccount(null, $data);
                    Notification::make()->title('Rekening ditambahkan: '.$acc->name)->body('Isi saldo awalnya lewat Saldo > Koreksi / Saldo Awal.')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    public function editAccountAction(): Action
    {
        return Action::make('editAccount')
            ->modalHeading('Ubah Rekening')
            ->modalWidth(Width::Medium)
            ->fillForm(fn (array $arguments): array => app(MiniAtmService::class)->accounts(false)->firstWhere('id', $arguments['id'] ?? 0)?->only(['name', 'bank_name', 'account_number', 'is_active']) ?? [])
            ->schema([...$this->accountFields(), Toggle::make('is_active')->label('Aktif')->default(true)])
            ->action(function (array $data, array $arguments, Action $action): void {
                try {
                    app(MiniAtmService::class)->saveAccount((int) $arguments['id'], $data);
                    Notification::make()->title('Rekening diperbarui')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    /** @return array<int, array{id: int, name: string, bank: ?string, balance: float}> */
    public function accountCards(): array
    {
        $svc = app(MiniAtmService::class);
        $bal = $svc->accountBalances();

        return $svc->accounts()->map(fn ($a) => [
            'id' => (int) $a->id,
            'name' => $a->name,
            'bank' => trim(($a->bank_name ?? '').' '.($a->account_number ?? '')),
            'balance' => (float) ($bal[$a->id] ?? 0),
        ])->values()->all();
    }

    protected function txAction(string $type, string $label, $icon, string $color): Action
    {
        return Action::make($type)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalHeading($label)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Simpan')
            ->closeModalByClickingAway(false)
            ->schema($this->fieldsFor($type))
            ->action(function (array $data, Action $action) use ($type): void {
                $this->save($action, $type, $data);
            });
    }

    protected function moveAction(string $type, string $label): Action
    {
        return Action::make($type)
            ->label($label)
            ->modalHeading($label)
            ->modalWidth(Width::Medium)
            ->schema($this->fieldsFor($type))
            ->action(function (array $data, Action $action) use ($type): void {
                $this->save($action, $type, $data);
            });
    }

    protected function correctionAction(): Action
    {
        return Action::make('koreksi')
            ->label('Koreksi / Saldo Awal')
            ->modalHeading('Koreksi Saldo')
            ->modalDescription('Isi angka penambahan (+) atau pengurangan (−). Untuk saldo awal, isi saldo yang ada sekarang.')
            ->modalWidth(Width::Medium)
            ->schema($this->fieldsFor('koreksi'))
            ->action(function (array $data, Action $action): void {
                $this->save($action, 'koreksi', $data);
            });
    }

    protected function save(Action $action, string $type, array $data): void
    {
        try {
            $tx = app(MiniAtmService::class)->record([...$data, 'type' => $type]);
        } catch (Throwable $e) {
            Notification::make()->title('Gagal menyimpan')->body($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title('Tersimpan: '.$tx->number)->success()->send();
    }

    /** Aksi Edit (dipanggil dari tombol di riwayat: mountAction('editTx', {id})). */
    public function editTxAction(): Action
    {
        return Action::make('editTx')
            ->modalHeading(fn (array $arguments): string => 'Edit '.(MiniAtmTransaction::find($arguments['id'] ?? 0)?->number ?? 'Transaksi'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Simpan Perubahan')
            ->closeModalByClickingAway(false)
            ->fillForm(function (array $arguments): array {
                $tx = MiniAtmTransaction::find($arguments['id'] ?? 0);

                return $tx ? $tx->only(['account_id', 'to_account_id', 'bank_name', 'account_number', 'account_name', 'amount', 'fee', 'bank_fee', 'cash_effect', 'bank_effect', 'notes']) : [];
            })
            ->schema(function (array $arguments): array {
                $tx = MiniAtmTransaction::find($arguments['id'] ?? 0);

                return $this->fieldsFor($tx?->type ?? 'tarik');
            })
            ->action(function (array $data, array $arguments, Action $action): void {
                try {
                    $tx = app(MiniAtmService::class)->update((int) $arguments['id'], $data);
                } catch (Throwable $e) {
                    Notification::make()->title('Gagal menyimpan')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Diperbarui: '.$tx->number)->success()->send();
            });
    }

    /** Aksi Hapus permanen (mountAction('deleteTx', {id})). */
    public function deleteTxAction(): Action
    {
        return Action::make('deleteTx')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading('Hapus transaksi?')
            ->modalDescription('Transaksi dihapus permanen dan pengaruhnya ke saldo dibatalkan. Untuk menyimpan jejak, gunakan "Batalkan" saja.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->action(function (array $arguments): void {
                try {
                    app(MiniAtmService::class)->delete((int) $arguments['id']);
                    Notification::make()->title('Transaksi dihapus')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Gagal menghapus')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function voidTransaction(int $id): void
    {
        try {
            app(MiniAtmService::class)->void($id);
            Notification::make()->title('Transaksi dibatalkan')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Gagal membatalkan')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array{cash: float, bank: float} */
    public function balances(): array
    {
        return app(MiniAtmService::class)->balances();
    }

    /** @return array{count: int, fee: float, bank_fee: float, profit: float} */
    public function daySummary(): array
    {
        $rows = $this->transactions()->where('is_void', false)->whereIn('type', MiniAtmTransaction::INCOME_TYPES);

        return [
            'count' => $rows->count(),
            'fee' => (float) $rows->sum('fee'),
            'bank_fee' => (float) $rows->sum('bank_fee'),
            'profit' => (float) $rows->sum('fee') - (float) $rows->sum('bank_fee'),
        ];
    }

    /** Pendapatan Mini ATM bulan berjalan (bulan dari tanggal yang dipilih). */
    public function monthSummary(): array
    {
        $d = \Illuminate\Support\Carbon::parse($this->date !== '' ? $this->date : today());

        return app(MiniAtmService::class)->summary($d->copy()->startOfMonth(), $d->copy()->endOfMonth()) + ['label' => $d->translatedFormat('F Y')];
    }

    /** @return Collection<int, MiniAtmTransaction> */
    public function transactions(): Collection
    {
        $date = $this->date !== '' ? $this->date : today()->toDateString();

        return MiniAtmTransaction::query()
            ->with(['user', 'account', 'toAccount'])
            ->whereDate('transacted_at', $date)
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->get();
    }
}
