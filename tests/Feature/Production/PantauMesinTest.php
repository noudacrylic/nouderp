<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Production\Mesin\RuidaKlien;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
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

    public function test_alarm_mesin_selesai_belum_disentuh(): void
    {
        Carbon::setTestNow('2026-11-28 10:20:00');
        $cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $mesin = fn ($n) => DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => $n, 'is_active' => true,
            'mesin_ip' => '192.168.1.9' . strlen($n), 'mesin_protokol' => 'udp']);
        $m1 = $mesin('Mesin 1');
        $m2 = $mesin('Mesin 22');
        $m3 = $mesin('Mesin 333');
        $baris = fn ($m, $k, $a, $b) => DB::table('production_mesin_keadaan')->insert(['executor_id' => $m->id, 'keadaan' => $k,
            'mulai_at' => "2026-11-28 $a", 'sampai_at' => $b ? "2026-11-28 $b" : null, 'dilihat_at' => '2026-11-28 ' . ($b ?? '10:19:50')]);
        // Mesin 1: selesai 10:18, diam → alarm.
        $baris($m1, 'potong', '09:00:00', '10:18:00');
        $baris($m1, 'diam', '10:18:00', null);
        // Mesin 2: selesai lalu kepala digerakkan (operator sudah di mesin) → tidak alarm.
        $baris($m2, 'potong', '09:00:00', '10:10:00');
        $baris($m2, 'diam', '10:10:00', '10:15:00');
        $baris($m2, 'gerak', '10:15:00', '10:15:05');
        $baris($m2, 'diam', '10:15:05', null);
        // Mesin 3: sedang memotong → tidak alarm.
        $baris($m3, 'potong', '10:00:00', null);

        $a = app(PantauMesinService::class)->menungguDiambil();
        $this->assertSame([['id' => $m1->id, 'nama' => 'Mesin 1', 'selesai' => '10:18', 'detik' => 120]], $a);

        // Siapa pun yang login boleh bertanya (perangkat stasiun, bukan hak menu).
        $operator = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($operator)->getJson(route('intercom.mesin-cnc'))
            ->assertOk()->assertJsonPath('menunggu.0.nama', 'Mesin 1');
        Carbon::setTestNow();
    }

    public function test_pita_sinyal_di_kalender_produksi(): void
    {
        Carbon::setTestNow('2026-11-25 15:00:00');
        $cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $andi = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Andi', 'is_active' => true]);
        $m = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Mesin 9', 'parent_executor_id' => $andi->id,
            'is_active' => true, 'mesin_ip' => '192.168.1.99', 'mesin_protokol' => 'udp']);
        DB::table('production_mesin_keadaan')->insert([
            // dari kemarin → dipotong mulai 00:00
            ['executor_id' => $m->id, 'keadaan' => 'putus', 'mulai_at' => '2026-11-24 20:00:00', 'sampai_at' => '2026-11-25 08:00:00', 'dilihat_at' => '2026-11-25 07:59:00'],
            ['executor_id' => $m->id, 'keadaan' => 'potong', 'mulai_at' => '2026-11-25 08:00:00', 'sampai_at' => '2026-11-25 10:00:00', 'dilihat_at' => '2026-11-25 09:59:50'],
            ['executor_id' => $m->id, 'keadaan' => 'diam', 'mulai_at' => '2026-11-25 10:00:00', 'sampai_at' => null, 'dilihat_at' => '2026-11-25 10:30:00'],
        ]);

        $h = app(PantauMesinService::class)->hari(Carbon::parse('2026-11-25'))[$m->id];
        $this->assertSame(['potong' => 7200, 'diam' => 1800, 'gerak' => 0, 'putus' => 8 * 3600], $h['detik'],
            'Diam berhenti di pembacaan terakhir 10:30 (sinyal basi), sesudahnya tanpa data.');

        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($owner)->get(route('analisa.kalender.index', ['date' => '2026-11-25']))
            ->assertOk()
            ->assertSee('Sinyal mesin:')
            ->assertSee('Memotong 08:00:00–10:00:00 (2j)')
            ->assertSee('potong 2j');
        Carbon::setTestNow();
    }
}
