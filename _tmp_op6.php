<?php
use App\Modules\Production\Models\ProductionOrder;
use App\Core\Journal\Journal;

$o = ProductionOrder::with(['materials.product','outputs.product','sources.product'])->find(6);
echo "OP {$o->order_number} type={$o->type} status={$o->status} wh={$o->warehouse_id}".PHP_EOL;
echo "repair_source_type={$o->repair_source_type} src_id={$o->repair_source_id}".PHP_EOL;

echo PHP_EOL.'MATERIALS:'.PHP_EOL;
foreach ($o->materials as $m) echo "  p={$m->product_id} {$m->product->name} qty_required={$m->qty_required} qty_consumed={$m->qty_consumed}".PHP_EOL;
echo 'OUTPUTS:'.PHP_EOL;
foreach ($o->outputs as $m) echo "  p={$m->product_id} {$m->product->name} type={$m->output_type} qty_planned={$m->qty_planned} qty_produced={$m->qty_produced}".PHP_EOL;
echo 'SOURCES:'.PHP_EOL;
foreach ($o->sources as $m) echo "  p={$m->product_id} {$m->product->name} type={$m->source_type} src_id={$m->source_id} qty={$m->qty}".PHP_EOL;

echo PHP_EOL.'=== Jurnal terkait OP 6 ==='.PHP_EOL;
foreach (Journal::with('lines.account')->where('reference_id',6)->whereIn('reference_type',['production_confirm','production_order_finalize','production_material_addition','production_cost_addition'])->orWhere(function($q){ $q->where('description','like','%OP/2026/04/00001%'); })->get() as $j) {
    echo "  {$j->journal_number} | {$j->reference_type} ref={$j->reference_id} | {$j->description}".PHP_EOL;
    foreach ($j->lines as $l) echo "     {$l->account->code} {$l->account->name} Dr ".number_format($l->debit)." Cr ".number_format($l->credit).PHP_EOL;
}
