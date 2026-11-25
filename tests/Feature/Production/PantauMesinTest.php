<?php

namespace Tests\Feature\Production;

use App\Modules\Production\Mesin\RuidaKlien;
use App\Modules\Production\Services\PantauMesinService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sinyal mesin CNC (25 Nov 2026). Byte uji diambil dari rekaman pktmon RDWorks ↔ Mesin 1 di bengkel.
 */
class PantauMesinTest extends TestCase
{
    use RefreshDatabase;

    public function test_paket_sama_persis_dengan_rdworks(): void
    {
        $baca = RuidaKlien::perintahBaca(0x057E);
        $this->assertSame('0261d4890df7', bin2hex(RuidaKlien::paketUdp($baca)), 'UDP: checksum + isi teracak');
        $this->assertSame('d4890df7', bin2hex(RuidaKlien::acak($baca)), 'TCP: isi teracak tanpa checksum');

        // Balasan mesin: ACK (c6 → CC) lalu DA 01 05 7E + nilai = id kartu 0x90109010.
        $aliran = RuidaKlien::buka(hex2bin('c6d4090df70189cba999'));
        $this->assertSame("\xCC", $aliran[0]);
        [$v, $sisa] = RuidaKlien::ambilNilai($aliran, 0x057E);
        $this->assertSame([0x90109010, ''], [$v, $sisa]);
        $this->assertNull(RuidaKlien::ambilNilai($aliran, 0x0400)[0], 'Alamat lain tidak tertukar.');
    }

    public function test_keadaan_dari_status(): void
    {
        $this->assertSame('potong', PantauMesinService::keadaan(0x20018401, false));
        $this->assertSame('potong', PantauMesinService::keadaan(0x28018401, true));
        $this->assertSame('diam', PantauMesinService::keadaan(0x20000600, false));
        $this->assertSame('gerak', PantauMesinService::keadaan(0x20008600, true));
        $this->assertSame('putus', PantauMesinService::keadaan(null, false));
    }

    public function test_hanya_perubahan_keadaan_menambah_baris(): void
    {
        $p = app(PantauMesinService::class);
        $t = Carbon::parse('2026-11-25 10:00:00');
        $p->catat(7, 'potong', 1, $t);
        $p->catat(7, 'potong', 1, $t->copy()->addSeconds(3));
        $p->catat(7, 'potong', 1, $t->copy()->addSeconds(20));   // dilihat diperbarui
        $p->catat(7, 'diam', 0, $t->copy()->addSeconds(23));
        // Server mati 5 menit → potongan diam ditutup di pembacaan terakhir, selang dibiarkan kosong.
        $p->catat(7, 'diam', 0, $t->copy()->addSeconds(26));
        $p->catat(7, 'diam', 0, $t->copy()->addMinutes(6));

        $rows = DB::table('production_mesin_keadaan')->orderBy('id')->get();
        $this->assertSame(['potong', 'diam', 'diam'], $rows->pluck('keadaan')->all());
        $this->assertSame('2026-11-25 10:00:23', $rows[0]->sampai_at);
        $this->assertSame('2026-11-25 10:00:20', $rows[0]->dilihat_at);
        $this->assertSame('2026-11-25 10:00:23', $rows[1]->sampai_at, 'Selang tanpa data tidak dianggap diam.');
        $this->assertNull($rows[2]->sampai_at);
    }
}
