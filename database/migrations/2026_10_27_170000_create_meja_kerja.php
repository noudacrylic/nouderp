<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meja Kerja Admin — Task Manager menjadi tempat kerja admin & owner.
 *
 * - tasks: status 'pending' (menunggu pelanggan/bahan, wajib alasan) dan sumber 'skill'.
 *   Kolom skill/peran = task lahir dari "Skill Admin" (pola Karyawan Virtual, dikerjakan
 *   manusia); kunci_sumber mencegah satu pesanan/OP membuat task kembar; mulai_at,
 *   tenggat_at, pending_detik = bahan penilaian kinerja.
 * - meja_kerja_peran: peran → orang (urutan 1 = utama, berikutnya cadangan bila utama
 *   tidak absen masuk).
 * - meja_kerja_skill: pengaturan per skill (aktif, peran, bobot, SLA). aktif_sejak mencegah
 *   pekerjaan lama membanjiri meja saat skill baru dinyalakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY status ENUM('open','in_progress','pending','done','cancelled') NOT NULL DEFAULT 'open'");
        DB::statement("ALTER TABLE tasks MODIFY source ENUM('manual','scheduled','event','skill') NOT NULL DEFAULT 'manual'");

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('skill', 60)->nullable()->after('source')->index();
            $table->string('peran', 30)->nullable()->after('skill')->index();
            $table->string('kunci_sumber', 120)->nullable()->after('peran')->unique();
            $table->dateTime('mulai_at')->nullable()->after('done_at');
            $table->dateTime('tenggat_at')->nullable()->after('mulai_at');
            $table->string('pending_alasan', 255)->nullable()->after('tenggat_at');
            $table->dateTime('pending_sejak')->nullable()->after('pending_alasan');
            $table->unsignedInteger('pending_detik')->default(0)->after('pending_sejak');
            $table->json('meta')->nullable()->after('pending_detik');
        });

        Schema::create('meja_kerja_peran', function (Blueprint $table) {
            $table->id();
            $table->string('peran', 30);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('urutan')->default(1);
            $table->timestamps();
            $table->unique(['peran', 'user_id']);
        });

        Schema::create('meja_kerja_skill', function (Blueprint $table) {
            $table->id();
            $table->string('skill', 60)->unique();
            $table->boolean('aktif')->default(false);
            $table->string('peran', 30);
            $table->unsignedTinyInteger('bobot');
            $table->unsignedInteger('sla_menit');
            $table->dateTime('aktif_sejak')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meja_kerja_skill');
        Schema::dropIfExists('meja_kerja_peran');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['kunci_sumber']);
            $table->dropIndex(['skill']);
            $table->dropIndex(['peran']);
            $table->dropColumn(['skill', 'peran', 'kunci_sumber', 'mulai_at', 'tenggat_at', 'pending_alasan', 'pending_sejak', 'pending_detik', 'meta']);
        });
        DB::statement("UPDATE tasks SET status = 'in_progress' WHERE status = 'pending'");
        DB::statement("UPDATE tasks SET source = 'event' WHERE source = 'skill'");
        DB::statement("ALTER TABLE tasks MODIFY status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open'");
        DB::statement("ALTER TABLE tasks MODIFY source ENUM('manual','scheduled','event') NOT NULL DEFAULT 'manual'");
    }
};
