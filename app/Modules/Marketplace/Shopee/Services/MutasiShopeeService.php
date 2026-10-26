<?php

namespace App\Modules\Marketplace\Shopee\Services;

use App\Core\Accounting\Account;
use App\Modules\Marketplace\Shopee\Models\ShopeeWalletTransaction as Mutasi;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Mutasi Saldo Penjual Shopee ↔ akun 1104 Saldo Penjualan Shopee.
 *
 *  - impor(): baca file "my_balance_transaction_report…xlsx" dari Seller Centre (nanti juga API).
 *    Baris yang sudah ada dikenali dari kuncinya → unggah ulang / rentang bertumpuk aman.
 *  - cocokkan(): tiap baris dicocokkan dengan jurnal 1104 berstatus posted:
 *      · ber-nomor pesanan → dijumlah per pesanan, dibanding jurnal yang deskripsinya memuat
 *        "SP-<nomor>" (pelepasan dana, koreksi potongan, retur). Tanggal boleh beda.
 *      · tanpa nomor pesanan (penarikan, iklan, SPinjam, kompensasi, penyesuaian) → baris jurnal
 *        1104 tanpa "SP-" bernominal sama dalam ±HARI_TOLERANSI hari, satu lawan satu.
 *  - harian(): saldo akhir Shopee per hari vs saldo 1104 ERP di tanggal yang sama — menunjukkan
 *    hari selisih mulai muncul.
 */
class MutasiShopeeService
{
    public const HARI_TOLERANSI = 3;

    public function akunSaldo(): ?int
    {
        return Account::where('code', '1104')->value('id');
    }

    /** @return array{baru: int, diperbarui: int, dibaca: int, dari: ?string, sampai: ?string} */
    public function impor(string $path, ?int $userId = null): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

        $hIdx = null;
        foreach (array_slice($rows, 0, 60, true) as $i => $r) {
            if (trim((string) ($r[0] ?? '')) === 'Tanggal Transaksi') { $hIdx = $i; break; }
        }
        if ($hIdx === null) {
            throw new \InvalidArgumentException('Bukan file mutasi Saldo Penjual Shopee (kolom "Tanggal Transaksi" tidak ditemukan).');
        }
        $h    = array_flip(array_map(fn ($v) => trim((string) $v), $rows[$hIdx]));
        $data = array_values(array_filter(array_slice($rows, $hIdx + 1), fn ($r) => !empty($r[$h['Tanggal Transaksi']] ?? null)));

        $baru = $diperbarui = 0;
        $tanggal = [];
        $n = count($data);
        $baris = [];
        foreach ($data as $i => $r) {
            $waktu  = $this->waktu($r[$h['Tanggal Transaksi']]);
            $tipe   = trim((string) ($r[$h['Tipe Transaksi']] ?? ''));
            $desk   = trim((string) ($r[$h['Deskripsi']] ?? ''));
            $sn     = trim((string) ($r[$h['No. Pesanan']] ?? ''));
            if ($sn === '' || $sn === '-') {
                $sn = preg_match('/\b(\d{6}[0-9A-Z]{8})\b/', $desk, $m) ? $m[1] : null;
            }
            $jumlah = $this->angka($r[$h['Jumlah']] ?? 0) ?? 0.0;
            $saldo  = isset($h['Saldo Akhir']) ? $this->angka($r[$h['Saldo Akhir']] ?? null) : null;

            $kunci = sha1(implode('|', ['excel', $waktu->format('Y-m-d H:i:s'), $tipe, $desk, $sn, $jumlah, $saldo]));
            // File Seller Centre terurut terbaru dulu → urutan membesar mengikuti waktu.
            $baris[$kunci] = [
                'sumber' => 'excel', 'kunci' => $kunci, 'waktu' => $waktu->format('Y-m-d H:i:s'), 'urutan' => $n - $i,
                'tipe' => $tipe, 'deskripsi' => $desk, 'order_sn' => $sn, 'jumlah' => $jumlah, 'saldo_akhir' => $saldo,
                'status' => isset($h['Status']) ? trim((string) ($r[$h['Status']] ?? '')) : null,
                'kategori' => Mutasi::kategoriDari($tipe, $desk, $sn),
                'diimpor_oleh' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ];
            $tanggal[] = $waktu->toDateString();
        }

