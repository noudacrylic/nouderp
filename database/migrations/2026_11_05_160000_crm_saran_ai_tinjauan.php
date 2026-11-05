<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LAMA = <<<'TXT'
- Pakai kalimat contoh dari butir yang cocok. Boleh disesuaikan sedikit dengan
  pertanyaannya, tapi isinya jangan diubah. Kalimat dari daftar boleh lebih dari 3 baris.
- Kalau pertanyaannya tidak ada di daftar, atau butirnya bilang lempar: usulkan
  lempar ke admin/owner sesuai butirnya (tidak ada → admin), dengan balasan sementara
  bila butirnya menyediakannya.
TXT;

    private const BARU = <<<'TXT'
- Sampaikan isi butir yang cocok sesingkat mungkin, mengikuti bagian "Gaya balasan" di pengetahuan.
- Kalau pertanyaannya tidak ada di daftar, atau butirnya bilang lempar: isi `lempar_ke` menurut
  pengetahuan, dengan balasan sementara yang singkat.
- Bagian "Koreksi dari owner" menang bila bertabrakan dengan butir daftar.
TXT;

    /**
     * Pemeriksaan manual owner atas saran AI. Persona lama menyuruh menyalin kalimat contoh dan
     * membolehkan lebih dari 3 baris, bertabrakan dengan gaya singkat; persona yang sudah disunting
     * dari layar tidak disentuh.
     */
    public function up(): void
    {
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->string('tinjauan', 10)->nullable()->after('catatan');   // null | tepat | salah
            $table->text('koreksi')->nullable()->after('tinjauan');         // yang benar menurut owner
            $table->foreignId('ditinjau_oleh')->nullable()->after('koreksi')->constrained('users')->nullOnDelete();
            $table->timestamp('ditinjau_at')->nullable()->after('ditinjau_oleh');
            $table->index('tinjauan');
        });

        $agen = DB::table('crm_agents')->where('kode', 'cs-shopee')->first(['id', 'persona']);
        if ($agen && str_contains(str_replace("\r\n", "\n", (string) $agen->persona), self::LAMA)) {
            DB::table('crm_agents')->where('id', $agen->id)->update([
                'persona' => str_replace(self::LAMA, self::BARU, str_replace("\r\n", "\n", (string) $agen->persona)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_saran_ai', function (Blueprint $table) {
            $table->dropForeign(['ditinjau_oleh']);
            $table->dropIndex(['tinjauan']);
            $table->dropColumn(['tinjauan', 'koreksi', 'ditinjau_oleh', 'ditinjau_at']);
        });
    }
};
