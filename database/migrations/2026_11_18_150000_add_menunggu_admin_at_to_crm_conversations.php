<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda "Menunggu admin" (18 Nov 2026): AI melempar chat ke admin, tapi tanda belum dibaca
 * yang dipasangnya terhapus oleh balasan AI berikutnya (ucapan penutup) dan oleh penyelarasan
 * status baca Seller Centre — chat lemparan semalam lenyap dari daftar admin pagi harinya.
 * Kolom ini hanya dikosongkan oleh balasan MANUSIA atau saat chat ditutup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_conversations', 'menunggu_admin_at')) {
            Schema::table('crm_conversations', function (Blueprint $t) {
                $t->timestamp('menunggu_admin_at')->nullable()->after('unread_count')->index();
            });
        }

        // Isi awal: chat aktif yang lemparan AI terkirim terakhirnya belum dibalas manusia.
        $lemparan = DB::table('crm_saran_ai')
            ->where('otomatis', 'terkirim')
            ->whereNotNull('lempar_ke')
            ->where('diputus_at', '>=', now()->subDays(14))
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, MAX(diputus_at) AS terakhir')
            ->get();

        foreach ($lemparan as $l) {
            $aktif = DB::table('crm_conversations')->where('id', $l->conversation_id)
                ->where('status', 'aktif')->whereNull('menunggu_admin_at')->exists();
            if (!$aktif) {
                continue;
            }
            $dibalasManusia = DB::table('crm_messages')
                ->where('conversation_id', $l->conversation_id)
                ->where('direction', 'keluar')
                ->where('sent_at', '>', $l->terakhir)
                ->where(fn ($q) => $q->whereNull('source')->orWhereNotIn('source', ['ai', 'shopee_otomatis']))
                ->exists();
            if (!$dibalasManusia) {
                DB::table('crm_conversations')->where('id', $l->conversation_id)
                    ->update(['menunggu_admin_at' => $l->terakhir]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_conversations', 'menunggu_admin_at')) {
            Schema::table('crm_conversations', function (Blueprint $t) {
                $t->dropIndex(['menunggu_admin_at']);
                $t->dropColumn('menunggu_admin_at');
            });
        }
    }
};
