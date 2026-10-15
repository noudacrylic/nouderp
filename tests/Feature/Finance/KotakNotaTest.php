<?php

namespace Tests\Feature\Finance;

use App\Core\Accounting\Account;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Modules\Finance\Models\CashDisbursement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kotak Nota: foto dari PWA HP masuk tanpa dokumen, lalu ditempel dari ERP.
 * Yang dijaga: menu Nota hanya untuk yang boleh membuka dokumen pembelian /
 * pengeluaran (bukan CS khusus chat), foto sekali tempel tidak bisa direbut
 * dokumen lain, dan pengirim hanya melihat / membatalkan kirimannya sendiri.
 */
class KotakNotaTest extends TestCase
{
    use RefreshDatabase;

    private Account $kas;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->kas = Account::firstOrCreate(['code' => '1101'], ['name' => 'Kas', 'type' => 'asset']);
    }

    /** Akun PWA (flag pwa_crm) dengan menu tertentu. */
    private function akunPwa(array $menus = []): User
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
        foreach ($menus as $m) {
            $u->menuPermissions()->create(['menu_key' => $m]);
        }
        return $u->fresh();
    }

    private function pengeluaran(string $nomor): CashDisbursement
    {
        return CashDisbursement::create([
            'number' => $nomor, 'date' => now()->toDateString(), 'type' => 'general',
            'cash_account_id' => $this->kas->id, 'total' => 25000, 'status' => 'posted',
        ]);
    }

    private function kirimDariHp(User $u, string $catatan = 'Toko Lem 25rb'): DocumentAttachment
    {
        $this->actingAs($u)->post(route('cs.nota.store'), [
            'catatan' => $catatan,
            'nota'    => [UploadedFile::fake()->image('nota.jpg', 1200, 900)],
        ])->assertRedirect(route('cs.nota'))->assertSessionHas('success');

        return DocumentAttachment::where('uploaded_by', $u->id)->latest('id')->firstOrFail();
    }

    public function test_foto_dari_hp_masuk_kotak_tanpa_dokumen(): void
    {
        $u = $this->akunPwa(['cash-bank.disbursements']);

        $this->actingAs($u)->get(route('cs.profil'))->assertOk()->assertSee(route('cs.nota'), false);

        $att = $this->kirimDariHp($u);
        $this->assertNull($att->doc_type);
        $this->assertNull($att->doc_id);
        $this->assertSame('Toko Lem 25rb', $att->catatan);
        Storage::disk('local')->assertExists($att->path);

        $this->actingAs($u)->get(route('cs.nota'))->assertOk()
            ->assertSee('Menunggu ditempel')->assertSee('Toko Lem 25rb');
    }

    public function test_cs_khusus_chat_tidak_melihat_menu_nota(): void
    {
        $cs = $this->akunPwa(['crm.inbox']);

        $this->actingAs($cs)->get(route('cs.profil'))->assertOk()->assertDontSee(route('cs.nota'), false);
        $this->actingAs($cs)->get(route('cs.nota'))->assertForbidden();
        $this->actingAs($cs)->post(route('cs.nota.store'), ['nota' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertForbidden();
        $this->actingAs($cs)->get(route('nota-kotak.index'))->assertForbidden();
        $this->assertSame(0, DocumentAttachment::count());
    }

    public function test_tempel_dari_halaman_dokumen_dan_tidak_bisa_ditempel_dua_kali(): void
    {
        $u    = $this->akunPwa(['cash-bank.disbursements']);
        $att  = $this->kirimDariHp($u);
        $cd   = $this->pengeluaran('CD-0001');
        $lain = $this->pengeluaran('CD-0002');

        // Muncul di panel "Ambil dari HP" halaman dokumen.
        $this->actingAs($u)->get(route('finance.cash-bank.disbursements.show', $cd->id))
            ->assertOk()->assertSee('Ambil dari HP')->assertSee(route('nota-kotak.gambar', $att->id), false);

        $this->actingAs($u)->post(route('finance.cash-bank.disbursements.nota.tempel', $cd->id), ['lampiran_id' => $att->id])
            ->assertSessionHas('success');

        $att->refresh();
        $this->assertSame('cash_disbursement', $att->doc_type);
        $this->assertSame($cd->id, $att->doc_id);
        $this->assertSame($u->id, $att->ditempel_by);
        $this->assertSame(1, $cd->fotoNota()->count());

        // Sudah keluar dari kotak → dokumen lain tidak bisa merebutnya.
        $this->actingAs($u)->post(route('finance.cash-bank.disbursements.nota.tempel', $lain->id), ['lampiran_id' => $att->id])
            ->assertNotFound();
        $this->assertSame($cd->id, $att->fresh()->doc_id);

        // Riwayat di HP menunjukkan ke dokumen mana fotonya menempel.
        $this->actingAs($u)->get(route('cs.nota'))->assertOk()->assertSee('✓ Pengeluaran CD-0001');
    }

    public function test_pengirim_hanya_bisa_melihat_dan_membatalkan_kirimannya_sendiri(): void
    {
        $budi = $this->akunPwa(['cash-bank.disbursements']);
        $ani  = $this->akunPwa(['purchasing.invoices']);
        $att  = $this->kirimDariHp($budi);

        $this->actingAs($ani)->get(route('cs.nota.gambar', $att->id))->assertNotFound();
        $this->actingAs($ani)->delete(route('cs.nota.destroy', $att->id))->assertNotFound();

        $this->actingAs($budi)->get(route('cs.nota.gambar', $att->id))->assertOk();
        $this->actingAs($budi)->delete(route('cs.nota.destroy', $att->id))->assertRedirect(route('cs.nota'));
        $this->assertSoftDeleted('document_attachments', ['id' => $att->id]);
    }

    public function test_kotak_nota_di_erp_menampilkan_dan_menghapus(): void
    {
        $u   = $this->akunPwa(['purchasing.invoices']);
        $att = $this->kirimDariHp($u, 'Toko Baut');

        $this->actingAs($u)->get(route('nota-kotak.index'))->assertOk()->assertSee('Toko Baut');
        $this->actingAs($u)->get(route('nota-kotak.gambar', $att->id))->assertOk();
        $this->actingAs($u)->get(route('purchasing.invoices.index'))->assertOk()
            ->assertSee('foto nota dari HP belum ditempel');

        $this->actingAs($u)->delete(route('nota-kotak.destroy', $att->id))->assertSessionHas('success');
        $this->assertSoftDeleted('document_attachments', ['id' => $att->id]);
        $this->actingAs($u)->get(route('nota-kotak.index'))->assertOk()->assertSee('Kotak kosong');
    }
}
