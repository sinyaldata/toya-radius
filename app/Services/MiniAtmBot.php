<?php

namespace App\Services;

use App\Filament\Admin\Pages\MiniAtm;
use App\Models\MiniAtmTransaction;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Perintah Mini ATM untuk bot Telegram. Dipanggil dari TelegramCommandHandler
 * (chat sudah terverifikasi terhubung ke toko). Mengembalikan teks balasan (HTML).
 *
 *   /tarik 500rb 5rb BRI
 *   /setor 200000 5000 BNI 1234567890 Budi
 *   /transfer 300rb 7rb BCA                (no. rekening & nama BOLEH dikosongkan)
 *   /transfer 300rb 7rb BCA 1234567890 Budi adm2500
 *   /tarik 500rb 5rb BRI rek=BNI_Cadangan   (pilih rekening Anda bila punya lebih dari satu)
 *   /atm  |  /laporanatm [hari|kemarin|minggu|bulan]  |  /batalatm ATM-20260930-0001
 */
class MiniAtmBot
{
    public const COMMANDS = ['/atm', '/saldoatm', '/tarik', '/setor', '/transfer', '/laporanatm', '/batalatm'];

    public function handle(Store $store, string $command, string $arg): string
    {
        $svc = MiniAtmService::forStore((int) $store->id);

        try {
            return match ($command) {
                '/atm', '/saldoatm' => $this->saldo($svc),
                '/tarik', '/setor', '/transfer' => $this->transaksi($svc, ltrim($command, '/'), $arg),
                '/laporanatm' => $this->laporan($svc, $arg),
                '/batalatm' => $this->batal($svc, $arg),
                default => $this->bantuan(),
            };
        } catch (Throwable $e) {
            return '❌ '.$e->getMessage();
        }
    }

    public static function helpLines(): string
    {
        return "/atm — Saldo cash & rekening Mini ATM\n".
            "/tarik NOMINAL JASA [BANK] — Tarik tunai\n".
            "/setor NOMINAL JASA [BANK] [NOREK] [NAMA] — Setor tunai\n".
            "/transfer NOMINAL JASA BANK [NOREK] [NAMA] — Transfer (NOREK & NAMA boleh kosong)\n".
            "Tambahkan rek=NAMA_REKENING bila punya lebih dari satu rekening\n".
            "/laporanatm [hari|kemarin|minggu|bulan] — Pendapatan Mini ATM\n".
            '/batalatm NOMOR — Batalkan transaksi Mini ATM';
    }

    protected function bantuan(): string
    {
        return "🏧 <b>Perintah Mini ATM</b>\n\n".self::helpLines().
            "\n\nContoh:\n/tarik 500rb 5rb BRI\n/transfer 1jt 10rb BCA\n/transfer 300000 7000 BNI 1234567890 Budi\nTambahkan <b>adm2500</b> untuk biaya admin bank.";
    }

    protected function saldo(MiniAtmService $svc): string
    {
        $b = $svc->balances();
        $h = $svc->summary(Carbon::today()->startOfDay(), Carbon::today()->endOfDay());

        $lines = ['🏧 <b>Saldo Mini ATM</b>', '<b>Total: '.$this->rp($b['total']).'</b>', 'Uang Cash: '.$this->rp($b['cash']), 'Rekening: '.$this->rp($b['bank'])];

        $accounts = $svc->accounts();
        if ($accounts->count() > 1) {
            foreach ($accounts as $a) {
                $lines[] = '  • '.$a->name.': '.$this->rp($b['accounts'][$a->id] ?? 0);
            }
        }

        $lines[] = '';
        $lines[] = "Hari ini: {$h['count']} transaksi, pendapatan bersih ".$this->rp($h['profit']);

        return implode("\n", $lines);
    }

