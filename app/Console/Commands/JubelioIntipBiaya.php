<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use Illuminate\Console\Command;

/**
 * Hanya-baca: tampilkan semua kolom angka / biaya dari detail pesanan Jubelio, untuk mencari
 * rincian potongan marketplace (biaya admin, pajak, affiliate, saldo masuk).
 */
class JubelioIntipBiaya extends Command
{
    protected $signature = 'jubelio:intip-biaya {nomor* : Nomor pesanan (mis. SP-26100707D5HAE4) atau salesorder_id Jubelio} {--json : Simpan detail mentah ke storage/app/jubelio-intip}';

    protected $description = 'Tampilkan kolom biaya/potongan dari detail pesanan Jubelio (tidak mengubah apa pun)';

    private const POLA = '/fee|commission|komisi|affiliate|afiliasi|tax|pajak|escrow|income|seller|disc|voucher|amount|cost|total|admin|service|insurance|asuransi|cashback|coin|settle|payout|net/i';

    public function handle(): int
    {
        $client = new JubelioClient();
        if (!$client->isReady()) {
            $this->error('Jubelio belum dikonfigurasi / login gagal.');

            return self::FAILURE;
        }

        foreach ($this->argument('nomor') as $nomor) {
            $id = ctype_digit($nomor)
                ? (int) $nomor
                : (int) \DB::table('jubelio_order_links')->where('jubelio_salesorder_no', $nomor)->value('jubelio_salesorder_id');
            if (!$id) {
                $this->warn("$nomor: tidak ditemukan di jubelio_order_links.");
                continue;
            }

            $resp = $client->getOrder($id);
            $d = $resp['data'] ?? $resp;
            $this->line("\n=== $nomor (salesorder_id $id) status=" . ($d['status'] ?? $d['internal_status'] ?? '?'));
            foreach ($this->ratakan((array) $d) as $k => $v) {
                if (preg_match(self::POLA, $k) || (is_numeric($v) && !preg_match('/_id$|^id$/', $k))) {
                    $this->line(str_pad($k, 60) . ' = ' . (is_bool($v) ? var_export($v, true) : $v));
                }
            }

            if ($this->option('json')) {
                $path = storage_path("app/jubelio-intip/$id.json");
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("Detail mentah: $path");
            }
        }

        return self::SUCCESS;
    }

    /** ['a' => ['b' => 1]] → ['a.b' => 1] */
    private function ratakan(array $d, string $prefix = ''): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v)) {
                $out += $this->ratakan($v, $key);
            } elseif ($v !== null && $v !== '') {
                $out[$key] = $v;
            }
        }

        return $out;
    }
}
