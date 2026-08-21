<?php

namespace App\Modules\Analysis\Services;

use App\Core\Journal\JournalLine;
use App\Modules\Analysis\Models\ProductionCostComponent;
use App\Modules\Production\Models\Department;
use App\Modules\SDM\Models\KaryawanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung TARIF BIAYA TETAP per divisi produksi — fondasi HPP.
 *
 *   tarif_per_detik[divisi] = biaya_tetap_bulanan[divisi] / detik_kerja_bulanan[divisi]
 *
 * Kenapa berbasis tarif per divisi, bukan "biaya tetap sehari ÷ kapasitas sehari":
 * secara matematis keduanya sama —
 *   biaya_hari ÷ (jam_hari ÷ waktu_per_unit) = (biaya_hari ÷ jam_hari) × waktu_per_unit
 * — tapi bentuk kedua memaksa asumsi "sehari hanya bikin satu jenis produk", padahal
 * kenyataannya rata-rata 4,77 jenis produk per hari produksi. Bentuk tarif tidak butuh
 * asumsi itu dan langsung memakai keluaran Analisa Waktu Produksi.
 *
 * ── STRUKTUR (pohon tetap, tidak ada pengaturan basis manual) ──────────────
 *
 *   Non Produksi                     ← gaji divisi non-produksi + biaya kantor/gedung
 *   Packing                          ← biaya membungkus & menyiapkan paket
 *   Produksi
 *     ├─ Overhead Produksi           ← overhead pabrik yang tidak melekat ke satu divisi
 *     └─ Divisi Produksi
 *          ├─ CNC                    ← gaji divisi + biaya khusus divisi
 *          └─ Assembling
 *
 * Non Produksi, Packing dan Overhead Produksi adalah POOL: totalnya dibagi ke divisi
 * produksi sesuai porsi jam operasional masing-masing.
 *
 * Packing tetap ikut dibebankan seperti pool lain, yang berbeda hanya cara MENGUKURnya:
 * tarifnya ditampilkan per TRANSAKSI (surat jalan), bukan per jam — lihat
 * ProductionCostComponent::TRANSACTION_GROUPS.
 *
 * ── DUA JENIS JAM PEMBAGI ──────────────────────────────────────────────────
 *
 * Gaji dan biaya non-gaji TIDAK boleh memakai pembagi yang sama:
 *
 *  • GAJI       → jam-orang = jam kerja sebulan × JUMLAH KARYAWAN. Gaji memang naik
 *                 seiring jumlah orang, jadi pembaginya harus ikut naik. Membaginya
 *                 dengan jam operasional membuat tarif gaji divisi berkaryawan banyak
 *                 tampak berkali lipat dari yang sebenarnya.
 *  • NON-GAJI   → jam operasional = lamanya divisi itu buka, TANPA dikali jumlah orang.
 *                 Sewa dan listrik tidak bertambah karena orangnya bertambah.
 *
 * Jam diambil dari jadwal kontrak karyawan (jam pulang − masuk − istirahat), jadi
 * TIDAK termasuk lembur.
 *
 * Sumber angka:
 *  • Gaji     → slip gaji per karyawan (sdm_slip_gaji ⋈ sdm_karyawan.department_id). Tepat, bukan taksiran.
 *  • Akun GL  → journal_lines akun yang dipilih di komponen, dirata-rata per bulan.
 *  • Manual   → nilai per bulan yang diketik sendiri (biaya yang belum punya akun).
 *
 * Service ini sengaja bebas request()/session()/auth() supaya bisa dipanggil dari
 * command/job — filter periode dikirim eksplisit.
 */
class ProductionCostRateService
{
    /** Default jumlah bulan yang dirata-rata, supaya satu bulan aneh tidak melonjakkan tarif. */
    public const DEFAULT_PERIOD_MONTHS = 3;

    /** Rata-rata jumlah minggu per bulan (52 minggu ÷ 12 bulan). */
    protected const WEEKS_PER_MONTH = 52 / 12;

    /** @var array<string,mixed>|null cache hasil build() per instance */
    protected ?array $cache = null;
    protected ?string $cacheKey = null;

    // ==========================================================
    // API PUBLIK
    // ==========================================================

