<?php

namespace App\Services;

use App\Models\Customer;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gabung pelanggan kembar — orang yang sama tercatat dua kali.
 *
 * Kembar lahir terutama dari checkout web: dulu nomor dicari apa adanya, jadi
 * '081252527212' tidak mengenali '+62 812-5252-7212' dan pembeli lama dibuatkan
 * pelanggan WEB-… baru. Riwayatnya lalu terbelah dua.
 *
 * ATURAN OTOMATIS: nomor HP sama (setelah dinormalkan) DAN nama sama. Nomor sama
 * tapi nama beda TIDAK digabung sendiri — satu nomor dipakai dua orang itu nyata
 * (kakak-adik, rekan sekantor) — melainkan masuk daftar "perlu dicek" untuk
 * diputuskan admin lewat tombol Gabung.
 *
 * Yang digabung tidak dihapus: diarsipkan dan menunjuk ke penampungnya
 * (`merged_into_id`), supaya jejaknya tetap bisa ditelusuri.
 */
class CustomerMergeService
{
    /**
     * Tabel & kolom yang menunjuk ke pelanggan. Jurnal TIDAK menyimpan pelanggan,
     * jadi buku besar tak perlu disentuh.
     */
    private const RUJUKAN = [
        'sales_quotations'             => ['customer_id'],
        'sales_orders'                 => ['customer_id'],
        'sales_invoices'               => ['customer_id'],
        'sales_returns'                => ['customer_id', 'refund_customer_id'],
        'sales_advances'               => ['customer_id'],
        'customer_payments'            => ['customer_id'],
        'customer_overpayments'        => ['customer_id'],
        'customer_credits'             => ['customer_id'],
        'customer_billings'            => ['customer_id'],
        'customer_branches'            => ['customer_id'],
        'customer_notification_phones' => ['customer_id'],
        'warranty_orders'              => ['customer_id'],
        'cash_disbursements'           => ['customer_id'],
        'midtrans_transactions'        => ['customer_id'],
        'web_payments'                 => ['customer_id'],
        'crm_conversations'            => ['customer_id'],
    ];

    /** Isian yang diwariskan bila di penampung masih kosong (email, PIN web, alamat…). */
    private const ISIAN_WARIS = [
        'email', 'web_order_pin', 'address', 'shipping_address', 'city', 'province',
        'district', 'postal_code', 'recipient_phone', 'biteship_area_id',
        'jubelio_area_id', 'kiriminaja_area_id', 'latitude', 'longitude',
    ];

    /**
     * Gabungkan $dari ke dalam $ke. Semua dokumen $dari pindah ke $ke, lalu $dari
     * diarsipkan. Atomik: gagal di tengah = tak ada yang berubah.
     */
    public function gabung(Customer $dari, Customer $ke): void
    {
        if ($dari->is($ke)) {
            throw new \InvalidArgumentException('Pelanggan tidak bisa digabung ke dirinya sendiri.');
        }
        if ($dari->merged_into_id || $ke->merged_into_id) {
            throw new \InvalidArgumentException('Salah satu pelanggan sudah pernah digabung.');
        }
        if ($dari->is_marketplace || $ke->is_marketplace) {
            throw new \InvalidArgumentException('Pelanggan marketplace tidak ikut digabung.');
        }

        DB::transaction(function () use ($dari, $ke) {
            foreach (self::RUJUKAN as $tabel => $kolom) {
                foreach ($kolom as $k) {
                    if (Schema::hasColumn($tabel, $k)) {
                        DB::table($tabel)->where($k, $dari->id)->update([$k => $ke->id]);
                    }
                }
            }

            $this->warisiIsian($dari, $ke);
            $this->rapikanNomorTambahan($ke);

            // Customer::saved observer (CRM) sengaja tidak perlu dipicu: chatnya sudah
            // dipindahkan lewat RUJUKAN di atas.
            $dari->forceFill([
                'is_active'      => false,
                'merged_into_id' => $ke->id,
                'merged_at'      => now(),
            ])->saveQuietly();
        });
    }

    /**
     * Gabungkan semua kembar yang lolos aturan otomatis (nomor sama + nama sama).
     * Penampungnya pelanggan tertua di kelompok itu — biasanya yang riwayatnya terbanyak.
     *
     * @return array<int, array{dari:Customer, ke:Customer}>  yang digabung (atau akan, bila $kering)
     */
    public function gabungOtomatis(bool $kering = false): array
    {
        $hasil = [];

        foreach ($this->kelompokKembar() as $kelompok) {
            foreach ($kelompok['otomatis'] as $sama) {
                $ke = $this->pilihPenampung($sama);

                foreach ($sama as $dari) {
                    if ($dari->is($ke)) {
                        continue;
                    }
                    if (! $kering) {
                        $this->gabung($dari, $ke);
                    }
                    $hasil[] = ['dari' => $dari, 'ke' => $ke];
                }
            }
        }

        return $hasil;
    }

