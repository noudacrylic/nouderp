<?php

namespace App\Console\Commands;

use App\Modules\Production\Mesin\RuidaKlien;
use App\Modules\Production\Services\PantauMesinService;
use Illuminate\Console\Command;

/**
 * Baca status mesin CNC lewat LAN selama ±55 detik (dijadwalkan tiap menit, tak bertumpuk).
 * Server ERP berada di LAN kantor yang sama dengan mesin. Pembacaan sengaja pelan (±3 detik
 * per putaran) — pengontrol bisa macet bila dibanjiri.
 */
class PantauMesinCnc extends Command
{
    protected $signature = 'cnc:pantau {--detik=55 : lama memantau} {--jeda=3 : detik antar putaran} {--sekali : satu putaran lalu cetak hasil}';

    protected $description = 'Catat keadaan mesin CNC (memotong/diam) dari sinyal pengontrol Ruida';

    public function handle(PantauMesinService $pantau): int
    {
        $mesin = $pantau->mesin();
        if ($mesin->isEmpty()) {
            if ($this->option('sekali')) $this->warn('Belum ada mesin dengan IP sinyal.');
            return self::SUCCESS;
        }
        $klien = $mesin->mapWithKeys(fn ($m) => [$m->id => new RuidaKlien($m->mesin_ip, $m->mesin_protokol ?: 'udp')]);

        $akhir = microtime(true) + (int) $this->option('detik');
        $cobaLagi = []; // mesin tak terjangkau dicoba ulang tiap 30 dtk (tiap percobaan bisa makan ±3 dtk)
        do {
            $mulai = microtime(true);
            foreach ($mesin as $m) {
                if (($cobaLagi[$m->id] ?? 0) > microtime(true)) continue;
                $k = $pantau->amati($m, $klien[$m->id]);
                if ($k === 'putus') $cobaLagi[$m->id] = microtime(true) + 30;
                if ($this->option('sekali')) $this->line("{$m->name} ({$m->mesin_ip} {$m->mesin_protokol}): $k");
            }
            if ($this->option('sekali')) break;
            $tidur = (float) $this->option('jeda') - (microtime(true) - $mulai);
            if ($tidur > 0) usleep((int) ($tidur * 1e6));
        } while (microtime(true) < $akhir);

        foreach ($klien as $k) $k->tutup();

        return self::SUCCESS;
    }
}