    /**
     * Seluruh hasil perhitungan tarif, sudah berbentuk pohon siap render.
     *
     * @return array{
     *   period: array{from: string, to: string, months: float, pay_periods: int},
     *   groups: array<string,array>,
     *   departments: array<int,array>,
     *   pool_total: float,
     *   produksi_total: float,
     *   grand_total: float,
     *   production_operating_seconds: float,
     *   production_labor_seconds: float,
     *   production_headcount: int,
     *   transactions_per_month: float,
     *   warnings: array<int,string>
     * }
     */
    public function build(array $filters = []): array
    {
        $key = json_encode($filters);
        if ($this->cache !== null && $this->cacheKey === $key) {
            return $this->cache;
        }

        [$from, $to, $months] = $this->resolvePeriod($filters);

        $depts       = Department::where('is_active', 1)->orderBy('name')->get();
        $prodDepts   = $depts->where('type', 'produksi');
        $nonProdIds  = $depts->where('type', '!=', 'produksi')->pluck('id')->all();

        // Hari kerja aktual periode ini — dasar SEMUA jam di halaman ini.
        $workingDays = $this->actualWorkingDaysPerMonth($from, $to);

        $laborHours           = $this->laborHoursPerMonth($workingDays);
        $operating            = $this->operatingSecondsPerMonth($workingDays);
        [$labor, $payPeriods] = $this->laborPerDepartmentPerMonth($from, $to);
        $components           = $this->componentsPerMonth($from, $to, $months);
        $transactions         = $this->transactionsPerMonth($from, $to, $months);

        $warnings = $this->periodWarnings($payPeriods, $months);

        if ($workingDays === null) {
            $warnings[] = 'Hari kerja diperkirakan dari jadwal kontrak (± 26 hari/bulan) karena belum ada '
                . 'slip gaji di rentang ini. Angka jam pembagi baru pasti setelah periode penggajiannya difinalisasi.';
        }

        // DUA angka berbeda, jangan tertukar:
        //
        //  • $factoryOperating — berapa jam PABRIK buka sebulan. Inilah pembagi biaya
        //    tetap: sewa gedung dan iklan tidak ada hubungannya dengan jumlah divisi,
        //    jadi tidak boleh dikali jumlah divisi. Rp/jam-nya berarti "biaya ini
        //    membebani sekian rupiah tiap jam usaha berjalan".
        //
        //  • $prodOperating — jumlah jam seluruh divisi, hanya dipakai sebagai
        //    PENIMBANG saat membagi biaya bersama ke tiap divisi (porsi jam masing-masing).
        //    Bukan angka yang ditampilkan sebagai pembagi.
        $prodOperating   = 0.0;
        $factoryOperating = 0.0;
        foreach ($prodDepts as $d) {
            $sec = (float) ($operating[$d->id] ?? 0);
            $prodOperating   += $sec;
            $factoryOperating = max($factoryOperating, $sec);
        }

        $factoryNote = ($workingDays && $this->hoursPerDay($workingDays))
            ? $this->trimNumber($workingDays) . ' hari × ' . $this->trimNumber($this->hoursPerDay($workingDays)) . ' jam'
            : null;

        $txNote = $transactions > 0
            ? 'rata-rata ' . round($months) . ' bulan'
            : null;

        // ── Grup pool: Non Produksi & Overhead Produksi ────────────────────
        $groups = [];
        foreach (ProductionCostComponent::POOL_GROUPS as $g) {
            $groups[$g] = [
                'label'      => ProductionCostComponent::GROUP_LABELS[$g],
                'components' => $components['groups'][$g] ?? [],
            ];
        }

        // Gaji divisi NON-PRODUKSI (Admin, Packing, dsb) jadi satu baris di grup Non
        // Produksi — orang-orang ini tidak mengerjakan produk tertentu, jadi biayanya
        // ditanggung bersama. Barisnya otomatis dan tidak bisa dihapus: selama masih ada
        // karyawan non-produksi yang digaji, biayanya memang ada.
        $nonProdLabor = 0.0;
        $nonProdNames = [];
        foreach ($depts as $dept) {
            if ($dept->type === 'produksi' || (float) ($labor[$dept->id] ?? 0) <= 0) {
                continue;
            }
            $nonProdLabor  += (float) $labor[$dept->id];
            $nonProdNames[] = $dept->name;
        }

        if ($nonProdLabor > 0) {
            array_unshift($groups['non_produksi']['components'], $this->laborLine(
                'Gaji Karyawan Non Produksi',
                'otomatis dari slip gaji — ' . implode(', ', $nonProdNames),
                $nonProdLabor,
                $this->laborDivisor($nonProdIds, $laborHours),
            ));
        }

        // Baris non-gaji di grup pool dibagi jam operasional produksi: pool memang
        // ditanggung bersama oleh jam produksi, bukan oleh jam orang non-produksi.
        // Kecuali grup bertarif transaksi (Packing) — pembaginya jumlah pengiriman.
        foreach ($groups as $g => $group) {
            $byTransaction = in_array($g, ProductionCostComponent::TRANSACTION_GROUPS, true);

            foreach ($group['components'] as $i => $line) {
                if ($line['source'] === 'gaji') {
                    continue;
                }
                $groups[$g]['components'][$i] = $byTransaction
                    ? $this->withTransactionRate($line, $transactions, $txNote)
                    : $this->withRate($line, $factoryOperating, 'jam operasional pabrik', $factoryNote);
            }
            $groups[$g]['total'] = array_sum(array_column($groups[$g]['components'], 'amount'));
        }

        $poolTotal = array_sum(array_column($groups, 'total'));

        if (($groups['packing']['total'] ?? 0) > 0 && $transactions <= 0) {
            $warnings[] = 'Biaya Packing tidak bisa diukur per transaksi — belum ada surat jalan '
                . 'ter-post di rentang ini. Biayanya tetap dibebankan, hanya tarif per transaksinya kosong.';
        }

        if ($poolTotal > 0 && $factoryOperating <= 0) {
            $warnings[] = 'Biaya bersama sebesar Rp ' . number_format($poolTotal, 0, ',', '.')
                . ' tidak bisa dialokasikan — belum ada divisi produksi yang punya jam kerja.';
        }

        // Beban tetap per jam usaha berjalan — angka manajemen, bukan tarif divisi.
        foreach ($groups as $g => $group) {
            $groups[$g]['allocation'] = in_array($g, ProductionCostComponent::TRANSACTION_GROUPS, true)
                ? [
                    'divisor'       => $transactions,
                    'divisor_unit'  => 'transaksi',
                    'divisor_label' => 'transaksi per bulan',
                    'divisor_note'  => $txNote,
                    'rate'          => $transactions > 0 ? $group['total'] / $transactions : null,
                    'rate_unit'     => 'transaksi',
                ]
                : [
                    'divisor'       => $factoryOperating,
                    'divisor_unit'  => 'jam',
                    'divisor_label' => 'jam operasional pabrik',
                    'divisor_note'  => $factoryNote,
                    'rate'          => $factoryOperating > 0 ? ($group['total'] / $factoryOperating) * 3600 : null,
                    'rate_unit'     => 'jam',
                ];
        }

        // ── Divisi produksi ────────────────────────────────────────────────
        $rows = [];
        foreach ($prodDepts as $dept) {
            $oper     = (float) ($operating[$dept->id] ?? 0);
            $gaji     = (float) ($labor[$dept->id] ?? 0);
            $manHours = $this->laborDivisor([$dept->id], $laborHours);

            $lines = [];
            if ($gaji > 0) {
                $lines[] = $this->laborLine('Gaji Karyawan', 'otomatis dari slip gaji', $gaji, $manHours);
            }
            foreach ($components['departments'][$dept->id] ?? [] as $line) {
                $lines[] = $this->withRate($line, $oper, 'jam operasional divisi', $factoryNote);
            }

            $otherDirect = array_sum(array_column(
                array_filter($lines, fn ($l) => $l['source'] !== 'gaji'),
                'amount'
            ));
            $share = $prodOperating > 0 ? $poolTotal * ($oper / $prodOperating) : 0.0;

            // Dua pembagi berbeda: gaji per jam-orang, biaya lain per jam operasional.
            $laborRate = $manHours['seconds'] > 0 ? $gaji / $manHours['seconds'] : null;
            $otherRate = $oper > 0 ? ($otherDirect + $share) / $oper : null;

            // Tarif LANGSUNG: gaji divisi + biaya khusus divisi, TANPA porsi biaya bersama.
            // Dipakai halaman HPP yang menampilkan Non Produksi/Packing/Overhead sebagai
            // kolom sendiri — memakai $otherRate di sana akan menghitung pool dua kali.
            $directOther = $oper > 0 ? $otherDirect / $oper : null;
            $directRate  = ($laborRate === null && $directOther === null)
                ? null
                : (float) $laborRate + (float) $directOther;

            $rows[$dept->id] = [
                'department'        => ['id' => $dept->id, 'code' => $dept->code, 'name' => $dept->name],
                'components'        => $lines,
                'labor'             => $gaji,
                'other_direct'      => $otherDirect,
                'direct_total'      => $gaji + $otherDirect,
                'general_share'     => $share,
                'total_monthly'     => $gaji + $otherDirect + $share,
                'labor_seconds'     => $manHours['seconds'],
                'labor_headcount'   => $manHours['headcount'],
                'labor_note'        => $manHours['note'],
                'operating_seconds' => $oper,
                'labor_rate_per_second' => $laborRate,
                'other_rate_per_second' => $otherRate,
                'direct_rate_per_second' => $directRate,
                'rate_per_second'   => ($laborRate === null && $otherRate === null)
                    ? null
                    : (float) $laborRate + (float) $otherRate,
            ];

            if ($oper <= 0 && $rows[$dept->id]['total_monthly'] > 0) {
                $warnings[] = "Divisi {$dept->name} punya biaya tapi jam kerjanya 0 — "
                    . 'pastikan karyawannya sudah punya jadwal kerja di menu SDM.';
            }
        }

        foreach ($this->employeesWithoutSchedule() as $name => $deptName) {
            $warnings[] = "{$name} ({$deptName}) belum punya jadwal kerja — gajinya ikut dihitung "
                . 'tapi jamnya tidak, sehingga tarif per jam jadi lebih tinggi dari seharusnya.';
        }

        $deptTotal      = array_sum(array_column($rows, 'direct_total'));
        $produksiTotal  = ($groups['overhead_produksi']['total'] ?? 0) + $deptTotal;

        $this->cacheKey = $key;

        return $this->cache = [
            'period'      => [
                'from'        => $from->toDateString(),
                'to'          => $to->toDateString(),
                'months'      => $months,
                'pay_periods' => $payPeriods,
            ],
            'groups'      => $groups,
            'departments' => $rows,
            'pool_total'      => $poolTotal,
            'produksi_total'  => $produksiTotal,
            // Dijumlah dari pool + biaya langsung divisi, bukan menyebut grup satu per
            // satu — grup pool yang ditambahkan kemudian (mis. Packing) ikut sendirinya.
            'grand_total'     => $poolTotal + $deptTotal,
            // Jam PABRIK buka — pembagi biaya tetap yang ditampilkan.
            'factory_operating_seconds' => $factoryOperating,
            'factory_operating_note'    => $factoryNote,
            // Jumlah jam seluruh divisi — hanya penimbang pembagian, tidak ditampilkan
            // sebagai pembagi supaya tidak dikira "jam pabrik dikali jumlah divisi".
            'production_operating_seconds' => $prodOperating,
            'production_labor_seconds'     => array_sum(array_column($rows, 'labor_seconds')),
            'production_headcount'         => array_sum(array_column($rows, 'labor_headcount')),
            // Pembagi grup bertarif transaksi (Packing).
            'transactions_per_month' => $transactions,
            // Dasar jam: berapa hari kerja sebulan & dari mana angkanya.
            'working_days'        => $workingDays,
            'working_days_actual' => $workingDays !== null,
            'hours_per_day'       => $this->hoursPerDay($workingDays),
            'warnings'    => $warnings,
        ];
    }

