$q = \App\Models\Voucher::withoutGlobalScopes()->whereNull('outlet_id');

echo "Total voucher Stok Pusat: ".(clone $q)->count()."\n";
(clone $q)->selectRaw('status, count(*) c')->groupBy('status')->get()
    ->each(fn ($r) => print("  status=".($r->status?->value ?? $r->status)." : {$r->c}\n"));

$ids = (clone $q)->pluck('id')->all();
$lewati = \App\Models\Transaction::withoutGlobalScopes()
    ->whereIn('voucher_id', $ids)->pluck('voucher_id')->unique()->all();
echo "Punya riwayat penjualan (DILEWATI): ".count($lewati)."\n";
echo "Akan terhapus: ".(count($ids) - count($lewati))."\n";

$HAPUS = true;

if ($HAPUS) {
    $n = 0;
    foreach (\App\Models\Voucher::withoutGlobalScopes()->whereIn('id', array_diff($ids, $lewati))->get() as $v) {
        $v->delete();
        $n++;
    }
    echo "Terhapus: {$n}\n";
}
