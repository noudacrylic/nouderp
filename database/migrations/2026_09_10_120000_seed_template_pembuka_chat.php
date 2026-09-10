<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dua template PEMBUKA CHAT pindah dari kode ke tabel.
 *
 * Keduanya tidak pernah dikirim kode. Manusialah yang memilihnya dari layar
 * Chat Baru, satu nomor pada satu waktu — dan itulah yang membedakannya dari
 * notifikasi. Selama bunyinya duduk di TemplateResmi, mengubah satu kata berarti
 * menyunting berkas PHP, padahal tak ada satu pun alasan teknis untuk itu:
 * bunyi notifikasi terkunci di kode karena jalur WAHA & jalur resmi wajib
 * mengucapkan kalimat yang sama persis, dan kedua template ini tidak punya
 * jalur WAHA sama sekali.
 *
 * `meta_status` sengaja DIBIARKAN KOSONG, bukan ditebak 'APPROVED'. Apakah
 * keduanya sudah disetujui adalah fakta yang hidup di Meta; layar Template
 * menanyakannya ke vendor dan mengisi sendiri. Menebak di sini berarti tombol
 * "Ajukan" hilang untuk template yang ternyata belum pernah diajukan.
 */
return new class extends Migration
{
    private const BAWAAN = [
        [
            'title'     => 'Sapaan umum',
            'meta_name' => 'sapa_umum',
            'category'  => 'Pembuka chat',
            'body'      => 'Selamat siang Kak, kami dari Noud Acrylic. Kami ingin melanjutkan pembahasan pesanan Kakak. Mohon balas pesan ini ya.',
            'vars'      => [],
        ],
        [
            'title'     => 'Konfirmasi desain',
            'meta_name' => 'konfirmasi_desain',
            'category'  => 'Pembuka chat',
            'body'      => 'Halo Kak {{1}}, kami dari Noud Acrylic ingin mengonfirmasi desain untuk pesanan {{2}}. Mohon balas pesan ini agar kami kirimkan pratinjaunya.',
            'vars'      => ['Budi', 'SO-2609-0012'],
        ],
    ];

    public function up(): void
    {
        foreach (self::BAWAAN as $i => $t) {
            if (DB::table('crm_templates')->where('meta_name', $t['meta_name'])->exists()) {
                continue;
            }

            DB::table('crm_templates')->insert([
                'title'          => $t['title'],
                'body'           => $t['body'],
                'category'       => $t['category'],
                'meta_name'      => $t['meta_name'],
                'meta_variables' => json_encode($t['vars']),
                'sort_order'     => $i,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('crm_templates')
            ->whereIn('meta_name', array_column(self::BAWAAN, 'meta_name'))
            ->delete();
    }
};