    /** SEAM untuk HPP: [department_id => tarif rupiah per detik]. */
    public function ratePerSecondMap(array $filters = []): array
    {
        return collect($this->build($filters)['departments'])
            ->map(fn ($r) => $r['rate_per_second'])
            ->filter(fn ($v) => $v !== null)
            ->all();
    }

    /**
     * SEAM untuk halaman HPP yang merinci Fixed Cost jadi kolom terpisah.
     *
     * Bedanya dengan ratePerSecondMap(): di sana biaya bersama sudah DILEBUR ke tarif
     * tiap divisi, di sini dipisah supaya bisa ditampilkan sebagai kolomnya sendiri.
     * Karena itu tarif divisinya yang dipakai adalah tarif LANGSUNG — kalau memakai
     * tarif gabungan, Non Produksi & Overhead akan terhitung dua kali.
     *
     * Rp/jam pool memakai basis JAM PABRIK BUKA, sama persis dengan angka yang tampil
     * di halaman Fixed Cost. Konsekuensinya produk yang melewati lebih dari satu divisi
     * menyerap biaya bersama lebih dari sekali — itu pilihan yang disengaja supaya
     * angkanya bisa dicocokkan langsung dengan halaman Fixed Cost.
     *
     * @return array{
     *   pool_per_second: array<string,float|null>,
     *   packing_per_transaction: float|null,
     *   direct_per_second: array<int,float|null>,
     *   departments: array<int,array{code: ?string, name: string}>
     * }
     */
    // ==========================================================
    // BARIS KOMPONEN
    // ==========================================================

