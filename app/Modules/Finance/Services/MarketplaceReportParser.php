<?php

namespace App\Modules\Finance\Services;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Baca file ASLI laporan marketplace untuk Rekonsiliasi, tanpa perlu diubah lewat AI dulu:
 *
 *  - TikTok/Tokopedia "income_….xlsx" (Seller Center → Keuangan → Penghasilan), sheet "Detail
 *    pesanan": satu baris per pesanan dengan rincian biaya lengkap — komisi, komisi dinamis,
 *    Growth Xtra, biaya proses, ongkir, Komisi Afiliasi, PPh Pasal 22 dipungut. Rinciannya ikut
 *    dikembalikan (`rincian`) untuk kartu Potongan Marketplace & Laporan Pajak.
 *  - Lazada "Income Overview" (Keuangan → Laporan Penghasilan): satu baris per jenis biaya per
 *    item ("Nama Biaya": Omset Penjualan, Komisi, Biaya Transaksi, Pajak Penghasilan Pasal 22,
 *    …) — dijumlah per "Nomor Pesanan" dan dirinci seperti TikTok.
 *  - Shopee "my_balance_transaction_report…xlsx" (Saldo Penjual → mutasi): hanya nilai bersih per
 *    pesanan; rinciannya sudah datang dari Jubelio. Penarikan dana, isi ulang iklan, dan
 *    penyesuaian tanpa nomor pesanan dilewati (bukan per pesanan) dan dilaporkan.
 *
 * Hasil: null bila bukan salah satu format di atas (→ format baku), atau
 * ['format' => …, 'rows' => [['marketplace','order_ref','settlement_date','net_amount','raw_row','rincian'?]],
 *  'dilewati' => [label => ['n' => …, 'jumlah' => …]]]. Satu baris per nomor pesanan.
 */
class MarketplaceReportParser
{
    public function parse(Spreadsheet $ss): ?array
    {
        foreach ($ss->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, false);
            if (in_array('Jumlah penyelesaian pembayaran', $rows[0] ?? [], true)) {
                return $this->tiktok($rows);
            }
        }

        $rows = $ss->getActiveSheet()->toArray(null, true, false, false);
        $kepala = array_map(fn ($v) => trim((string) $v), $rows[0] ?? []);
        if (in_array('Nama Biaya', $kepala, true) && in_array('Nomor Pesanan', $kepala, true)) {
            return $this->lazada($rows);
        }
        foreach (array_slice($rows, 0, 40, true) as $i => $r) {
            if (($r[0] ?? null) === 'Tanggal Transaksi' && in_array('No. Pesanan', $r, true)) {
                return $this->shopee($rows, $i);
            }
        }

