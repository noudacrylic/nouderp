<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Models\CrmWebhookEvent;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap 2 modul CRM: yang dijaga skema, bukan yang dijaga niat baik.
 *
 * Tiga hal yang diuji di sini semuanya pernah menggigit di modul lain:
 * percakapan kembar karena nomor tak dinormalkan, pesan ganda karena webhook
 * dikirim ulang, dan notifikasi terkirim dua kali karena status di-set ulang.
 */
class CrmSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function percakapan(string $kontak = '628998844666'): CrmConversation
    {
        return CrmConversation::findOrCreateFor($kontak);
    }

    public function test_nomor_dalam_bentuk_apa_pun_jatuh_ke_satu_percakapan(): void
    {
        $a = CrmConversation::findOrCreateFor('0899-8844-666');
        $b = CrmConversation::findOrCreateFor('+62 899 8844 666');
        $c = CrmConversation::findOrCreateFor('628998844666');

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame('628998844666', $a->contact_key);
        $this->assertSame(1, CrmConversation::count());
    }

    public function test_kanal_dan_nomor_bisnis_berbeda_adalah_percakapan_berbeda(): void
    {
        $wa1 = CrmConversation::findOrCreateFor('628998844666', 'whatsapp', 'nomor-1');
        $wa2 = CrmConversation::findOrCreateFor('628998844666', 'whatsapp', 'nomor-2');
        $ig  = CrmConversation::findOrCreateFor('@noudacrylic', 'instagram');

        $this->assertNotSame($wa1->id, $wa2->id);
        $this->assertSame('noudacrylic', $ig->contact_key);   // '@' dibuang
        $this->assertSame(3, CrmConversation::count());
    }

    public function test_jendela_24_jam(): void
    {
        $c = $this->percakapan();

        $this->assertFalse($c->windowIsOpen());          // belum pernah ada pesan masuk
        $this->assertNull($c->windowHoursLeft());

        $c->update(['window_expires_at' => now()->addHours(5)]);
        $this->assertTrue($c->fresh()->windowIsOpen());
        $this->assertSame(4, $c->fresh()->windowHoursLeft());

        $c->update(['window_expires_at' => now()->subMinute()]);
        $this->assertFalse($c->fresh()->windowIsOpen());
    }

    public function test_pesan_kembar_ditolak_di_tingkat_basis_data(): void
    {
        $c = $this->percakapan();

        CrmMessage::create([
            'conversation_id'     => $c->id,
            'direction'           => CrmMessage::MASUK,
            'content'             => 'halo',
            'provider_message_id' => 'msg_1',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CrmMessage::create([
            'conversation_id'     => $c->id,
            'direction'           => CrmMessage::MASUK,
            'content'             => 'halo',
            'provider_message_id' => 'msg_1',
        ]);
    }

    public function test_balasan_dari_hp_bisa_dibedakan_dari_balasan_lewat_erp(): void
    {
        $c = $this->percakapan();

        $dariHp = CrmMessage::create([
            'conversation_id' => $c->id,
            'direction'       => CrmMessage::KELUAR,
            'source'          => CrmMessage::SOURCE_WHATSAPP_APP,
            'content'         => 'iya kak',
        ]);

        $dariErp = CrmMessage::create([
            'conversation_id' => $c->id,
            'direction'       => CrmMessage::KELUAR,
            'source'          => CrmMessage::SOURCE_ERP,
            'content'         => 'baik kak',
        ]);

        $this->assertTrue($dariHp->dibalasDariHp());
        $this->assertFalse($dariErp->dibalasDariHp());
    }

    public function test_menghapus_percakapan_ikut_menghapus_pesan_dan_lampiran(): void
    {
        $c = $this->percakapan();
        $m = CrmMessage::create(['conversation_id' => $c->id, 'direction' => CrmMessage::MASUK, 'content' => 'logo']);
        CrmAttachment::create(['message_id' => $m->id, 'original_name' => 'logo.png', 'mime' => 'image/png']);

        $c->delete();

        $this->assertSame(0, CrmMessage::count());
        $this->assertSame(0, CrmAttachment::count());
    }

    public function test_lampiran_tahu_dirinya_belum_tersimpan_aman(): void
    {
        $c = $this->percakapan();
        $m = CrmMessage::create(['conversation_id' => $c->id, 'direction' => CrmMessage::MASUK]);

        $a = CrmAttachment::create([
            'message_id'        => $m->id,
            'provider_media_id' => '123',
            'mime'              => 'image/png',
        ]);

        // Selama belum terunduh, satu-satunya salinan ada di server Meta (~30 hari).
        $this->assertFalse($a->tersimpanAman());
        $this->assertSame(1, CrmAttachment::belumTerunduh()->count());

        $a->update(['disk' => 's3', 'path' => 'crm/2026/logo.png', 'downloaded_at' => now()]);

        $this->assertTrue($a->fresh()->tersimpanAman());
        $this->assertSame(0, CrmAttachment::belumTerunduh()->count());
    }

    public function test_webhook_yang_dikirim_ulang_hanya_tercatat_sekali(): void
    {
        $key = 'msg_1_message.received_29301234';

        $pertama = CrmWebhookEvent::catatBaru($key, 'uuid-1', 'message.received', ['a' => 1]);
        $kedua   = CrmWebhookEvent::catatBaru($key, 'uuid-1', 'message.received', ['a' => 1]);

        $this->assertNotNull($pertama);
        $this->assertNull($kedua, 'Kiriman ulang harus dikenali sebagai duplikat, bukan baris baru.');
        $this->assertSame(1, CrmWebhookEvent::count());

        $pertama->tandaiSelesai();
        $this->assertNotNull($pertama->fresh()->processed_at);
    }

    public function test_satu_peristiwa_hanya_menghasilkan_satu_notifikasi(): void
    {
        $so = $this->salesOrder();

        $pertama = CrmOutboxMessage::antrekan("so:{$so->id}:siap_diambil", [
            'event'          => CrmOutboxMessage::EVENT_SIAP_AMBIL,
            'sales_order_id' => $so->id,
            'recipient'      => '628998844666',
            'template_name'  => CrmOutboxMessage::TEMPLATES[CrmOutboxMessage::EVENT_SIAP_AMBIL],
            'template_body'  => ['Budi', $so->order_number, 'AMB-1', 'Senin–Sabtu 08.00–16.00'],
        ]);

        // Status di-set ulang (mis. operator menekan tombol dua kali).
        $kedua = CrmOutboxMessage::antrekan("so:{$so->id}:siap_diambil", [
            'event'          => CrmOutboxMessage::EVENT_SIAP_AMBIL,
            'sales_order_id' => $so->id,
        ]);

        $this->assertNotNull($pertama);
        $this->assertNull($kedua);
        $this->assertSame(1, CrmOutboxMessage::count());
        $this->assertSame('pesanan_siap_diambil', $pertama->template_name);
        $this->assertSame('Budi', $pertama->template_body[0]);
    }

    public function test_pembayaran_berulang_boleh_dua_notifikasi_karena_kuncinya_berbeda(): void
    {
        $so = $this->salesOrder();

        $dp    = CrmOutboxMessage::antrekan("so:{$so->id}:pembayaran:1", ['event' => CrmOutboxMessage::EVENT_PEMBAYARAN, 'sales_order_id' => $so->id]);
        $lunas = CrmOutboxMessage::antrekan("so:{$so->id}:pembayaran:2", ['event' => CrmOutboxMessage::EVENT_PEMBAYARAN, 'sales_order_id' => $so->id]);

        $this->assertNotNull($dp);
        $this->assertNotNull($lunas);
        $this->assertSame(2, CrmOutboxMessage::count());
    }

    public function test_antrean_jatuh_tempo_menghormati_jam_sopan(): void
    {
        $so = $this->salesOrder();

        CrmOutboxMessage::antrekan('a', ['event' => 'siap_diambil', 'sales_order_id' => $so->id]);
        CrmOutboxMessage::antrekan('b', ['event' => 'siap_diambil', 'sales_order_id' => $so->id, 'scheduled_at' => now()->subMinute()]);
        // Datang pukul 23.00, ditunda ke jam kerja besok pagi.
        CrmOutboxMessage::antrekan('c', ['event' => 'siap_diambil', 'sales_order_id' => $so->id, 'scheduled_at' => now()->addHours(9)]);

        $this->assertEqualsCanonicalizing(['a', 'b'], CrmOutboxMessage::jatuhTempo()->pluck('dedupe_key')->all());
    }

    public function test_notifikasi_yang_dilewati_tetap_meninggalkan_alasan(): void
    {
        $so   = $this->salesOrder();
        $baris = CrmOutboxMessage::antrekan("so:{$so->id}:dikirim", ['event' => 'dikirim', 'sales_order_id' => $so->id]);

        $baris->tandaiDilewati('Pesanan marketplace — nomor pembeli adalah nomor proxy.');

        $this->assertSame(CrmOutboxMessage::STATUS_DILEWATI, $baris->fresh()->status);
        $this->assertStringContainsString('marketplace', $baris->fresh()->reason);
        $this->assertSame(0, CrmOutboxMessage::jatuhTempo()->count());
    }

    public function test_pesanan_dihapus_tidak_menghapus_jejak_notifikasi(): void
    {
        $so = $this->salesOrder();
        CrmOutboxMessage::antrekan('x', ['event' => 'dikirim', 'sales_order_id' => $so->id]);

        $so->delete();

        $baris = CrmOutboxMessage::first();
        $this->assertNotNull($baris, 'Jejak notifikasi harus bertahan meski pesanannya hilang.');
        $this->assertNull($baris->sales_order_id);
    }

    private function salesOrder(): SalesOrder
    {
        $cust = Customer::create([
            'code' => 'CUST-' . uniqid(), 'name' => 'Budi', 'is_marketplace' => false, 'is_active' => true,
        ]);
        $wh = Warehouse::firstOrCreate(['name' => 'Gudang Uji CRM']);

        return SalesOrder::create([
            'order_number' => 'SO-CRM-' . uniqid(),
            'customer_id'  => $cust->id,
            'warehouse_id' => $wh->id,
            'order_date'   => now()->toDateString(),
            'status'       => 'confirmed',
            'grand_total'  => 100000,
        ]);
    }
}
