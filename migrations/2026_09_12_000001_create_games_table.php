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
        // Poker money is stored as integer chips. Only game.vue divides by 100 for display.
        Schema::create('games', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique()->comment('游戏UUID');
            $t->foreignId('user_id')->constrained();
            $t->enum('network', NetworkEnum::names())->comment('扑克网络');
            $t->string('room_number', 64)->comment('房间号');
            $t->unsignedInteger('hand_number')->comment('第几手');
            $t->string('provider', 32)->comment('服务商');
            $t->bigInteger('big_blind')->unsigned()->comment('大盲注');
            $t->bigInteger('small_blind')->unsigned()->comment('小盲注');
            $t->bigInteger('ante')->unsigned()->default(0)->comment('前注');
            $t->enum('status', GameStatusEnum::names())->default(GameStatusEnum::OPEN->name)->comment('游戏状态');
            $t->bigInteger('bet_amount')->unsigned()->comment('投注金额');
            $t->bigInteger('winnings')->unsigned()->default(0)->comment('赢得奖金');
            $t->bigInteger('profit')->nullable()->comment('游戏收益：奖金-投注');
            $t->bigInteger('pot')->unsigned()->default(0)->comment('总池，包含所有人前注、大小盲及所有下注');
            $t->index(['user_id', 'room_number', 'hand_number']);
            $t->index(['user_id', 'created_at', 'id']);
            $t->index(['user_id', 'status']);
            $t->timestamps(6);
            $t->unique(['user_id', 'network', 'room_number', 'hand_number'], 'games_user_network_room_hand_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