        return null;
    }

    // ───────────────────────────── TikTok / Tokopedia ─────────────────────────────

    private function tiktok(array $rows): array
    {
        $h = array_flip(array_map(fn ($v) => trim((string) $v), $rows[0]));
        $col = fn (string $nama) => $h[$nama] ?? null;

        // Kolom biaya = antara "Total Biaya" dan "Jumlah penyesuaian". "Ongkir" adalah SUBTOTAL
        // kolom-kolom ongkir sesudahnya sampai "Biaya layanan logistik" — dipakai sebagai satu
        // baris, kolom anaknya dilewati supaya tak terhitung dua kali.
        $awal  = $col('Total Biaya');
        $akhir = $col('Jumlah penyesuaian') ?? count($rows[0]);
        $ongkir = $col('Ongkir');
        $ongkirAkhir = $col('Biaya layanan logistik');
        $kolomBiaya = [];
        for ($j = $awal + 1; $j < $akhir; $j++) {
            $nama = trim((string) ($rows[0][$j] ?? ''));
            if ($nama === '' || $j === $ongkir) {
                continue;
            }
            if ($ongkir !== null && $ongkirAkhir !== null && $j > $ongkir && $j <= $ongkirAkhir) {
                continue;
            }
            $kolomBiaya[$j] = $nama;
        }

        $out = [];
        $dilewati = [];
        foreach (array_slice($rows, 1) as $r) {
            $id = trim((string) ($r[$col('ID Pesanan/Penyesuaian')] ?? ''));
            if ($id === '') {
                continue;
            }
            $jenis = trim((string) ($r[$col('Jenis transaksi')] ?? ''));
            $net = $this->angka($r[$col('Jumlah penyelesaian pembayaran')] ?? 0);
            $pesanan = $jenis === 'Pesanan';
            $ref = $pesanan ? $id : trim((string) ($r[$col('ID pesanan terkait')] ?? ''));
            if ($ref === '' || $ref === '/') {
                $this->lewati($dilewati, $jenis ?: 'Penyesuaian', $net);
                continue;
            }

            $row = &$out[$ref];
            $row ??= [
                'marketplace' => 'tiktok_tokopedia', 'order_ref' => $ref, 'settlement_date' => null,
                'net_amount' => 0.0, 'raw_row' => [],
            ];
            $row['net_amount'] = round($row['net_amount'] + $net, 2);
            $row['settlement_date'] = max($row['settlement_date'] ?? '', $this->tanggal($r[$col('Waktu pembayaran pesanan')] ?? null) ?? '') ?: null;
            $row['raw_row'][] = $r;

            if ($pesanan) {
                $admin = [];
                $aff = 0.0;
                $pajak = 0.0;
                if ($ongkir !== null && ($biaya = -$this->angka($r[$ongkir] ?? 0)) > 0) {
                    $admin['Ongkir & layanan logistik'] = $biaya;
                }
                foreach ($kolomBiaya as $j => $nama) {
                    $biaya = -$this->angka($r[$j] ?? 0);
                    if ($biaya == 0) {
                        continue;
                    }
                    if (stripos($nama, 'afiliasi') !== false) {
                        $aff += $biaya;
                    } elseif (stripos($nama, 'PPh') !== false) {
                        $pajak += $biaya;
                    } elseif ($biaya > 0) {
                        $admin[$nama] = ($admin[$nama] ?? 0) + $biaya;
                    }
                }
                $row['rincian'] = ['admin_rincian' => $admin, 'affiliate' => $aff, 'pajak' => $pajak, 'saldo' => $net];
            }
            unset($row);
        }

        // Penyesuaian susulan pada pesanan yang sama ikut mengubah saldo masuknya.
        foreach ($out as &$row) {
            if (isset($row['rincian'])) {
                $row['rincian']['saldo'] = $row['net_amount'];
            }
            $row['raw_row'] = json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE);
        }
        unset($row);

        return ['format' => 'Laporan penghasilan TikTok/Tokopedia', 'rows' => array_values($out), 'dilewati' => $dilewati];
    }

    // ───────────────────────────── Lazada ─────────────────────────────

    private function lazada(array $rows): array
    {
        $h = array_flip(array_map(fn ($v) => trim((string) $v), $rows[0]));
        $out = [];
        $dilewati = [];

        foreach (array_slice($rows, 1) as $r) {
            $ref = trim((string) ($r[$h['Nomor Pesanan']] ?? ''));
            $nama = trim(preg_replace('/\s+/', ' ', (string) ($r[$h['Nama Biaya']] ?? '')));
            $jumlah = $this->angka($r[$h['Jumlah (Termasuk Pajak)']] ?? 0);
            if ($nama === '' && $jumlah == 0) {
                continue;
            }
            $status = trim((string) ($r[$h['Status Pelepasan Dana']] ?? ''));
            if ($status !== '' && stripos($status, 'Dilepas') === false) {
                $this->lewati($dilewati, 'Belum dilepas: ' . $nama, $jumlah);
                continue;
            }
            if ($ref === '') {
                $this->lewati($dilewati, $nama ?: 'Lainnya', $jumlah);
                continue;
            }

            $row = &$out[$ref];
            $row ??= [
                'marketplace' => 'lazada', 'order_ref' => $ref, 'settlement_date' => null, 'net_amount' => 0.0, 'raw_row' => [],
                'rincian' => ['admin_rincian' => [], 'affiliate' => 0.0, 'pajak' => 0.0, 'saldo' => 0.0],
            ];
            $row['net_amount'] = round($row['net_amount'] + $jumlah, 2);
            $row['settlement_date'] = max($row['settlement_date'] ?? '', $this->tanggal($r[$h['Tanggal Dilepas']] ?? null) ?? '') ?: null;
            $row['raw_row'][] = $r;

            $biaya = -$jumlah;
            if (stripos($nama, 'Omset') !== false || $biaya <= 0) {
                // Omset & pemasukan lain (kompensasi, pengembalian) bukan potongan.
            } elseif (stripos($nama, 'Pajak Penghasilan') !== false || stripos($nama, 'PPh') !== false) {
                $row['rincian']['pajak'] += $biaya;
            } elseif (stripos($nama, 'affiliate') !== false || stripos($nama, 'afiliasi') !== false) {
                $row['rincian']['affiliate'] += $biaya;
            } else {
                $row['rincian']['admin_rincian'][$nama] = ($row['rincian']['admin_rincian'][$nama] ?? 0) + $biaya;
            }
            unset($row);
        }

        foreach ($out as &$row) {
            $row['rincian']['saldo'] = $row['net_amount'];
            $row['raw_row'] = json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE);
        }
        unset($row);

        return ['format' => 'Laporan penghasilan Lazada', 'rows' => array_values($out), 'dilewati' => $dilewati];
    }

    // ───────────────────────────── Shopee ─────────────────────────────

    private function shopee(array $rows, int $headerIdx): array
    {
        $h = array_flip(array_map(fn ($v) => trim((string) $v), $rows[$headerIdx]));
        $out = [];
        $dilewati = [];

        foreach (array_slice($rows, $headerIdx + 1) as $r) {
            $tgl = $r[$h['Tanggal Transaksi']] ?? null;
            if (!$tgl) {
                continue;
            }
            $tipe = trim((string) ($r[$h['Tipe Transaksi']] ?? ''));
            $desk = trim((string) ($r[$h['Deskripsi']] ?? ''));
            $jumlah = $this->angka($r[$h['Jumlah']] ?? 0);
            if (isset($h['Status']) && trim((string) ($r[$h['Status']] ?? '')) !== 'Transaksi Selesai') {
                $this->lewati($dilewati, $tipe . ' (belum selesai)', $jumlah);
                continue;
            }

            $ref = trim((string) ($r[$h['No. Pesanan']] ?? ''));
            if (($ref === '' || $ref === '-') && preg_match('/\b(\d{6}[0-9A-Z]{8})\b/', $desk, $m)) {
                $ref = $m[1];   // mis. "Penyesuaian … premi Pesanan yang Gagal Terkirim: 2610096HTQN2MD"
            }
            if ($ref === '' || $ref === '-') {
                $this->lewati($dilewati, $tipe ?: 'Lainnya', $jumlah);
                continue;
            }

            $row = &$out[$ref];
            $row ??= ['marketplace' => 'shopee', 'order_ref' => $ref, 'settlement_date' => null, 'net_amount' => 0.0, 'raw_row' => []];
            $row['net_amount'] = round($row['net_amount'] + $jumlah, 2);
            $row['settlement_date'] = max($row['settlement_date'] ?? '', $this->tanggal($tgl) ?? '') ?: null;
            $row['raw_row'][] = $r;
            unset($row);
        }

        foreach ($out as &$row) {
            $row['raw_row'] = json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE);
        }
        unset($row);

        return ['format' => 'Mutasi Saldo Penjual Shopee', 'rows' => array_values($out), 'dilewati' => $dilewati];
    }

    // ───────────────────────────── Bantuan ─────────────────────────────

    private function lewati(array &$d, string $label, float $jumlah): void
    {
        $d[$label] ??= ['n' => 0, 'jumlah' => 0.0];
        $d[$label]['n']++;
        $d[$label]['jumlah'] += $jumlah;
    }

    private function angka($v): float
    {
        if ($v === null || $v === '' || $v === '/') {
            return 0.0;
        }

        return is_numeric($v) ? (float) $v : (float) clean_number((string) $v);
    }

    private function tanggal($v): ?string
    {
        if ($v === null || $v === '' || $v === '/') {
            return null;
        }
        try {
            return is_numeric($v)
                ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d')
                : Carbon::parse(str_replace('/', '-', (string) $v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
