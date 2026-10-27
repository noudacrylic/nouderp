<?php

namespace App\Modules\Production\Services;

use App\Models\ProductionSetting;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\QcCheck;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Models\QcReferenceHistory;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * QC Timbang hasil produksi produk knockdown (17 Okt 2026).
 *
 * Alurnya: pilih OP → produk → timbang per bungkus → banding acuan ± toleransi.
 * Bungkus yang lulus menjadi jatah finalisasi: OP hanya boleh difinalisasi (sebagian
 * maupun penuh) sebanyak bungkus yang sudah lulus. Bungkus yang gagal menunggu
 * perbaikan, dan finalisasi penutup baru boleh setelah semuanya beres.
 *
 * Hasil lulus/gagal SELALU dihitung di sini dari acuan di server — peramban hanya
 * mengirim angka berat.
 */
class QcService
{
    /** Status OP yang hasilnya boleh ditimbang (sedang/selesai dikerjakan, belum final). */
    public const STATUS_BOLEH_TIMBANG = ['in_progress', 'partial', 'completed', 'pending'];

    /** Tipe OP yang tidak ikut QC timbang: perbaikan/garansi punya alurnya sendiri. */
    private const TIPE_DIKECUALIKAN = ['perbaikan', 'garansi', 'repair'];

    /** @var string[] langkah lain yang dijeda otomatis oleh penimbangan terakhir */
    public array $jedaOtomatis = [];

    // ───────────────────────────── toleransi & acuan ─────────────────────────────

    /** Toleransi bawaan: layar Setting → config. */
    public function toleransiBawaan(): array
    {
        $s = ProductionSetting::first();
        if ($s && $s->qc_tolerance_mode && $s->qc_tolerance_value !== null) {
            return ['mode' => $s->qc_tolerance_mode, 'value' => (float) $s->qc_tolerance_value];
        }

        return config('qc.default_tolerance');
    }

    /** Target berat paket: rata-rata sampel paket utuh, atau Σ komponen + bungkus bila belum disampel. */
    public function target(QcProfile $profile): ?float
    {
        if ($profile->target_gram) {
            return (float) $profile->target_gram;
        }

        $komponen = $profile->components;
        if ($komponen->isEmpty() || $komponen->contains(fn ($k) => !$k->unit_gram)) {
            return null;
        }

        return round($komponen->sum(fn ($k) => $k->qty * $k->unit_gram) + (float) $profile->packaging_gram, 1);
    }

    /** Toleransi dalam gram untuk profil ini. */
    public function toleransiGram(QcProfile $profile, ?float $target = null): float
    {
        $target ??= $this->target($profile) ?? 0;
        $tol = $profile->tolerance_mode && $profile->tolerance_value !== null
            ? ['mode' => $profile->tolerance_mode, 'value' => (float) $profile->tolerance_value]
            : $this->toleransiBawaan();

        $gram = $tol['mode'] === 'gram' ? (float) $tol['value'] : $target * (float) $tol['value'] / 100;

        return round(max(0.1, $gram), 1);
    }

    /**
     * Komponen yang terlalu ringan untuk dideteksi timbangan: lebih ringan dari 2×
     * toleransi berarti kehilangannya bisa tenggelam di variasi tebal lembaran.
     */
    public function komponenTakTerdeteksi(QcProfile $profile): Collection
    {
        $tol = $this->toleransiGram($profile);

        return $profile->components->filter(fn ($k) => $k->unit_gram && $k->unit_gram < 2 * $tol)->values();
    }

    // ─────────────────────────────── analisa ───────────────────────────────

    /**
     * Banding berat aktual dengan acuan.
     *
     * @return array{result:string,target:float,tolerance:float,diff:float,kandidat:array,pesan:string}
     */
    public function analisa(QcProfile $profile, float $aktual): array
    {
        $target = $this->target($profile);
        if ($target === null) {
            throw new Exception('Profil QC ini belum punya berat acuan — ambil sampel dulu di Sampel Acuan.');
        }

        $tol  = $this->toleransiGram($profile, $target);
        $diff = round($aktual - $target, 1);

        if (abs($diff) <= $tol) {
            return ['result' => QcCheck::LULUS, 'target' => $target, 'tolerance' => $tol, 'diff' => $diff,
                    'kandidat' => [], 'pesan' => 'Berat sesuai acuan.'];
        }

        $result   = $diff < 0 ? QcCheck::KURANG : QcCheck::LEBIH;
        $kandidat = $this->kandidatKomponen($profile, abs($diff), $tol, $result === QcCheck::KURANG);

        $arah  = $result === QcCheck::KURANG ? 'Kurang' : 'Kelebihan';
        $pesan = $kandidat
            ? $arah . ' ' . $kandidat[0]['label'] . (count($kandidat) > 1 ? ' (atau kemungkinan lain di bawah)' : '')
            : $arah . ' ' . number_format(abs($diff), 1, ',', '.') . ' g — tidak cocok dengan kombinasi komponen, cek manual.';

        return ['result' => $result, 'target' => $target, 'tolerance' => $tol, 'diff' => $diff,
                'kandidat' => $kandidat, 'pesan' => $pesan];
    }