    protected function transaksi(MiniAtmService $svc, string $type, string $arg): string
    {
        $tokens = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $amount = isset($tokens[0]) ? $this->money($tokens[0]) : null;
        if ($amount === null || $amount <= 0) {
            return "Format: /{$type} NOMINAL JASA [BANK]".($type === 'transfer' ? ' [NOREK] [NAMA]' : '')."\nContoh: /{$type} 500rb 5rb BRI";
        }
        array_shift($tokens);

        // Jasa = angka kedua (bila ada). Biaya admin bank: adm2500 / adm=2500.
        $fee = 0.0;
        if (isset($tokens[0]) && ! preg_match('/^adm/i', $tokens[0]) && ($m = $this->money($tokens[0])) !== null && strlen(preg_replace('/\D/', '', $tokens[0])) < 9) {
            $fee = $m;
            array_shift($tokens);
        }

        $bankFee = 0.0;
        $accountId = null;
        $bank = null;
        $account = null;
        $nameParts = [];

        foreach ($tokens as $t) {
            if (preg_match('/^rek[=:](.+)$/i', $t, $m)) {
                $acc = $svc->findAccountByName(str_replace('_', ' ', $m[1]));
                if (! $acc) {
                    return 'Rekening "'.str_replace('_', ' ', $m[1]).'" tidak ditemukan. Rekening tersedia: '.$svc->accounts()->pluck('name')->implode(', ');
                }
                $accountId = (int) $acc->id;
            } elseif (preg_match('/^adm=?(.+)$/i', $t, $m) && ($v = $this->money($m[1])) !== null) {
                $bankFee = $v;
            } elseif (preg_match('/^(jasa|fee)=?(.+)$/i', $t, $m) && ($v = $this->money($m[2])) !== null) {
                $fee = $v;
            } elseif ($account === null && preg_match('/^\d{6,}$/', $t)) {
                $account = $t;
            } elseif ($bank === null && ! preg_match('/^\d+$/', $t)) {
                $bank = $this->bankName($t);
            } else {
                $nameParts[] = $t;
            }
        }

        if ($type === 'transfer' && $bank === null) {
            return "Sebutkan bank tujuan.\nContoh: /transfer {$this->plain($amount)} {$this->plain($fee > 0 ? $fee : 5000)} BCA\n(No. rekening & nama boleh dikosongkan)";
        }

        $tx = $svc->record([
            'type' => $type,
            'account_id' => $accountId,
            'bank_name' => $bank,
            'account_number' => $account,
            'account_name' => $nameParts ? implode(' ', $nameParts) : null,
            'amount' => $amount,
            'fee' => $fee,
            'bank_fee' => $bankFee,
            'notes' => 'via Telegram',
        ]);

        $b = $svc->balances();
        $label = MiniAtmTransaction::TYPES[$type];
        $lines = ["✅ <b>{$label} tercatat</b> — {$tx->number}", 'Nominal: '.$this->rp($amount), 'Jasa: '.$this->rp($fee)];

        if ($bankFee > 0) {
            $lines[] = 'Biaya admin bank: '.$this->rp($bankFee);
        }
        if ($bank) {
            $lines[] = 'Bank: '.$bank.($account ? " · {$account}" : '').($nameParts ? ' · '.implode(' ', $nameParts) : '');
        }

        $lines[] = $type === 'tarik'
            ? 'Serahkan tunai ke pelanggan: '.$this->rp(max($amount - $fee, 0))
            : 'Terima dari pelanggan: '.$this->rp($amount + $fee);
        if ($svc->accounts()->count() > 1) {
            $lines[] = 'Rekening saya: '.($tx->account?->name ?? $svc->accounts()->first()->name);
        }
        $lines[] = '';
        $lines[] = 'Saldo Cash: '.$this->rp($b['cash']).' | Rekening: '.$this->rp($b['bank']).' | Total: '.$this->rp($b['total']);
        $lines[] = "Salah input? /batalatm {$tx->number}";

        return implode("\n", $lines);
    }

    protected function laporan(MiniAtmService $svc, string $arg): string
    {
        $arg = strtolower(trim($arg));

        [$from, $to, $label] = match (true) {
            in_array($arg, ['kemarin', 'yesterday']) => [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay(), 'Kemarin'],
            in_array($arg, ['minggu', 'week']) => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'Minggu Ini'],
            in_array($arg, ['bulan', 'month']) => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'Bulan Ini'],
            default => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay(), 'Hari Ini'],
        };

        $s = $svc->summary($from, $to);
        $lines = ["🏧 <b>Pendapatan Mini ATM — {$label}</b>", "Jumlah transaksi: {$s['count']}", 'Total nominal: '.$this->rp($s['amount'])];

        foreach ($s['by_type'] as $type => $row) {
            $lines[] = '• '.(MiniAtmTransaction::TYPES[$type] ?? $type).": {$row['count']}x, jasa ".$this->rp($row['fee']);
        }

        $lines[] = 'Total jasa: '.$this->rp($s['fee']);
        $lines[] = 'Biaya admin bank: '.$this->rp($s['bank_fee']);
        $lines[] = '<b>Pendapatan bersih: '.$this->rp($s['profit']).'</b>';

        return implode("\n", $lines);
    }

    protected function batal(MiniAtmService $svc, string $arg): string
    {
        if (trim($arg) === '') {
            return 'Format: /batalatm NOMOR (contoh: /batalatm ATM-20260930-0001)';
        }

        $tx = $svc->voidByNumber($arg);
        $b = $svc->balances();

        return "↩️ {$tx->number} dibatalkan. Saldo dikembalikan.\nSaldo Cash: ".$this->rp($b['cash']).' | Rekening: '.$this->rp($b['bank']);
    }

    /** "500rb", "1jt", "1.5jt", "500k", "500.000", "500000", "Rp5000" -> angka. */
    public static function parseMoney(string $s): ?float
    {
        $s = str_replace(['rp', ' '], '', strtolower(trim($s)));

        if (preg_match('/^(\d+(?:[.,]\d+)?)(jt|juta)$/', $s, $m)) {
            return round((float) str_replace(',', '.', $m[1]) * 1_000_000);
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)(rb|ribu|k)$/', $s, $m)) {
            return round((float) str_replace(',', '.', $m[1]) * 1_000);
        }
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) {
            return (float) preg_replace('/[.,]/', '', $s);
        }
        if (preg_match('/^\d+$/', $s)) {
            return (float) $s;
        }

        return null;
    }

    protected function money(string $s): ?float
    {
        return static::parseMoney($s);
    }

    protected function bankName(string $t): string
    {
        foreach (MiniAtm::BANKS as $b) {
            if (strcasecmp($b, $t) === 0) {
                return $b;
            }
        }

        return strtoupper($t) === $t || strlen($t) <= 4 ? strtoupper($t) : ucfirst(strtolower($t));
    }

    protected function rp(float $v): string
    {
        return 'Rp'.number_format($v, 0, ',', '.');
    }

    protected function plain(float $v): string
    {
        return (string) (int) $v;
    }
}
