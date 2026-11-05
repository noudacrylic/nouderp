<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Jam pesanan Shopee lama tersimpan dalam UTC, mundur 7 jam. Dihitung ulang dari timestamp asli
     * Shopee di kolom raw, bukan digeser +7 jam, jadi aman bila terjalankan lebih dari sekali.
     */
    public function up(): void
    {
        $zona = config('app.timezone');

        DB::table('shopee_orders')->select('id', 'raw')->orderBy('id')->chunkById(500, function ($baris) use ($zona) {
            foreach ($baris as $o) {
                $raw  = json_decode((string) $o->raw, true) ?: [];
                $ubah = [];
                if (!empty($raw['create_time'])) {
                    $ubah['dibuat_shopee_at'] = Carbon::createFromTimestamp((int) $raw['create_time'], $zona)->format('Y-m-d H:i:s');
                }
                if (!empty($raw['update_time'])) {
                    $ubah['diperbarui_shopee_at'] = Carbon::createFromTimestamp((int) $raw['update_time'], $zona)->format('Y-m-d H:i:s');
                }
                if ($ubah) {
                    DB::table('shopee_orders')->where('id', $o->id)->update($ubah);
                }
            }
        });
    }

    /** Data jam yang salah tidak dikembalikan. */
    public function down(): void
    {
    }
};