    /**
     * Kombinasi komponen (≤ N keping) yang beratnya paling cocok dengan selisih.
     * Komponen yang beratnya nyaris sama digabung jadi "A atau B" — timbangan memang
     * tidak bisa membedakannya.
     */
    private function kandidatKomponen(QcProfile $profile, float $selisih, float $tol, bool $hilang): array
    {
        $komponen = $profile->components->filter(fn ($k) => $k->unit_gram > 0)->values();
        if ($komponen->isEmpty()) {
            return [];
        }

        $maks = (int) config('qc.analisa_maks_keping', 3);
        $hasil = [];

        $jelajah = function (int $i, array $hitung, int $keping, float $berat) use (&$jelajah, $komponen, $maks, $hilang, $selisih, $tol, &$hasil) {
            if ($i === $komponen->count()) {
                if ($keping > 0 && abs($berat - $selisih) <= $tol) {
                    $hasil[] = ['hitung' => $hitung, 'gram' => round($berat, 1), 'keping' => $keping,
                                'galat' => round(abs($berat - $selisih), 1)];
                }
                return;
            }
            $k = $komponen[$i];
            $batas = $hilang ? min($k->qty, $maks - $keping) : $maks - $keping;
            for ($n = 0; $n <= $batas; $n++) {
                $jelajah($i + 1, $n ? $hitung + [$i => $n] : $hitung, $keping + $n, $berat + $n * $k->unit_gram);
            }
        };
        $jelajah(0, [], 0, 0.0);

        usort($hasil, fn ($a, $b) => [$a['galat'], $a['keping']] <=> [$b['galat'], $b['keping']]);

        // Gabung kombinasi berberat setara (selisih ≤ ½ toleransi) dengan jumlah keping sama.
        $grup = [];
        foreach ($hasil as $h) {
            $label = collect($h['hitung'])->map(fn ($n, $i) => $n . '× ' . $komponen[$i]->name)->implode(' + ');
            foreach ($grup as &$g) {
                if ($g['keping'] === $h['keping'] && abs($g['gram'] - $h['gram']) <= $tol / 2) {
                    $g['pilihan'][] = $label;
                    continue 2;
                }
            }
            unset($g);
            $grup[] = ['gram' => $h['gram'], 'keping' => $h['keping'], 'galat' => $h['galat'], 'pilihan' => [$label]];
        }

        return collect($grup)->take(3)->map(fn ($g) => [
            'label'  => implode(' atau ', $g['pilihan']),
            'gram'   => $g['gram'],
            'galat'  => $g['galat'],
            'ambigu' => count($g['pilihan']) > 1,
        ])->all();
    }

    // ──────────────────────────────── timbang ────────────────────────────────

    public function profilAktif(int $productId): ?QcProfile
    {
        return QcProfile::with('components')->where('product_id', $productId)->where('is_active', true)->first();
    }

    /** OP & produk ini wajib QC? Profil aktif + OP dibuat setelah profilnya ada (OP lama dibebaskan). */
    public function wajibQc(ProductionOrder $order, int $productId): bool
    {
        if (in_array($order->type, self::TIPE_DIKECUALIKAN, true)) {
            return false;
        }

        $profil = QcProfile::where('product_id', $productId)->where('is_active', true)->first();

        return $profil && $order->created_at && $order->created_at->gte($profil->created_at);
    }

    // ───────────────────────────── langkah QC di OP ─────────────────────────────

    /** Ada hasil OP ini yang wajib QC? */
    public function perluQc(ProductionOrder $order): bool
    {
        return $order->outputs()->pluck('product_id')->unique()
            ->contains(fn ($pid) => $this->wajibQc($order, (int) $pid));
    }