    /**
     * Baris gaji otomatis. Tidak punya id karena tidak disimpan sebagai komponen —
     * nilainya selalu ikut slip gaji terbaru, jadi tidak bisa diedit maupun dihapus.
     *
     * @param  array{seconds: float, headcount: int, note: ?string}  $manHours
     */
    protected function laborLine(string $name, string $detail, float $amount, array $manHours): array
    {
        return [
            'id'             => null,
            'locked'         => true,
            'name'           => $name,
            'source'         => 'gaji',
            'detail'         => $detail,
            'amount'         => $amount,
            'divisor'        => $manHours['seconds'],
            'divisor_unit'   => 'jam',
            'divisor_label'  => 'jam-orang',
            'divisor_note'   => $manHours['note'],
            'rate'           => $manHours['seconds'] > 0 ? ($amount / $manHours['seconds']) * 3600 : null,
            'rate_unit'      => 'jam',
            'account_id'     => null,
            'percentage'     => 100.0,
            'amount_monthly' => 0.0,
        ];
    }

    /** Lengkapi baris komponen biasa dengan jam pembagi + tarif per jam. */
    protected function withRate(array $line, float $divisorSeconds, string $label, ?string $note = null): array
    {
        return array_merge($line, [
            'divisor'       => $divisorSeconds,
            'divisor_unit'  => 'jam',
            'divisor_label' => $label,
            'divisor_note'  => $note,
            'rate'          => $divisorSeconds > 0 ? ($line['amount'] / $divisorSeconds) * 3600 : null,
            'rate_unit'     => 'jam',
        ]);
    }

