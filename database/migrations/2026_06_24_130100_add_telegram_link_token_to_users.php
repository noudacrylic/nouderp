<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token sekali-pakai untuk menghubungkan akun Telegram user via deep-link
 * (t.me/<bot>?start=<token>). Webhook mencocokkan token → user → simpan chat_id,
 * lalu token dikosongkan. Nullable & unik.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_link_token');
        });
    }
};
