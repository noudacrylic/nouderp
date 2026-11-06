<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill "Prioritas & pengganti admin" (6 Nov 2026):
 *  - tugas_owner.prioritas: utama | normal | rendah — owner menentukan letak tugasnya di meja admin.
 *  - crm_conversations.dioper_dari_user_id: chat yang dioper karena pemegangnya tidak berangkat,
 *    supaya bisa dikembalikan begitu ia hadir (chat yang dioper manual tidak ditandai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tugas_owner', function (Blueprint $t) {
            $t->string('prioritas', 10)->default('normal')->after('tenggat');
        });
        Schema::table('crm_conversations', function (Blueprint $t) {
            $t->foreignId('dioper_dari_user_id')->nullable()->after('owner_user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('dioper_dari_user_id');
        });
        Schema::table('tugas_owner', function (Blueprint $t) {
            $t->dropColumn('prioritas');
        });
    }
};
