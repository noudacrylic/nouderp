<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan virtual Asisten Owner: skill persetujuan usulan pindah dari Manajer Admin (mode &
 * jurnalnya ikut), dan Meja Owner kini hanya diisi Asisten Owner — skill Manajer Admin yang
 * pernah diarahkan ke sana kembali ke meja bawaannya.
 */
return new class extends Migration
{
    private const LAMA = 'admin.persetujuan-usulan';
    private const BARU = 'owner.persetujuan-usulan';
    private const MEJA_DESAIN = ['desain', 'foto-custom', 'cetak-stiker'];

    public function up(): void
    {
        Schema::table('sdm_izin_requests', function (Blueprint $t) {
            $t->json('telegram')->nullable()->after('override_ids');
        });

        DB::table('karyawan_virtual_skills')->where('skill', self::LAMA)->update(['skill' => self::BARU]);
        DB::table('karyawan_virtual_jurnal')->where('skill', self::LAMA)->update(['skill' => self::BARU, 'karyawan' => 'asisten-owner']);

        DB::table('meja_kerja_skill')->where('skill', 'persetujuan-usulan')->update(['peran' => 'owner']);
        DB::table('tasks')->where('skill', 'persetujuan-usulan')->whereIn('status', ['open', 'in_progress', 'pending'])->update(['peran' => 'owner']);

        foreach (DB::table('meja_kerja_skill')->where('peran', 'owner')->where('skill', '!=', 'persetujuan-usulan')->pluck('skill') as $skill) {
            $meja = in_array($skill, self::MEJA_DESAIN, true) ? 'desain' : 'layanan';
            DB::table('meja_kerja_skill')->where('skill', $skill)->update(['peran' => $meja]);
            DB::table('tasks')->where('skill', $skill)->where('peran', 'owner')->whereIn('status', ['open', 'pending'])
                ->update(['peran' => $meja, 'assignee_user_id' => null]);
        }
    }

    public function down(): void
    {
        DB::table('karyawan_virtual_jurnal')->where('skill', self::BARU)->update(['skill' => self::LAMA, 'karyawan' => 'manajer-admin']);
        DB::table('karyawan_virtual_skills')->where('skill', self::BARU)->update(['skill' => self::LAMA]);

        Schema::table('sdm_izin_requests', function (Blueprint $t) {
            $t->dropColumn('telegram');
        });
    }
};
