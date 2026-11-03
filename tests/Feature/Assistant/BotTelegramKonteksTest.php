<?php

namespace Tests\Feature\Assistant;

use App\Core\Accounting\Account;
use App\Models\User;
use App\Modules\Assistant\Models\AsistenIngatan;
use App\Modules\Assistant\Services\AiAccountantService;
use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualJurnal;
use App\Modules\KaryawanVirtual\Skills\Owner\PencatatanTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bot pencatatan: tanpa ambang konfirmasi, percakapan nyambung lintas hari, ingatan permanen.
 * Claude dipalsukan — yang diuji alur ERP-nya, bukan jawaban modelnya.
 */
class BotTelegramKonteksTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '5001';

    private User $owner;
    private Account $beban;
    private Account $kas;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'KUNCI-UJI', 'services.telegram.token' => 'TOKEN-UJI']);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => self::CHAT]);
        Auth::login($this->owner);

        \App\Core\Period\AccountingPeriod::firstOrCreate(
            ['year' => (int) now()->year, 'month' => (int) now()->month],
            ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']
        );

        $this->beban = Account::firstOrCreate(['code' => '5999'], ['name' => 'Beban Uji', 'type' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]);
        $this->kas   = Account::firstOrCreate(['code' => '1199'], ['name' => 'Bank BCA Uji', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]);
        $this->kas->forceFill(['is_cash_account' => true, 'account_category' => 'cash'])->save();
    }

    /** @param array<int, array> $jawaban isi `content` tiap balasan Claude, berurutan */
    private function claude(array ...$jawaban): void
    {
        $urutan = Http::sequence();
        foreach ($jawaban as $isi) {
            $pakaiTool = collect($isi)->contains('type', 'tool_use');
            $urutan->push(['content' => $isi, 'stop_reason' => $pakaiTool ? 'tool_use' : 'end_turn']);
        }
        Http::fake(['api.anthropic.com/*' => $urutan, 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function bot(string $teks): string
    {
        return app(AiAccountantService::class)->handle($this->owner, self::CHAT, $teks);
    }

    private function teks(string $t): array
    {
        return [['type' => 'text', 'text' => $t]];
    }

    private function tool(string $nama, array $input): array
    {
        return [['type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $nama, 'input' => $input]];
    }

    /** Payload request Claude ke-$i. */
    private function permintaan(int $i): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'anthropic'))[$i][0]->data();
    }

    private function catat(float $nominal, array $tambahan = []): array
    {
        return $this->tool('catat_pengeluaran', $tambahan + [
            'akun_beban_id' => $this->beban->id, 'account_id' => $this->beban->id,
            'kas_account_id' => $this->kas->id, 'cash_account_id' => $this->kas->id,
            'nominal' => $nominal, 'keterangan' => 'Beli mesin',
        ]);
    }

    public function test_nominal_besar_langsung_diposting_tanpa_konfirmasi(): void
    {
        $this->claude($this->catat(25_000_000), $this->teks('Tercatat Rp25 juta.'));

        $balasan = $this->bot('beli mesin 25jt dari bca');

        $this->assertStringNotContainsString('konfirmasi', strtolower($balasan));
        $this->assertStringContainsString('/batal', $balasan, json_encode($this->permintaan(1)['messages'][2] ?? null));
        $cd = CashDisbursement::sole();
        $this->assertSame('posted', $cd->status);
        $this->assertSame(1, KaryawanVirtualJurnal::where('skill', PencatatanTelegram::KEY)->count());
    }

    public function test_gagal_posting_tidak_meninggalkan_draft(): void
    {
        // Akun kas bukan kas sungguhan / periode bermasalah → post melempar galat.
        $this->mock(\App\Modules\Finance\Services\CashDisbursementService::class, function ($m) {
            $m->shouldReceive('createDraft')->andReturnUsing(fn () => CashDisbursement::create([
                'number' => 'CD-UJI', 'date' => now()->toDateString(), 'type' => 'general', 'cash_account_id' => $this->kas->id, 'status' => 'draft',
            ]));
            $m->shouldReceive('post')->andThrow(new \RuntimeException('Periode ditutup'));
        });
        $this->claude($this->catat(50_000), $this->teks('Periode ditutup, tanggal berapa?'));

        $this->bot('bensin 50rb dari bca');

        $this->assertSame(0, CashDisbursement::count());
        $this->assertStringContainsString('tidak ada yang tercatat', json_encode($this->permintaan(1)));
    }

    public function test_balasan_esok_hari_tetap_nyambung_dan_diberi_catatan_tanggal(): void
    {
        $this->claude($this->teks('Dari rekening mana?'), $this->teks('Oke.'));

        $this->travelTo(now()->setTime(9, 0));
        $this->bot('bensin 50rb');

        $this->travel(26)->hours();
        $this->bot('dari bca');

        $pesan = $this->permintaan(1)['messages'];
        $this->assertSame('bensin 50rb', $pesan[0]['content'], 'Percakapan kemarin masih ada.');
        $this->assertStringContainsString('Catatan sistem', $pesan[2]['content']);
        $this->assertStringContainsString('dari bca', $pesan[2]['content']);
    }

    public function test_balasan_cepat_tanpa_catatan_dan_lewat_tujuh_hari_mulai_baru(): void
    {
        $this->claude($this->teks('Dari mana?'), $this->teks('Oke.'), $this->teks('Halo.'));

        $this->bot('bensin 50rb');
        $this->travel(10)->minutes();
        $this->bot('dari bca');
        $this->assertSame('dari bca', $this->permintaan(1)['messages'][2]['content']);

        $this->travel(8)->days();
        $this->bot('halo');
        $this->assertCount(1, $this->permintaan(2)['messages']);
    }

    public function test_foto_tidak_tersimpan_dan_riwayat_dibatasi(): void
    {
        $this->claude($this->teks('Dari mana?'), $this->teks('Oke.'));

        app(AiAccountantService::class)->handle($this->owner, self::CHAT, 'struk', ['data' => 'BASE64BESAR', 'media_type' => 'image/jpeg']);
        $this->bot('dari bca');

        $kedua = json_encode($this->permintaan(1)['messages']);
        $this->assertStringNotContainsString('BASE64BESAR', $kedua);
        $this->assertStringContainsString('foto struk', $kedua);
    }

    public function test_riwayat_panjang_dipangkas_mulai_dari_pesan_user_biasa(): void
    {
        $jawab = array_fill(0, 22, $this->teks('Oke.'));
        $this->claude(...$jawab);

        foreach (range(1, 21) as $i) {
            $this->bot("pesan $i");
        }
        $this->bot('terakhir');

        $pesan = $this->permintaan(21)['messages'];
        $this->assertLessThanOrEqual(31, count($pesan));
        $this->assertSame('user', $pesan[0]['role']);
        $this->assertIsString($pesan[0]['content']);
    }

    public function test_ingatan_tersimpan_ikut_di_prompt_dan_bisa_dihapus(): void
    {
        $this->claude($this->tool('simpan_ingatan', ['isi' => 'BCA berarti rekening Bank BCA, bukan QRIS']), $this->teks('📝 Saya ingat.'), $this->teks('Oke.'));

        $this->bot('untuk bca pakai bank bukan qris');
        $i = AsistenIngatan::sole();
        $this->assertSame($this->owner->id, $i->user_id);

        // Percakapan baru tetap membawa ingatannya.
        $this->bot('/baru');
        $this->bot('bensin 50rb dari bca');
        $this->assertStringContainsString('BCA berarti rekening Bank BCA', $this->permintaan(2)['system'][0]['text']);

        $this->assertStringContainsString('BCA berarti', $this->bot('/ingatan'));
        $this->assertStringContainsString('dihapus', $this->bot("/lupa {$i->id}"));
        $this->assertSame(0, AsistenIngatan::count());
    }

    public function test_ingatan_kembar_tidak_disimpan_dan_milik_orang_lain_tidak_terhapus(): void
    {
        $lain = User::factory()->create();
        $milikLain = AsistenIngatan::create(['user_id' => $lain->id, 'isi' => 'Aturan orang lain']);
        $this->claude(
            $this->tool('simpan_ingatan', ['isi' => 'Bensin dari kas kecil']), $this->tool('simpan_ingatan', ['isi' => 'Bensin dari kas kecil']),
            $this->tool('hapus_ingatan', ['id' => $milikLain->id]), $this->teks('Oke.'),
        );

        $this->bot('bensin selalu dari kas kecil');

        $this->assertSame(1, AsistenIngatan::where('user_id', $this->owner->id)->count());
        $this->assertTrue(AsistenIngatan::whereKey($milikLain->id)->exists());
        $this->bot('/lupa semua');
        $this->assertTrue(AsistenIngatan::whereKey($milikLain->id)->exists());
    }

    public function test_batal_membatalkan_transaksi_terakhir_sekali_saja(): void
    {
        $this->claude($this->catat(50_000), $this->teks('Tercatat.'));
        $this->bot('bensin 50rb dari bca');
        $cd = CashDisbursement::sole();

        $this->assertStringContainsString('Dibatalkan', $this->bot('/batal'));
        $this->assertTrue($cd->fresh()->isVoid());
        $this->assertStringContainsString('Tidak ada transaksi terakhir', $this->bot('/batal'));
    }
}
