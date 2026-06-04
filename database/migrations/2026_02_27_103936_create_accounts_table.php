<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\AccountTypeEnum;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->string('code', 10)->unique();
            $table->string('name', 150);

            $table->enum('type', [
                AccountTypeEnum::ASSET,
                AccountTypeEnum::LIABILITY,
                AccountTypeEnum::EQUITY,
                AccountTypeEnum::REVENUE,
                AccountTypeEnum::EXPENSE
            ]);

            $table->unsignedBigInteger('parent_id')->nullable();

            $table->enum('normal_balance', ['debit', 'credit']);

            $table->boolean('is_control_account')->default(false);
            $table->boolean('is_system_account')->default(false);
            $table->boolean('is_active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