    public function langkahQc(ProductionOrder $order): ?ProductionOrderStep
    {
        return $order->steps()->where('is_qc', true)->first();
    }

    /**
     * Tambahkan langkah "QC Timbang" di ujung OP bila hasilnya wajib QC. Pelaksananya divisi
     * di Setting QC; kosong = divisi langkah sebelumnya (sementara QC dikerjakan Assembling).
     */
    public function pastikanLangkahQc(ProductionOrder $order): ?ProductionOrderStep
    {
        if ($ada = $this->langkahQc($order)) {
            return $ada;
        }

        $terakhir = $order->steps()->orderByDesc('step_number')->first();
        if (!$terakhir || !$this->perluQc($order)) {
            return null;
        }

        $deptId = ProductionSetting::first()?->qc_department_id ?: $terakhir->department_id;

        return ProductionOrderStep::create([
            'production_order_id' => $order->id,
            'step_number'         => (int) $terakhir->step_number + 1,
            'department_id'       => $deptId,
            'is_qc'               => true,
            'name'                => 'QC Timbang',
            'status'              => 'pending',
        ]);
    }

    /**
     * Syarat menutup langkah QC: langkah produksi sebelumnya selesai, tak ada bungkus yang
     * menunggu perbaikan, dan qty penutup tiap produk tidak melebihi bungkus yang lulus.
     *
     * @param array $outputs [{output_id, qty_produced}, ...] dari modal Selesai
     */
    public function assertBolehSelesaiQc(ProductionOrder $order, array $outputs): void
    {
        $belum = $order->steps()->where('is_qc', false)->where('status', '!=', 'completed')->orderBy('step_number')->first();
        if ($belum) {
            throw new Exception("Langkah {$belum->step_number} ({$belum->name}) belum selesai — QC baru bisa ditutup setelah produksinya selesai.");
        }

        $rows = [];
        foreach ($outputs as $out) {
            $rec = $order->outputs()->find($out['output_id'] ?? null);
            if ($rec) {
                $rows[] = ['rec' => $rec, 'qty' => (float) ($out['qty_produced'] ?? 0)];
            }
        }
        // Produk QC yang tidak dikirim modal tetap harus bebas dari antrean perbaikan.
        foreach ($order->outputs as $rec) {
            if (!collect($rows)->contains(fn ($r) => $r['rec']->id === $rec->id)) {
                $rows[] = ['rec' => $rec, 'qty' => 0];
            }
        }

        $this->assertBolehFinalisasi($order, $rows, true);
    }

    /**
     * Catat satu penimbangan bungkus.
     *
     * Mode 'perbaikan': bungkus yang tadi gagal ditimbang ulang setelah dilengkapi.
     * Lulus → menutup satu catatan gagal tertua; gagal → hanya dicatat, tidak menambah
     * antrean perbaikan (bungkusnya sama).
     *
     * Penimbangan menjalankan timer langkah QC (lihat ProductionOrderService::runQcTimer);
     * keterangan langkah lain yang ikut dijeda ditaruh di $this->jedaOtomatis.
     */
    public function catatTimbang(int $orderId, int $productId, float $aktual, string $mode, string $source, ?string $raw, ?int $userId, array $executorIds = []): QcCheck
    {
        return DB::transaction(function () use ($orderId, $productId, $aktual, $mode, $source, $raw, $userId, $executorIds) {
            $order = ProductionOrder::with('outputs')->lockForUpdate()->findOrFail($orderId);

            if (!in_array($order->status, self::STATUS_BOLEH_TIMBANG, true)) {
                throw new Exception("Order {$order->order_number} tidak sedang berjalan — tidak bisa di-QC.");
            }
            if (!$order->outputs->contains('product_id', $productId)) {
                throw new Exception('Produk ini bukan hasil order produksi tersebut.');
            }

            $profil = $this->profilAktif($productId);
            if (!$profil) {
                throw new Exception('Produk ini belum punya profil QC aktif.');
            }

            $menunggu = null;
            if ($mode === 'perbaikan') {
                $menunggu = QcCheck::where('production_order_id', $orderId)->where('product_id', $productId)
                    ->where('repair_status', 'menunggu')->orderBy('id')->lockForUpdate()->first();
                if (!$menunggu) {
                    throw new Exception('Tidak ada bungkus yang menunggu perbaikan untuk produk ini.');
                }
            }

            $a = $this->analisa($profil, $aktual);
            $lulus = $a['result'] === QcCheck::LULUS;

            $check = QcCheck::create([
                'production_order_id' => $orderId,
                'product_id'          => $productId,
                'qc_profile_id'       => $profil->id,
                'mode'                => $mode === 'perbaikan' ? 'perbaikan' : 'normal',
                'qty'                 => 1,
                'target_gram'         => $a['target'],
                'tolerance_gram'      => $a['tolerance'],
                'actual_gram'         => round($aktual, 1),
                'diff_gram'           => $a['diff'],
                'result'              => $a['result'],
                'analysis'            => ['pesan' => $a['pesan'], 'kandidat' => $a['kandidat']],
                'repair_status'       => (!$lulus && $mode !== 'perbaikan') ? 'menunggu' : null,
                'source'              => in_array($source, ['timbangan', 'simulasi', 'manual'], true) ? $source : 'manual',
                'raw_frame'           => $raw ? mb_substr($raw, 0, 2000) : null,
                'user_id'             => $userId,
            ]);

            if ($menunggu && $lulus) {
                $menunggu->update(['repair_status' => 'diperbaiki', 'repaired_by_check_id' => $check->id]);
            }

            $langkah = $this->pastikanLangkahQc($order);
            $this->jedaOtomatis = $langkah
                ? app(ProductionOrderService::class)->runQcTimer($langkah->id, $executorIds)
                : [];

            return $check;
        });
    }

