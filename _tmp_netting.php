<?php
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;

// Kumpulkan semua jurnal yang menyangkut garansi 1 / OP 6 (lewat referensi & deskripsi)
$refs = [
    ['warranty_post',1],['warranty_delivery',371],['warranty_fee',371],['warranty_correction',1],
    ['production_order_confirm',6],['production_order_finalize',6],
    ['production_material_addition',3],['production_material_addition',6],
];
$journalIds = collect();
foreach ($refs as [$rt,$rid]) {
    $journalIds = $journalIds->merge(Journal::where('reference_type',$rt)->where('reference_id',$rid)->where('status','!=','void')->pluck('id'));
}
// plus deskripsi mengandung OP/2026/04/00001 atau GR/2026/04/00001
$journalIds = $journalIds->merge(
    Journal::where('status','!=','void')
        ->where(fn($q)=>$q->where('description','like','%OP/2026/04/00001%')->orWhere('description','like','%GR/2026/04/00001%'))
        ->pluck('id')
)->unique();

echo 'Jumlah jurnal terkait: '.$journalIds->count().PHP_EOL.PHP_EOL;

$sum = [];
foreach (JournalLine::with('account')->whereIn('journal_id',$journalIds->all())->get() as $l) {
    $code = $l->account->code.' '.$l->account->name;
    if (!isset($sum[$code])) $sum[$code] = ['d'=>0,'c'=>0];
    $sum[$code]['d'] += (float)$l->debit;
    $sum[$code]['c'] += (float)$l->credit;
}
ksort($sum);
echo str_pad('AKUN',40)." DEBIT        CREDIT       SALDO".PHP_EOL;
foreach ($sum as $code=>$v) {
    $bal = $v['d']-$v['c'];
    echo str_pad($code,40).str_pad(number_format($v['d']),12).' '.str_pad(number_format($v['c']),12).' '.number_format($bal).PHP_EOL;
}
