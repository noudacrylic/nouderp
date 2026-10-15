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
 * Foto nota menggantikan nota kertas yang dibuang, jadi yang dijaga di sini:
 * berkas benar-benar tersimpan (dan diperkecil), tetap bisa ditambah setelah
 * POST, hanya terbuka lewat route yang dijaga hak menu dokumennya, dan
 * "hapus" tidak pernah membuang berkas.
 */
class FotoNotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Account $kas;
    private Account $beban;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->kas   = Account::firstOrCreate(['code' => '1101'], ['name' => 'Kas', 'type' => 'asset']);
        $this->beban = Account::firstOrCreate(['code' => '6101'], ['name' => 'Beban Lain', 'type' => 'expense']);
    }

    private function pengeluaran(string $status = 'posted', string $nomor = 'CD-0001'): CashDisbursement
    {
        return CashDisbursement::create([
            'number' => $nomor, 'date' => now()->toDateString(), 'type' => 'general',
            'cash_account_id' => $this->kas->id, 'payee' => 'Toko Akrilik', 'total' => 150000, 'status' => $status,
        ]);
    }

    public function test_foto_diunggah_ke_dokumen_yang_sudah_posted_dan_diperkecil(): void
    {
        $cd = $this->pengeluaran('posted');

        $this->actingAs($this->admin)
            ->from(route('finance.cash-bank.disbursements.show', $cd->id))
            ->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
                'nota' => [
                    UploadedFile::fake()->image('nota-besar.jpg', 4000, 3000)->size(4000),
                    UploadedFile::fake()->create('bukti-transfer.pdf', 300, 'application/pdf'),
                ],
            ])
            ->assertRedirect(route('finance.cash-bank.disbursements.show', $cd->id))
            ->assertSessionHas('success', '2 foto nota tersimpan.');

        $lampiran = $cd->fotoNota()->orderBy('id')->get();
        $this->assertCount(2, $lampiran);

        [$foto, $pdf] = $lampiran;
        $this->assertSame($this->admin->id, $foto->uploaded_by);
        $this->assertStringStartsWith('nota/' . now()->format('Y/m') . '/', $foto->path);
        $this->assertSame($foto->id . '.jpg', basename($foto->path), 'Nama berkas = id, bukan nama dari klien');
        Storage::disk('local')->assertExists($foto->path);

        [$lebar, $tinggi] = getimagesize(Storage::disk('local')->path($foto->path));
        $this->assertSame(1600, max($lebar, $tinggi), 'Foto 4000px harus diperkecil ke sisi 1600px');

        $this->assertSame('application/pdf', $pdf->mime);
        Storage::disk('local')->assertExists($pdf->path);

        // Tampil di halaman detail & bertanda 📎 di daftar.
        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.show', $cd->id))
            ->assertOk()->assertSee('Foto Nota')->assertSee(route('finance.cash-bank.disbursements.nota.lihat', [$cd->id, $foto->id]), false);
        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.general'))
            ->assertOk()->assertSee('📎2', false);
    }

    public function test_berkas_hanya_terbuka_lewat_dokumen_pemiliknya(): void
    {
        $cd   = $this->pengeluaran('posted', 'CD-0001');
        $lain = $this->pengeluaran('posted', 'CD-0002');
        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
            'nota' => [UploadedFile::fake()->image('a.png', 200, 200)],
        ]);
        $att = $cd->fotoNota()->first();

        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.nota.lihat', [$cd->id, $att->id]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        // id lampiran benar tapi ditempel di dokumen lain → 404, bukan bocor.
        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.nota.lihat', [$lain->id, $att->id]))
            ->assertNotFound();
        // Jenis dokumen lain (faktur pembelian) dengan id yang sama → 404.
        $this->actingAs($this->admin)->get(route('purchasing.invoices.nota.lihat', [$cd->id, $att->id]))
            ->assertNotFound();
    }

    public function test_akun_tanpa_hak_menu_pengeluaran_ditolak(): void
    {
        $cd   = $this->pengeluaran();
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($user)->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
            'nota' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertForbidden();
        $this->assertSame(0, DocumentAttachment::count());
    }

    public function test_hapus_hanya_mengarsipkan_berkas_tetap_ada(): void
    {
        $cd = $this->pengeluaran();
        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
            'nota' => [UploadedFile::fake()->image('a.jpg')],
        ]);
        $att = $cd->fotoNota()->first();

        $this->actingAs($this->admin)
            ->delete(route('finance.cash-bank.disbursements.nota.destroy', [$cd->id, $att->id]))
            ->assertSessionHas('success');

        $this->assertSame(0, $cd->fotoNota()->count());
        $arsip = DocumentAttachment::withTrashed()->find($att->id);
        $this->assertNotNull($arsip->deleted_at);
        $this->assertSame($this->admin->id, $arsip->deleted_by);
        Storage::disk('local')->assertExists($arsip->path);
    }

    public function test_draft_dihapus_fotonya_ikut_diarsipkan(): void
    {
        $cd = $this->pengeluaran('draft');
        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
            'nota' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $cd->delete();

        $this->assertSame(0, DocumentAttachment::count());
        $this->assertSame(1, DocumentAttachment::onlyTrashed()->count());
    }

    public function test_format_selain_gambar_dan_pdf_ditolak(): void
    {
        $cd = $this->pengeluaran();

        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.nota.store', $cd->id), [
            'nota' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('nota.0');

        $this->assertSame(0, DocumentAttachment::count());
    }

    public function test_foto_dari_form_buat_pengeluaran_ikut_tersimpan(): void
    {
        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.store'), [
            'date' => now()->toDateString(),
            'type' => 'general',
            'cash_account_id' => $this->kas->id,
            'payee' => 'Toko Lem',
            'lines' => [['account_id' => $this->beban->id, 'amount' => 25000, 'description' => 'Lem']],
            'nota' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $cd = CashDisbursement::where('payee', 'Toko Lem')->sole();
        $this->assertSame(1, $cd->fotoNota()->count());
    }

    public function test_filter_belum_ada_nota_di_daftar(): void
    {
        $dengan = $this->pengeluaran('posted', 'CD-ADA-NOTA');
        $this->pengeluaran('posted', 'CD-TANPA-NOTA');
        $this->actingAs($this->admin)->post(route('finance.cash-bank.disbursements.nota.store', $dengan->id), [
            'nota' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.general', ['nota' => 'belum']))
            ->assertOk()->assertSee('CD-TANPA-NOTA')->assertDontSee('CD-ADA-NOTA');
        $this->actingAs($this->admin)->get(route('finance.cash-bank.disbursements.general', ['nota' => 'ada']))
            ->assertOk()->assertSee('CD-ADA-NOTA')->assertDontSee('CD-TANPA-NOTA');
    }
}
