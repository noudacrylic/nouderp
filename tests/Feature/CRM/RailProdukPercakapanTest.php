<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel Produk perlu tahu chat mana yang sedang dibuka.
 *
 * Separuh isinya menggantung pada itu — kirim pesan, kirim foto, tandai
 * titipan. Kalau id-nya tidak sampai, tombol-tombolnya lenyap tanpa satu pun
 * pesan galat, dan yang terjadi di lapangan adalah orang mengira fiturnya
 * hilang. Karena itu jalur datanya dijaga di sini, bukan cuma dipercaya.
 */
class RailProdukPercakapanTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_percakapan_sampai_ke_panel_saat_chat_dibuka(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $chat = CrmConversation::create([
            'channel'     => 'whatsapp',
            'contact_key' => '628111111111',
            'status'      => CrmConversation::STATUS_AKTIF,
        ]);

        $this->actingAs($admin)
            ->get(route('crm.inbox.show', $chat))
            ->assertOk()
            ->assertSee('panelProduk(' . $chat->id . ')', false);
    }

    /** Tanpa chat terpilih panel tetap tampil, tapi keterbatasannya DIKATAKAN. */
    public function test_tanpa_chat_panel_mengatakan_keterbatasannya(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('panelProduk(null)', false)
            ->assertSee('Buka satu percakapan dulu', false);
    }
}
