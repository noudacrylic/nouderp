<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Providers\FakeChatProvider;
use App\Modules\CRM\Support\TemplateResmi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengajukan template NOTIFIKASI ke Meta, dari layar Notifikasi Pesanan.
 *
 * Letaknya di sana, bukan di layar Template Pesan, dan itu inti pemisahannya:
 * bunyi notifikasi milik kode — satu kalimat berangkat lewat dua jalur (WAHA
 * merangkai teksnya, jalur resmi mengirim nama templatenya) dan keduanya wajib
 * mengucapkan hal yang sama persis. Yang bisa dilakukan dari layar cuma
 * MENGAJUKANNYA.
 *
 * Karena itu yang dijaga di sini bukan "pengajuannya berhasil", melainkan APA
 * YANG DIAJUKAN: hanya bunyi yang sudah ada di TemplateResmi, tidak pernah yang
 * khusus WAHA, dan jumlah contoh variabelnya persis sebanyak placeholder-nya.
 */
class AjukanTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function palsu(): FakeChatProvider
    {
        return app(ChatManager::class)->provider();
    }

    public function test_mengajukan_bunyi_yang_sudah_disepakati(): void
    {
        $palsu = $this->palsu();

        $this->actingAs($this->admin())
            ->post(route('crm.notifikasi.template.ajukan'), ['event' => 'jatuh_tempo'])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($p) => str_contains($p, 'jatuh_tempo'));

        $this->assertCount(1, $palsu->templateDiajukan);

        $diajukan = $palsu->templateDiajukan[0];

        // Bunyinya diambil dari TemplateResmi, bukan dari isian layar — kalau
        // boleh diketik, kalimat yang beredar bisa berbeda dari yang dipakai kode.
        $this->assertSame(TemplateResmi::body('jatuh_tempo'), $diajukan['body']);
        $this->assertSame('UTILITY', $diajukan['kategori']);
        $this->assertSame('id', $diajukan['bahasa']);
    }

    /**
     * Vendor menolak pengajuan bila jumlah `variables` tak sama dengan jumlah
     * {{n}} di body. Dijaga di sini untuk SELURUH template sekaligus, supaya
     * variabel yang ditambah besok tidak lolos tanpa contohnya.
     */
    public function test_tiap_template_membawa_contoh_sebanyak_variabelnya(): void
    {
        foreach (TemplateResmi::USULAN as $t) {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $t['body'], $m);

            $butuh = $m[1] ? max(array_map('intval', $m[1])) : 0;

            $this->assertCount(
                $butuh,
                TemplateResmi::contoh($t['nama']),
                "Template {$t['nama']} punya {$butuh} variabel tapi jumlah contohnya beda."
            );
        }
    }

    /**
     * Yang khusus WAHA tidak boleh diajukan. Nadanya mengingatkan &
     * menawarkan, jadi Meta akan menggolongkannya MARKETING — dan penggolongan
     * itu menempel pada NOMORNYA, bukan pada satu template.
     */
    public function test_template_khusus_waha_ditolak(): void
    {
        $palsu = $this->palsu();

        $this->actingAs($this->admin())
            ->post(route('crm.notifikasi.template.ajukan'), ['event' => 'stok_tersedia'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'MARKETING'));

        $this->assertCount(0, $palsu->templateDiajukan);
    }

    public function test_jenis_yang_tidak_dikenal_ditolak(): void
    {
        $palsu = $this->palsu();

        $this->actingAs($this->admin())
            ->post(route('crm.notifikasi.template.ajukan'), ['event' => 'notifikasi_hantu'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertCount(0, $palsu->templateDiajukan);
    }

    /**
     * Layar notifikasi menandai mana yang perlu diajukan & mana yang tidak
     * boleh — tanpa itu, yang mengajukan akan menekan semuanya, dan yang
     * bernada promosi menyeret seluruh nomor ke tarif & aturan MARKETING.
     */
    public function test_layar_notifikasi_menandai_yang_khusus_waha(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.notifikasi.index'))
            ->assertOk()
            ->assertSee('Ajukan ke Meta')
            ->assertSee('khusus WAHA — jangan diajukan', false);
    }

    /**
     * Bunyi notifikasi TIDAK boleh dititipkan lewat form — yang dikirim
     * ditentukan namanya, dan kalimatnya diambil dari kode.
     */
    public function test_bunyi_tidak_bisa_dititipkan_lewat_form(): void
    {
        $palsu = $this->palsu();

        $this->actingAs($this->admin())
            ->post(route('crm.notifikasi.template.ajukan'), [
                'event' => 'jatuh_tempo',
                'body'  => 'Kalimat karangan sendiri yang tak pernah disepakati.',
            ])
            ->assertRedirect();

        $this->assertSame(TemplateResmi::body('jatuh_tempo'), $palsu->templateDiajukan[0]['body']);
    }
}
