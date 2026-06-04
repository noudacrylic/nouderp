<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_menu_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('menu_key', 80);
            $t->timestamps();

            $t->unique(['user_id', 'menu_key']);
            $t->index('menu_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_menu_permissions');
    }
};
