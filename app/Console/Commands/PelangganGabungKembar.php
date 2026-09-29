<?php

namespace App\Console\Commands;

use App\Services\CustomerMergeService;
use Illuminate\Console\Command;

/**
 * Sapuan pelanggan kembar (nomor HP + nama sama) — cadangan observer, dan
 * pembersih data lama yang kembar sebelum penggabungan otomatis ada.
 *
 * AMAN DIULANG: yang sudah digabung tidak ikut dihitung lagi.
 */
class PelangganGabungKembar extends Command
{
    protected $signature = 'pelanggan:gabung-kembar {--dry-run : Tampilkan saja, jangan gabungkan}';

    protected $description = 'Gabungkan pelanggan kembar bernomor HP & nama sama; yang namanya beda hanya dilaporkan';

    public function handle(CustomerMergeService $merge): int
    {
        $kering = (bool) $this->option('dry-run');

        foreach ($merge->gabungOtomatis($kering) as $g) {
            $this->line(sprintf('%s %s (%s) → %s (%s)',
                $kering ? '[dry-run]' : 'Digabung:',
                $g['dari']->code, $g['dari']->name, $g['ke']->code, $g['ke']->name));
        }

        $cek = $merge->kelompokKembar()->filter(fn ($k) => $k['cek']);
        foreach ($cek as $k) {
            $this->warn("Perlu dicek (nomor sama, nama beda) {$k['nomor']}: "
                . $k['anggota']->map(fn ($c) => "{$c->code} {$c->name}")->implode(' | '));
        }

        return self::SUCCESS;
    }
}