    /** Pelepasan QC oleh admin untuk keadaan darurat (mis. timbangan rusak). Alasan wajib. */
    public function lepas(int $orderId, int $productId, int $qty, string $alasan, ?int $userId): QcCheck
    {
        $order = ProductionOrder::with('outputs')->findOrFail($orderId);
        if (!$order->outputs->contains('product_id', $productId)) {
            throw new Exception('Produk ini bukan hasil order produksi tersebut.');
        }
        if ($qty < 1) {
            throw new Exception('Jumlah yang dilepas minimal 1.');
        }

        return QcCheck::create([
            'production_order_id' => $orderId,
            'product_id'          => $productId,
            'qc_profile_id'       => $this->profilAktif($productId)?->id,
            'mode'                => 'lepas',
            'qty'                 => $qty,
            'result'              => QcCheck::DILEPAS,
            'source'              => 'manual',
            'reason'              => $alasan,
            'user_id'             => $userId,
        ]);
    }

    /**
     * Ringkasan QC satu produk dalam satu OP.
     *
     * @return array{target:float,ditimbang:int,lulus:int,menunggu:int,difinalisasi:float,boleh:float}
     */
    public function ringkasan(ProductionOrder $order, int $productId): array
    {
        $checks = QcCheck::where('production_order_id', $order->id)->where('product_id', $productId)->get();

        $lulus = (int) $checks->filter->lulus()->sum('qty');

        $difinalisasi = (float) DB::table('production_finalization_items as i')
            ->join('production_finalizations as f', 'f.id', '=', 'i.production_finalization_id')
            ->where('f.production_order_id', $order->id)
            ->whereNull('f.voided_at')
            ->where('i.product_id', $productId)
            ->sum('i.qty');

        return [
            'target'       => (float) $order->outputs->where('product_id', $productId)->sum('qty_planned'),
            'ditimbang'    => $checks->where('mode', 'normal')->count(),
            'lulus'        => $lulus,
            'menunggu'     => $checks->where('repair_status', 'menunggu')->count(),
            'difinalisasi' => $difinalisasi,
            'boleh'        => max(0.0, $lulus - $difinalisasi),
        ];
    }

    /**
     * Kunci finalisasi — dipanggil releaseOutputs() untuk finalisasi sebagian & penutup.
     * Produk tanpa profil QC (atau OP lama) tidak tersentuh.
     *
     * @param array $rows baris output yang akan dilepas: ['rec' => ProductionOrderOutput, 'qty' => float, ...]
     */
    public function assertBolehFinalisasi(ProductionOrder $order, array $rows, bool $closing): void
    {
        $perProduk = [];
        foreach ($rows as $row) {
            $pid = (int) $row['rec']->product_id;
            $perProduk[$pid] = ($perProduk[$pid] ?? 0) + (float) $row['qty'];
        }

        foreach ($perProduk as $pid => $diminta) {
            if (!$this->wajibQc($order, $pid)) {
                continue;
            }

            $r   = $this->ringkasan($order, $pid);
            $sku = optional(\App\Core\Inventory\Product::find($pid))->sku;

            if ($diminta > $r['boleh'] + 1e-9) {
                throw new Exception(
                    "QC: baru " . $this->angka($r['boleh']) . " bungkus {$sku} yang lulus QC dan belum difinalisasi, "
                    . "diminta " . $this->angka($diminta) . ". Timbang dulu di menu Produksi → QC."
                );
            }

            if ($closing && $r['menunggu'] > 0) {
                throw new Exception(
                    "QC: masih ada {$r['menunggu']} bungkus {$sku} yang menunggu perbaikan. "
                    . "Timbang ulang setelah diperbaiki (mode Perbaikan) sebelum order ditutup."
                );
            }
        }
    }

