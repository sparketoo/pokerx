<?php

use App\Enum\UserStatusEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('account', 64)->unique();
            $t->string('nickname', 80);
            $t->boolean('is_vip')->default(false);
            $t->string('password');
            $t->enum('status', UserStatusEnum::names())->default(UserStatusEnum::NORMAL->name);
            $t->string('language', 10)->default('zh-CN');
            $t->text('two_factor_secret')->nullable();
            $t->unsignedBigInteger('credit_balance')->default(0);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
