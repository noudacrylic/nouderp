<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 60)->nullable()->unique()->after('id');
            $t->enum('role', ['super_admin', 'admin', 'user'])->default('user')->after('password');
            $t->boolean('is_active')->default(true)->after('role');
            $t->unsignedBigInteger('karyawan_id')->nullable()->unique()->after('is_active');
            $t->foreign('karyawan_id')->references('id')->on('sdm_karyawan')->nullOnDelete();
            $t->timestamp('last_login_at')->nullable()->after('karyawan_id');
        });

        // Email jadi nullable (MySQL allow multiple NULL pada UNIQUE column).
        DB::statement("ALTER TABLE users MODIFY email VARCHAR(255) NULL");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['karyawan_id']);
            $t->dropUnique(['karyawan_id']);
            $t->dropUnique(['username']);
            $t->dropColumn(['username', 'role', 'is_active', 'karyawan_id', 'last_login_at']);
        });
        DB::statement("ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL");
    }
};