    // ──────────────────────────────── profil ────────────────────────────────

    /**
     * Simpan profil + komponen. Bila acuan lama sudah pernah disampel dan beratnya berubah,
     * acuan lama diarsipkan dulu ("ambil ulang acuan", mis. ganti batch lembaran).
     */
    public function simpanProfil(array $data, ?int $userId, ?QcProfile $profil = null): QcProfile
    {
        return DB::transaction(function () use ($data, $userId, $profil) {
            $sampel = array_values(array_filter(array_map('floatval', (array) ($data['samples'] ?? [])), fn ($v) => $v > 0));

            $komponen = collect($data['components'] ?? [])
                ->filter(fn ($k) => trim((string) ($k['name'] ?? '')) !== '')
                ->values()
                ->map(function ($k, $i) {
                    $s = array_values(array_filter(array_map('floatval', (array) ($k['samples'] ?? [])), fn ($v) => $v > 0));
                    $unit = $s ? round(array_sum($s) / count($s), 1) : (isset($k['unit_gram']) && $k['unit_gram'] !== '' ? round((float) $k['unit_gram'], 1) : null);
                    return ['name' => mb_substr(trim($k['name']), 0, 100), 'qty' => max(1, (int) ($k['qty'] ?? 1)),
                            'unit_gram' => $unit, 'samples' => $s ?: null, 'sort_order' => $i];
                });

            $atribut = [
                'packaging_gram'  => round((float) ($data['packaging_gram'] ?? 0), 1),
                'target_gram'     => $sampel ? round(array_sum($sampel) / count($sampel), 1) : null,
                'samples'         => $sampel ?: null,
                'tolerance_mode'  => in_array($data['tolerance_mode'] ?? null, ['gram', 'persen'], true) ? $data['tolerance_mode'] : null,
                'tolerance_value' => isset($data['tolerance_value']) && $data['tolerance_value'] !== '' ? (float) $data['tolerance_value'] : null,
                'batch_note'      => $data['batch_note'] ?? null,
                'is_active'       => (bool) ($data['is_active'] ?? true),
            ];

            if ($profil) {
                $profil->load('components');
                $beratBerubah = $profil->sampled_at && (
                    (float) $profil->target_gram !== (float) $atribut['target_gram']
                    || $profil->components->pluck('unit_gram')->map(fn ($v) => (float) $v)->all()
                        !== $komponen->pluck('unit_gram')->map(fn ($v) => (float) $v)->all()
                );
                if ($beratBerubah) {
                    QcReferenceHistory::create([
                        'qc_profile_id' => $profil->id,
                        'snapshot'      => [
                            'target_gram'    => $profil->target_gram,
                            'samples'        => $profil->samples,
                            'packaging_gram' => $profil->packaging_gram,
                            'batch_note'     => $profil->batch_note,
                            'sampled_at'     => optional($profil->sampled_at)->toDateTimeString(),
                            'components'     => $profil->components->map->only(['name', 'qty', 'unit_gram', 'samples'])->all(),
                        ],
                        'reason'  => $data['batch_note'] ?? null,
                        'user_id' => $userId,
                    ]);
                }
                $profil->update($atribut);
                $profil->components()->delete();
            } else {
                $profil = QcProfile::create($atribut + ['product_id' => (int) $data['product_id']]);
            }

            foreach ($komponen as $k) {
                $profil->components()->create($k);
            }

            $adaSampel = $sampel || $komponen->contains(fn ($k) => $k['samples']);
            if ($adaSampel) {
                $profil->update(['sampled_at' => now(), 'sampled_by' => $userId]);
            }

            return $profil->fresh('components');
        });
    }

    private function angka(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