        // Kunci yang sudah tersimpan dibaca sekali per potongan, bukan satu query per baris.
        foreach (array_chunk($baris, 500, true) as $potong) {
            $ada = Mutasi::whereIn('kunci', array_keys($potong))->pluck('status', 'kunci');
            $sisip = [];
            foreach ($potong as $kunci => $isi) {
                if (!$ada->has($kunci)) {
                    $sisip[] = $isi;
                } elseif ($ada[$kunci] !== $isi['status']) {
                    // Status bisa berubah antar unduhan (mis. penarikan "Sedang Diproses" → selesai).
                    Mutasi::where('kunci', $kunci)->update(['status' => $isi['status']]);
                    $diperbarui++;
                }
            }
            if ($sisip) {
                Mutasi::insert($sisip);
                $baru += count($sisip);
            }
        }

        return ['baru' => $baru, 'diperbarui' => $diperbarui, 'dibaca' => $n,
            'dari' => $tanggal ? min($tanggal) : null, 'sampai' => $tanggal ? max($tanggal) : null];
    }

    /** @return array<string, int> jumlah baris per status cocok */
    public function cocokkan(): array
    {
        $akun = $this->akunSaldo();
        if (!$akun) {
            throw new \RuntimeException('Akun 1104 tidak ditemukan.');
        }

        $mutasi  = Mutasi::orderBy('waktu')->orderBy('urutan')->get();
        $selesai = $mutasi->filter(fn ($m) => $this->selesai($m));
        $baris   = $this->barisJurnal($akun, $selesai->min('waktu'));

        DB::transaction(function () use ($mutasi, $selesai, $baris) {
            foreach ($mutasi->diff($selesai) as $m) {
                $m->update(['cocok' => null, 'erp_jumlah' => null, 'journal_ids' => null, 'cocok_catatan' => 'Belum selesai di Shopee.']);
            }

            // 1) Ber-nomor pesanan: jumlah per pesanan.
            $perSn = $baris->whereNotNull('sn')->groupBy('sn');
            foreach ($selesai->whereNotNull('order_sn')->groupBy('order_sn') as $sn => $grup) {
                $erp     = $perSn->get($sn, collect());
                $shopee  = round($grup->sum('jumlah'), 2);
                $nilai   = round($erp->sum('nilai'), 2);
                $status  = $erp->isEmpty() ? Mutasi::BELUM : (abs($shopee - $nilai) < 1 ? Mutasi::COCOK : Mutasi::SELISIH);
                $catatan = match ($status) {
                    Mutasi::COCOK   => null,
                    Mutasi::BELUM   => 'Tak ada jurnal 1104 untuk SP-' . $sn . '.',
                    Mutasi::SELISIH => 'Shopee ' . $this->rp($shopee) . ' vs jurnal ' . $this->rp($nilai) . ' (selisih ' . $this->rp($nilai - $shopee) . ').',
                };
                foreach ($grup as $m) {
                    $m->update(['cocok' => $status, 'erp_jumlah' => $erp->isEmpty() ? null : $nilai,
                        'journal_ids' => $erp->pluck('journal_id')->unique()->values()->all() ?: null, 'cocok_catatan' => $catatan]);
                }
            }

            // 2) Tanpa nomor pesanan: nominal sama, tanggal terdekat dalam toleransi, satu lawan satu.
            $calon  = $baris->whereNull('sn')->values()->all();
            $dipakai = [];
            foreach ($selesai->whereNull('order_sn') as $m) {
                $pilih = null;
                foreach ($calon as $i => $b) {
                    if (isset($dipakai[$i]) || abs($b->nilai - $m->jumlah) >= 1) continue;
                    $jarak = abs(Carbon::parse($b->tanggal)->diffInDays($m->waktu->copy()->startOfDay(), false));
                    if ($jarak > self::HARI_TOLERANSI) continue;
                    if ($pilih === null || $jarak < $pilih[1]) $pilih = [$i, $jarak];
                }
                if ($pilih) {
                    $dipakai[$pilih[0]] = true;
                    $b = $calon[$pilih[0]];
                    $m->update(['cocok' => Mutasi::COCOK, 'erp_jumlah' => $b->nilai, 'journal_ids' => [$b->journal_id],
                        'cocok_catatan' => $pilih[1] > 0 ? "Tanggal jurnal {$b->tanggal} (beda {$pilih[1]} hari)." : null]);
                } else {
                    $m->update(['cocok' => Mutasi::BELUM, 'erp_jumlah' => null, 'journal_ids' => null,
                        'cocok_catatan' => 'Tak ada jurnal 1104 bernominal ' . $this->rp($m->jumlah) . ' dalam ±' . self::HARI_TOLERANSI . ' hari.']);
                }
            }
        });

        return Mutasi::whereNotNull('cocok')->select('cocok', DB::raw('count(*) n'))->groupBy('cocok')->pluck('n', 'cocok')->all();
    }

    /**
     * Saldo akhir harian Shopee vs saldo 1104 ERP.
     *
     * @return array<int, array{tanggal: string, shopee: float, erp: float, selisih: float, berubah: float, masuk: float, keluar: float, n: int}>
     */
    public function harian(?string $dari = null, ?string $sampai = null): array
    {
        $akun = $this->akunSaldo();
        $hari = Mutasi::query()
            ->when($dari, fn ($q) => $q->whereDate('waktu', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('waktu', '<=', $sampai))
            ->orderBy('waktu')->orderBy('urutan')
            ->get(['waktu', 'jumlah', 'saldo_akhir', 'status'])
            ->groupBy(fn ($m) => $m->waktu->toDateString());
        if ($hari->isEmpty() || !$akun) return [];

        $tanggal = $hari->keys()->all();
        $awal    = (float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $akun)->where('j.status', 'posted')->where('j.date', '<', $tanggal[0])
            ->sum(DB::raw('l.debit - l.credit'));
        $perTgl  = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $akun)->where('j.status', 'posted')->whereBetween('j.date', [$tanggal[0], end($tanggal)])
            ->groupBy('j.date')->select('j.date', DB::raw('sum(l.debit - l.credit) n'))->pluck('n', 'date');

        $hasil = [];
        $erp = $awal;
        $sebelum = null;
        $kursor = Carbon::parse($tanggal[0]);
        foreach ($hari as $tgl => $grup) {
            for (; $kursor->toDateString() <= $tgl; $kursor->addDay()) {
                $erp += (float) ($perTgl[$kursor->toDateString()] ?? 0);
            }
            $shopee  = (float) $grup->last()->saldo_akhir;
            $selisih = round($erp - $shopee, 2);
            $hasil[] = [
                'tanggal' => $tgl, 'shopee' => $shopee, 'erp' => round($erp, 2), 'selisih' => $selisih,
                'berubah' => $sebelum === null ? 0.0 : round($selisih - $sebelum, 2),
                'masuk' => round($grup->where('jumlah', '>', 0)->sum('jumlah'), 2),
                'keluar' => round($grup->where('jumlah', '<', 0)->sum('jumlah'), 2), 'n' => $grup->count(),
            ];
            $sebelum = $selisih;
        }

        return $hasil;
    }

    /** Saldo 1104 ERP (jurnal posted) sampai akhir $tanggal. */
    public function saldoErp(string $tanggal): ?float
    {
        $akun = $this->akunSaldo();
        if (!$akun) return null;

        return round((float) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $akun)->where('j.status', 'posted')->where('j.date', '<=', $tanggal)
            ->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /** Baris jurnal 1104 posted sejak (tanggal awal − toleransi), dengan nomor pesanan bila ada. */
    private function barisJurnal(int $akun, $sejak): Collection
    {
        return DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $akun)->where('j.status', 'posted')
            ->when($sejak, fn ($q) => $q->where('j.date', '>=', Carbon::parse($sejak)->subDays(self::HARI_TOLERANSI)->toDateString()))
            ->get(['l.journal_id', 'j.date as tanggal', 'j.description', 'l.debit', 'l.credit'])
            ->map(function ($b) {
                $b->nilai = round((float) $b->debit - (float) $b->credit, 2);
                $b->sn    = preg_match('/SP-([0-9A-Z]{14})\b/', (string) $b->description, $m) ? $m[1] : null;
                return $b;
            });
    }

    private function selesai(Mutasi $m): bool
    {
        return $m->status === null || $m->status === '' || str_contains(mb_strtolower($m->status), 'selesai');
    }

    private function waktu($v): Carbon
    {
        return is_numeric($v)
            ? Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v))
            : Carbon::parse(str_replace('/', '-', (string) $v));
    }

    private function angka($v): ?float
    {
        if ($v === null || $v === '' || $v === '-') return null;
        return is_numeric($v) ? (float) $v : (float) clean_number((string) $v);
    }

    private function rp(float $v): string
    {
        return 'Rp' . number_format($v, 0, ',', '.');
    }
}