    /**
     * Lengkapi baris komponen bertarif TRANSAKSI (Packing) — pembaginya jumlah
     * pengiriman per bulan, bukan detik, jadi tidak ada konversi ×3600.
     */
    protected function withTransactionRate(array $line, float $transactions, ?string $note = null): array
    {
        return array_merge($line, [
            'divisor'       => $transactions,
            'divisor_unit'  => 'transaksi',
            'divisor_label' => 'transaksi per bulan',
            'divisor_note'  => $note,
            'rate'          => $transactions > 0 ? $line['amount'] / $transactions : null,
            'rate_unit'     => 'transaksi',
        ]);
    }

    /**
     * Jam-orang sekelompok divisi + keterangannya.
     *
     * Keterangan sengaja berbentuk "182 jam × 3 orang" supaya pembacanya bisa
     * memverifikasi sendiri dari mana angka pembaginya datang — inilah bedanya dengan
     * jam operasional yang tidak dikali jumlah orang.
     *
     * @param  int[]  $deptIds
     * @param  array<int,array{seconds: float, headcount: int}>  $laborHours
     * @return array{seconds: float, headcount: int, note: ?string}
     */
    protected function laborDivisor(array $deptIds, array $laborHours): array
    {
        $seconds = 0.0;
        $heads   = 0;
        foreach ($deptIds as $id) {
            $seconds += (float) ($laborHours[$id]['seconds'] ?? 0);
            $heads   += (int) ($laborHours[$id]['headcount'] ?? 0);
        }

        // Rata-rata dipakai supaya "jam × orang" persis sama dengan totalnya walau tiap
        // divisi/karyawan punya jadwal berbeda.
        $note = $heads > 0
            ? number_format($seconds / 3600 / $heads, 0, ',', '.') . ' jam × ' . $heads . ' orang'
            : null;

        return ['seconds' => $seconds, 'headcount' => $heads, 'note' => $note];
    }

    /** @return array<int,string> peringatan periode penggajian */
    protected function periodWarnings(int $payPeriods, float $months): array
    {
        if ($payPeriods === 0) {
            return ['Belum ada slip gaji pada periode ini — biaya tenaga kerja belum masuk hitungan HPP.'];
        }

        if ($payPeriods < floor($months)) {
            return ["Gaji dirata-rata dari {$payPeriods} periode penggajian (rentang filter ± "
                . round($months) . ' bulan). Periode yang belum difinalisasi tidak ikut.'];
        }

        return [];
    }

    // ==========================================================
    // JAM KERJA
    // ==========================================================

    /** @return array{0: Carbon, 1: Carbon, 2: float} */
    public function resolvePeriod(array $filters): array
    {
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $from = Carbon::parse($filters['date_from'])->startOfDay();
            $to   = Carbon::parse($filters['date_to'])->endOfDay();
        } else {
            $months = (int) ($filters['months'] ?? self::DEFAULT_PERIOD_MONTHS);
            $to     = Carbon::now()->endOfMonth();
            // startOfMonth DULU baru mundur: mundur dari tanggal 31 meluber (31 Feb
            // tidak ada → lompat ke Maret), sehingga "6 bulan terakhir" hanya menarik
            // 5 bulan bila halaman dibuka di akhir bulan panjang.
            $from   = Carbon::now()->startOfMonth()->subMonths(max(1, $months) - 1);
        }

