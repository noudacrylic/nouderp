<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_profile_id')
              ->constrained('business_profiles')
              ->cascadeOnDelete();
            $t->string('bank_name', 100)->nullable();
            $t->string('account_number', 50)->nullable();
            $t->string('holder', 150)->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        // Salin rekening tunggal yang sudah ada di business_profiles ke tabel
        // baru — supaya rekening lama tidak hilang dari print.
        DB::table('business_profiles')->orderBy('id')->get()->each(function ($p) {
            if (empty($p->bank_name) || empty($p->bank_account_number)) return;
            DB::table('business_bank_accounts')->insert([
                'business_profile_id' => $p->id,
                'bank_name'           => $p->bank_name,
                'account_number'      => $p->bank_account_number,
                'holder'              => $p->bank_account_holder,
                'sort_order'          => 0,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_bank_accounts');
    }
};
