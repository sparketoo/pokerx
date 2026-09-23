<?php

use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 16)->unique()->comment('游戏ID');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->enum('network', NetworkEnum::names())->comment('扑克网络');
            $table->string('room_number', 64)->comment('房间号');
            $table->unsignedInteger('hand_number')->comment('第几手');
            $table->string('provider', 32)->comment('服务商');
            $table->unsignedTinyInteger('players')->comment('玩家数量');
            $table->enum('status', GameStatusEnum::names())->default(GameStatusEnum::OPEN->name)->comment('游戏状态');

            $table->unsignedBigInteger('big_blind')->comment('大盲注');
            $table->unsignedBigInteger('small_blind')->comment('小盲注');
            $table->unsignedBigInteger('ante')->default(0)->comment('前注');
            $table->unsignedBigInteger('pot')->comment('底池：主池+边池');
            $table->unsignedBigInteger('total')->comment('本人总下注金额：包含前注+盲注+主动下注');
            $table->unsignedBigInteger('winnings')->default(0)->comment('本人赢得奖金');
            $table->bigInteger('profit')->comment('本人游戏收益：winnings-total');
            $table->timestamps(6);

            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'profit']);
            $table->unique(['user_id', 'network', 'room_number', 'hand_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