        // Jumlah bulan sebagai pecahan supaya rata-rata bulanan tetap benar
        // walau rentangnya tidak pas satu bulan penuh.
        //
        // Selisihnya dihitung dari TANGGAL saja: $to sudah endOfDay, jadi
        // membandingkannya utuh menghasilkan 30,99999 hari untuk Juli lalu +1 di sini
        // membuatnya jadi ~32 hari — pembagi kelebihan sehari dan semua rata-rata
        // bulanan tampak ±3% lebih kecil dari seharusnya.
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);

        return [$from, $to, round($days / 30.4375, 4)];
    }

    /**
     * HARI KERJA per bulan yang dipakai sebagai dasar semua jam.
     *
     * Diambil dari slip gaji periode yang dipakai (`hari_kerja_periode` = hari kerja
     * periode itu setelah dipotong tanggal merah), lalu dirata-rata antar periode.
     * Sumbernya sengaja SAMA dengan sumber gaji: kalau gaji memakai Juni aktual,
     * pembagi jamnya juga harus hari kerja Juni aktual.
     *
     * Sebelumnya dipakai 52/12 minggu × hari kerja seminggu — itu hari kerja TEORETIS
     * (6 hari/minggu → 26 hari/bulan) dan mengabaikan tanggal merah, sehingga jam
     * pembagi kelebihan ±8% dan tarif per jam jadi terlalu murah.
     *
     * @return float|null null bila belum ada slip gaji pada rentang ini
     */
    public function actualWorkingDaysPerMonth(Carbon $from, Carbon $to): ?float
    {
        // MAX per periode, bukan rata-rata antar karyawan: karyawan yang baru masuk
        // di tengah bulan punya hari kerja lebih sedikit, tapi yang dicari adalah
        // berapa hari perusahaan beroperasi.
        $perPeriod = DB::table('sdm_slip_gaji as s')
            ->join('sdm_periode_penggajian as p', 'p.id', '=', 's.periode_id')
            ->where('p.status', '!=', 'void')
            ->whereDate('p.start_date', '<=', $to->toDateString())
            ->whereDate('p.end_date', '>=', $from->toDateString())
            ->groupBy('s.periode_id')
            ->selectRaw('MAX(s.hari_kerja_periode) as hari')
            ->pluck('hari')
            ->filter(fn ($v) => (float) $v > 0);

        return $perPeriod->isEmpty() ? null : (float) $perPeriod->avg();
    }

    /**
     * Detik kerja per bulan per karyawan aktif = jam kerja SEHARI menurut jadwal
     * kontraknya × hari kerja sebulan. TIDAK termasuk lembur.
     *
     * @param  float|null  $workingDays  hari kerja aktual; null → pakai kadens jadwalnya
     *                                   sendiri (hari kerja seminggu × 52/12) sebagai cadangan
     * @return array<int,array{department_id: int, seconds: float, seconds_per_day: float, days: float}>
     *         dikunci karyawan_id
     */
    protected function secondsPerEmployee(?float $workingDays = null): array
    {
        $karyawan = DB::table('sdm_karyawan')
            ->whereNotNull('department_id')
            ->where('is_active', 1)
            ->pluck('department_id', 'id');

        if ($karyawan->isEmpty()) {
            return [];
        }

        // Kumpulkan jadwal seminggu: total detik + berapa hari yang benar-benar kerja.
        $weekly = [];
        foreach (KaryawanSchedule::whereIn('karyawan_id', $karyawan->keys())->get() as $sch) {
            $sec = $sch->is_off ? 0 : $this->scheduleSeconds($sch);
            if ($sec <= 0) {
                continue;
            }
            $weekly[$sch->karyawan_id]['seconds'] = ($weekly[$sch->karyawan_id]['seconds'] ?? 0) + $sec;
            $weekly[$sch->karyawan_id]['days']    = ($weekly[$sch->karyawan_id]['days'] ?? 0) + 1;
        }

        $out = [];
        foreach ($karyawan as $id => $deptId) {
            $w = $weekly[(int) $id] ?? null;

            if (!$w) {
                $out[(int) $id] = ['department_id' => (int) $deptId, 'seconds' => 0.0, 'seconds_per_day' => 0.0, 'days' => 0.0];
                continue;
            }

            $perDay = $w['seconds'] / $w['days'];
            $days   = $workingDays ?? ($w['days'] * self::WEEKS_PER_MONTH);

            $out[(int) $id] = [
                'department_id'   => (int) $deptId,
                'seconds'         => $perDay * $days,
                'seconds_per_day' => $perDay,
                'days'            => $days,
            ];
        }

        return $out;
    }

    /**
     * Jam-orang per bulan per divisi + jumlah karyawannya — pembagi GAJI.
     *
     * Jumlah karyawan hanya menghitung yang punya jadwal, supaya keterangan
     * "x jam × n orang" konsisten dengan totalnya. Karyawan tanpa jadwal dilaporkan
     * lewat employeesWithoutSchedule().
     *
     * @return array<int,array{seconds: float, headcount: int}>
     */
    public function laborHoursPerMonth(?float $workingDays = null): array
    {
        $out = [];
        foreach ($this->secondsPerEmployee($workingDays) as $row) {
            if ($row['seconds'] <= 0) {
                continue;
            }
            $d = $row['department_id'];
            $out[$d]['seconds']   = ($out[$d]['seconds'] ?? 0) + $row['seconds'];
            $out[$d]['headcount'] = ($out[$d]['headcount'] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Detik kerja tersedia per bulan per divisi (jam-orang) — dipakai halaman Kapasitas.
     *
     * @return array<int,float> [department_id => detik/bulan]
     */
    public function availableSecondsPerMonth(array $filters = []): array
    {
        return array_map(
            fn ($r) => $r['seconds'],
            $this->laborHoursPerMonth($this->workingDaysFor($filters))
        );
    }

    /**
     * Detik OPERASIONAL per bulan per divisi — lamanya divisi itu buka, TANPA dikali
     * jumlah karyawan (diambil dari jadwal karyawan terpanjang).
     *
     * Dipakai sebagai pembagi biaya NON-GAJI (sewa gedung, listrik, overhead): biaya
     * semacam itu tidak bertambah karena orangnya bertambah, jadi membaginya dengan
     * jam-orang akan membuat divisi berkaryawan banyak menanggung terlalu besar.
     *
     * @return array<int,float> [department_id => detik/bulan]
     */
    public function operatingSecondsPerMonth(?float $workingDays = null): array
    {
        $perDept = [];
        foreach ($this->secondsPerEmployee($workingDays) as $row) {
            if ($row['seconds'] <= 0) {
                continue;
            }
            $d = $row['department_id'];
            $perDept[$d] = max($perDept[$d] ?? 0, $row['seconds']);
        }

        return $perDept;
    }

    /**
     * Hari kerja per bulan per divisi — dipakai halaman Kapasitas untuk mengubah
     * kapasitas bulanan jadi harian.
     *
     * @return array<int,float>
     */
    public function workingDaysPerMonth(array $filters = []): array
    {
        $days = $this->workingDaysFor($filters);

        $out = [];
        foreach ($this->secondsPerEmployee($days) as $row) {
            if ($row['seconds'] <= 0) {
                continue;
            }
            // Hari kerja divisi = hari kerja SEORANG karyawan (bukan dijumlah semua
            // orang), karena mereka bekerja pada hari yang sama — yang menambah
            // kapasitas adalah jumlah orangnya, dan itu sudah terhitung di detik tersedia.
            $d = $row['department_id'];
            $out[$d] = max($out[$d] ?? 0, $row['days']);
        }

        return $out;
    }

    /** Hari kerja aktual untuk sebuah filter, atau null bila belum ada slip gaji. */
    protected function workingDaysFor(array $filters): ?float
    {
        [$from, $to] = $this->resolvePeriod($filters);

        return $this->actualWorkingDaysPerMonth($from, $to);
    }

    /** Buang nol di belakang koma: 24,0 → "24", 25,5 → "25,5". */
    protected function trimNumber(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }

    /**
     * Jam kerja sehari untuk keterangan "24 hari × 7 jam". Diambil shift terpanjang,
     * sejalan dengan cara jam operasional divisi memakai jadwal karyawan terpanjang.
     */
    protected function hoursPerDay(?float $workingDays): ?float
    {
        $perDay = collect($this->secondsPerEmployee($workingDays))
            ->pluck('seconds_per_day')
            ->filter(fn ($v) => $v > 0);

        return $perDay->isEmpty() ? null : $perDay->max() / 3600;
    }

    /**
     * Karyawan aktif bergaji yang belum punya jadwal — jamnya tidak masuk pembagi.
     *
     * @return array<string,string> [nama karyawan => nama divisi]
     */
    protected function employeesWithoutSchedule(): array
    {
        $idle = collect($this->secondsPerEmployee())
            ->filter(fn ($r) => $r['seconds'] <= 0);

        if ($idle->isEmpty()) {
            return [];
        }

        return DB::table('sdm_karyawan as k')
            ->join('production_departments as d', 'd.id', '=', 'k.department_id')
            ->whereIn('k.id', $idle->keys())
            ->pluck('d.name', 'k.name')
            ->all();
    }

    protected function scheduleSeconds(KaryawanSchedule $sch): float
    {
        $work = $this->timeDiff($sch->jam_masuk, $sch->jam_pulang);
        if ($work <= 0) {
            return 0;
        }

        $break = $this->timeDiff($sch->jam_istirahat_start, $sch->jam_istirahat_end);

        return max(0, $work - max(0, $break));
    }

    protected function timeDiff(?string $start, ?string $end): float
    {
        if (!$start || !$end) {
            return 0;
        }

        $s = Carbon::parse('2000-01-01 ' . $start);
        $e = Carbon::parse('2000-01-01 ' . $end);
        if ($e->lte($s)) {
            $e->addDay(); // shift melewati tengah malam
        }

        return $s->diffInSeconds($e);
    }

    // ==========================================================
    // SUMBER BIAYA
    // ==========================================================

    /**
     * Gaji per divisi per bulan, dari slip gaji karyawan yang periodenya beririsan
     * dengan rentang yang dipilih. Karyawan tanpa divisi diabaikan.
     *
     * Pembaginya adalah jumlah PERIODE PENGGAJIAN yang benar-benar punya slip, bukan
     * panjang rentang filter — kalau tidak, memilih 3 bulan sementara baru 1 bulan yang
     * digaji akan mengecilkan biaya tenaga kerja jadi sepertiganya.
     *
     * @return array{0: array<int,float>, 1: int} [gaji per divisi per bulan, jumlah periode terpakai]
     */
    public function laborPerDepartmentPerMonth(Carbon $from, Carbon $to): array
    {
        $base = DB::table('sdm_slip_gaji as s')
            ->join('sdm_karyawan as k', 'k.id', '=', 's.karyawan_id')
            ->join('sdm_periode_penggajian as p', 'p.id', '=', 's.periode_id')
            ->whereNotNull('k.department_id')
            ->where('p.status', '!=', 'void')
            ->whereDate('p.start_date', '<=', $to->toDateString())
            ->whereDate('p.end_date', '>=', $from->toDateString());

        $periodCount = (int) (clone $base)->distinct()->count('s.periode_id');
        if ($periodCount === 0) {
            return [[], 0];
        }

        $rows = (clone $base)
            ->groupBy('k.department_id')
            ->selectRaw('k.department_id, SUM(s.total_gaji) total')
            ->pluck('total', 'department_id');

        return [$rows->map(fn ($v) => (float) $v / $periodCount)->all(), $periodCount];
    }

    /**
     * Rata-rata jumlah TRANSAKSI per bulan — pembagi biaya Packing.
     *
     * Satu transaksi = satu surat jalan ter-post, karena itulah satuan kerja packing:
     * satu paket dibungkus sekali. Sengaja BUKAN faktur (pesanan sudah dipacking
     * sebelum ditagih) dan bukan sales order (pesanan yang belum dikirim belum
     * memakan kerja packing). Surat jalan void tidak ikut.
     *
     * Dibagi $months dengan cara yang sama seperti komponen akun, supaya "per bulan"
     * di kolom Rp/Bulan dan di pembagi ini berarti hal yang persis sama.
     */
    public function transactionsPerMonth(Carbon $from, Carbon $to, float $months): float
    {
        if ($months <= 0) {
            return 0.0;
        }

        $count = DB::table('sales_deliveries')
            ->where('status', 'posted')
            ->whereDate('delivery_date', '>=', $from->toDateString())
            ->whereDate('delivery_date', '<=', $to->toDateString())
            ->count();

        return $count / $months;
    }

    /**
     * Semua komponen biaya, dinilai per bulan.
     * Komponen sumber 'akun' ditarik dari buku besar lalu dibagi jumlah bulan;
     * sumber 'manual' dipakai apa adanya (memang sudah nilai per bulan).
     *
     * @return array{groups: array<string,array<int,array>>, departments: array<int,array<int,array>>}
     */
    public function componentsPerMonth(Carbon $from, Carbon $to, float $months): array
    {
        $components = ProductionCostComponent::with('account')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $accountIds = $components->where('source', 'akun')->pluck('account_id')->filter()->unique();

        $glTotals = $accountIds->isEmpty() ? collect() : JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->where('journals.status', 'posted')
            ->whereDate('journals.date', '>=', $from->toDateString())
            ->whereDate('journals.date', '<=', $to->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->pluck('net', 'account_id');

        $groups = [];
        $depts  = [];

        foreach ($components as $c) {
            if ($c->source === 'akun') {
                // Saldo kredit/nol bukan beban → dianggap 0, bukan pengurang.
                $net    = max(0, (float) ($glTotals[$c->account_id] ?? 0));
                $amount = $months > 0 ? ($net * ((float) $c->percentage / 100)) / $months : 0.0;
                $detail = $c->account
                    ? $c->account->code . ' — ' . $c->account->name
                        . ((float) $c->percentage < 100 ? ' (' . rtrim(rtrim(number_format((float) $c->percentage, 2, ',', '.'), '0'), ',') . '%)' : '')
                        . ' · rata-rata ' . round($months) . ' bulan'
                    : 'akun tidak ditemukan';
            } else {
                $amount = (float) $c->amount_monthly;
                $detail = $c->notes ?: 'input manual';
            }

            $line = [
                'id'             => $c->id,
                'locked'         => false,
                'name'           => $c->name,
                'source'         => $c->source,
                'detail'         => $detail,
                'amount'         => $amount,
                // Dipakai form ubah supaya tidak perlu query ulang di view.
                'group_key'      => $c->group_key,
                'department_id'  => $c->department_id,
                'account_id'     => $c->account_id,
                'percentage'     => (float) $c->percentage,
                'amount_monthly' => (float) $c->amount_monthly,
                'notes'          => $c->notes,
            ];

            if ($c->group_key === 'divisi' && $c->department_id) {
                $depts[$c->department_id][] = $line;
            } else {
                $groups[$c->group_key][] = $line;
            }
        }

        return ['groups' => $groups, 'departments' => $depts];
    }
}