    /**
     * Gabung otomatis untuk SATU nomor — dipanggil sesaat setelah pelanggan disimpan.
     * Mengembalikan penampungnya bila $customer ikut digabung.
     */
    public function gabungOtomatisUntuk(Customer $customer): ?Customer
    {
        if ($customer->is_marketplace || $customer->merged_into_id) {
            return null;
        }

        $sama = Customer::denganNomor($customer->phone)
            ->filter(fn (Customer $c) => self::kunciNama($c->name) === self::kunciNama($customer->name))
            ->values();

        if ($sama->count() < 2) {
            return null;
        }

        $ke = $this->pilihPenampung($sama);
        foreach ($sama as $dari) {
            if (! $dari->is($ke)) {
                $this->gabung($dari, $ke);
            }
        }

        return $customer->is($ke) ? null : $ke;
    }

    /**
     * Kelompok pelanggan bernomor sama (≥2 orang). Tiap kelompok dipecah menjadi:
     *  - `otomatis`: sub-kelompok bernama sama (≥2) → aman digabung sendiri
     *  - `cek`: seluruh anggota bila namanya tidak semua sama → keputusan admin
     *
     * @return Collection<string, array{nomor:string, anggota:Collection, otomatis:Collection, cek:bool}>
     */
    public function kelompokKembar(): Collection
    {
        return Customer::query()
            ->where(fn ($q) => $q->whereNull('is_marketplace')->orWhere('is_marketplace', false))
            ->whereNull('merged_into_id')
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Customer $c) => PhoneNumber::normalize($c->phone) ?? '')
            ->filter(fn (Collection $g, string $nomor) => $nomor !== '' && $g->count() > 1)
            ->map(function (Collection $g, string $nomor) {
                $perNama = $g->groupBy(fn (Customer $c) => self::kunciNama($c->name));

                return [
                    'nomor'    => $nomor,
                    'anggota'  => $g->values(),
                    'otomatis' => $perNama->filter(fn ($x) => $x->count() > 1)->map->values()->values(),
                    'cek'      => $perNama->count() > 1,
                ];
            });
    }

    /** Nama untuk dibandingkan: huruf kecil, tanpa tanda baca, spasi dirapatkan. */
    public static function kunciNama(?string $nama): string
    {
        $nama = mb_strtolower(trim((string) $nama));
        $nama = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $nama) ?? $nama;

        return trim(preg_replace('/\s+/', ' ', $nama) ?? $nama);
    }

    /** Penampung: yang aktif lebih dulu, lalu yang tertua. */
    private function pilihPenampung(Collection $sama): Customer
    {
        return $sama->sortBy(fn (Customer $c) => [$c->is_active ? 0 : 1, $c->id])->first();
    }

    private function warisiIsian(Customer $dari, Customer $ke): void
    {
        $ubah = [];

        foreach (self::ISIAN_WARIS as $kolom) {
            if (blank($ke->{$kolom}) && filled($dari->{$kolom})) {
                $ubah[$kolom] = $dari->{$kolom};
            }
        }

        // Persetujuan WA eksplisit ikut, begitu pula KEBERATAN — keberatan dari salah
        // satu catatan tetap keberatan orang yang sama.
        if (! $ke->wa_opt_in && $dari->wa_opt_in) {
            $ubah += ['wa_opt_in' => true, 'wa_opt_in_at' => $dari->wa_opt_in_at, 'wa_opt_in_source' => $dari->wa_opt_in_source];
        }
        if (! $ke->wa_opt_out_at && $dari->wa_opt_out_at) {
            $ubah['wa_opt_out_at'] = $dari->wa_opt_out_at;
        }

        if ($ubah) {
            $ke->forceFill($ubah)->saveQuietly();
        }
    }

    /** Nomor tambahan yang kini kembar (atau sama dengan nomor utama) dibuang. */
    private function rapikanNomorTambahan(Customer $ke): void
    {
        if (! Schema::hasTable('customer_notification_phones')) {
            return;
        }

        $sudah = array_filter([PhoneNumber::normalize($ke->phone)]);

        foreach (DB::table('customer_notification_phones')->where('customer_id', $ke->id)->orderBy('id')->get() as $n) {
            $kunci = PhoneNumber::normalize($n->phone);
            if ($kunci && in_array($kunci, $sudah, true)) {
                DB::table('customer_notification_phones')->where('id', $n->id)->delete();
                continue;
            }
            $sudah[] = $kunci;
        }
    }
}
