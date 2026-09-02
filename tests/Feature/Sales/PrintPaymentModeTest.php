<?php

namespace Tests\Feature\Sales;

use App\Models\MidtransSetting;
use App\Support\PrintPaymentInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pilihan info pembayaran yang tercetak di SO/Faktur.
 *
 * Setting Midtrans cuma menentukan BAWAAN; tiap cetakan boleh menukarnya (perusahaan
 * besar minta nomor rekening). Yang dijaga di sini: mode selalu dikunci ke data yang
 * benar-benar ada, supaya dokumen tidak pernah mencetak blok kosong.
 */
class PrintPaymentModeTest extends TestCase
{
    use RefreshDatabase;

    private function setting(bool $showLinkByDefault): void
    {
        MidtransSetting::singleton()->update(['show_payment_method' => $showLinkByDefault]);
    }

    public function test_bawaan_ikut_setting_midtrans(): void
    {
        $this->setting(true);
        $this->assertSame('link', PrintPaymentInfo::mode(hasLink: true, hasBank: true));

        $this->setting(false);
        $this->assertSame('bank', PrintPaymentInfo::mode(hasLink: true, hasBank: true));
    }

    public function test_pilihan_cetakan_mengalahkan_setting(): void
    {
        $this->setting(true);

        $this->assertSame('bank', PrintPaymentInfo::mode(true, true, 'rekening'));
        $this->assertSame('both', PrintPaymentInfo::mode(true, true, 'dua'));
        $this->assertSame('none', PrintPaymentInfo::mode(true, true, 'tanpa'));
        // Nilai asing diabaikan → kembali ke bawaan.
        $this->assertSame('link', PrintPaymentInfo::mode(true, true, 'ngawur'));
    }

    public function test_mode_dikunci_ke_data_yang_ada(): void
    {
        $this->setting(true);

        // Rekening belum diisi → jangan cetak blok kosong, pakai link.
        $this->assertSame('link', PrintPaymentInfo::mode(true, false, 'rekening'));
        // Tidak ada link pembayaran → jatuh ke rekening.
        $this->assertSame('bank', PrintPaymentInfo::mode(false, true, 'link'));
        // "Keduanya" tapi cuma satu yang ada → cetak yang ada saja.
        $this->assertSame('bank', PrintPaymentInfo::mode(false, true, 'dua'));
        // Dokumen lunas: tidak ada apa pun untuk dicetak.
        $this->assertSame('none', PrintPaymentInfo::mode(false, false, 'link'));
    }
}
