<?php
$disp = App\Modules\Sales\Models\SalesAdvance::getEventDispatcher();
echo "Observer 'created' terdaftar? ".($disp->hasListeners("eloquent.created: ".App\Modules\Sales\Models\SalesAdvance::class) ? "YA" : "TIDAK")."\n";
echo "Observer 'updated' terdaftar? ".($disp->hasListeners("eloquent.updated: ".App\Modules\Sales\Models\SalesAdvance::class) ? "YA" : "TIDAK")."\n";
