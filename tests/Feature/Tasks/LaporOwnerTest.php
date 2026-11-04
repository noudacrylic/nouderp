<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Tasks\LaporOwner\LaporOwner;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaporOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => 'TOKEN-UJI']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => '5001']);
        $this->admin = User::factory()->create(['role' => 'user', 'is_active' => true, 'telegram_chat_id' => '5002']);
        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
        MejaKerjaPeran::create(['peran' => 'layanan', 'user_id' => $this->admin->id, 'urutan' => 1]);
    }

    private function terkirimKe(string $chatId, string $teks): bool
    {
        return Http::recorded(fn (HttpRequest $r) => ($r['chat_id'] ?? null) === $chatId && str_contains((string) ($r['text'] ?? ''), $teks))->isNotEmpty();
    }

    public function test_laporan_admin_langsung_jadi_kartu_meja_owner_dan_telegram(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.meja.index'))->assertSee('Lapor ke Owner');
        $this->post(route('tasks.meja.lapor.store'), ['isi' => 'Saldo ShopeePay belum diisi 2 hari', 'penting' => '1'])->assertRedirect();

        $t = Task::where('skill', 'lapor-owner')->firstOrFail();
        $this->assertSame('owner', $t->peran);
        $this->assertSame($this->owner->id, $t->assignee_user_id);
        $this->assertTrue($this->terkirimKe('5001', 'Saldo ShopeePay belum diisi 2 hari'));

        $this->actingAs($this->owner)->get(route('tasks.meja.index'))
            ->assertSee('⚠️ Laporan - Saldo ShopeePay belum diisi 2 hari', false)->assertSee('Kirim balasan');
    }

    public function test_balasan_owner_sampai_ke_pelapor_dan_kartu_selesai(): void
    {
        $this->actingAs($this->admin)->post(route('tasks.meja.lapor.store'), ['isi' => 'Transaksi kemarin belum tercatat']);
        $lapor = LaporOwner::firstOrFail();

        $this->actingAs($this->owner)->post(route('tasks.meja.lapor.balas', $lapor->id), ['teks' => 'Sudah saya cek, catat hari ini'])->assertRedirect();

        $this->assertSame('done', Task::where('skill', 'lapor-owner')->value('status'));
        $this->assertTrue($this->terkirimKe('5002', 'Sudah saya cek, catat hari ini'));
        $this->assertDatabaseHas('erp_notifications', ['user_id' => $this->admin->id, 'judul' => 'Balasan owner']);

        $this->post(route('tasks.meja.lapor.balas', $lapor->id), ['teks' => 'Lagi'])->assertStatus(422);
    }

    public function test_hanya_admin_yang_melapor_dan_hanya_owner_yang_membalas(): void
    {
        $lain = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($lain)->get(route('tasks.meja.index'))->assertDontSee('Lapor ke Owner');
        $this->post(route('tasks.meja.lapor.store'), ['isi' => 'x'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('tasks.meja.lapor.store'), ['isi' => 'Halo']);
        $lapor = LaporOwner::firstOrFail();
        $this->post(route('tasks.meja.lapor.balas', $lapor->id), ['teks' => 'x'])->assertForbidden();
    }

    public function test_foto_hanya_untuk_pelapor_dan_owner(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->post(route('tasks.meja.lapor.store'), ['isi' => 'Ada foto', 'foto' => UploadedFile::fake()->image('a.jpg')])->assertRedirect();
        $lapor = LaporOwner::firstOrFail();

        $this->get(route('tasks.meja.lapor.foto', $lapor->id))->assertOk();
        $this->actingAs($this->owner)->get(route('tasks.meja.index'))->assertSee('Lihat foto');
        $this->get(route('tasks.meja.lapor.foto', $lapor->id))->assertOk();

        $lain = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($lain)->get(route('tasks.meja.lapor.foto', $lapor->id))->assertForbidden();
    }

    public function test_sinkron_membuat_kartu_yang_terlewat_tanpa_dobel(): void
    {
        LaporOwner::create(['pelapor_id' => $this->admin->id, 'isi' => 'Terlewat']);
        app(MejaKerjaService::class)->sinkron();
        app(MejaKerjaService::class)->sinkron();

        $this->assertSame(1, Task::where('skill', 'lapor-owner')->count());
    }
}
