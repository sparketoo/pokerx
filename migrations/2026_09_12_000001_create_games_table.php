<?php

use App\Enum\GameStatusEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique()->comment('游戏UUID');
            $t->foreignId('user_id')->constrained();
            $t->string('game_type', 32)->after('provider');
            $t->string('room_number', 64)->comment('房间号');
            $t->unsignedInteger('hand_number')->comment('第几手');
            $t->string('provider', 32)->comment('服务商');
            $t->decimal('big_blind', 14, 4)->unsigned()->comment('大盲注');
            $t->decimal('small_blind', 14, 4)->unsigned()->comment('小盲注');
            $t->decimal('ante', 14, 4)->default(0)->comment('前注');
            $t->enum('status', GameStatusEnum::names())->default(GameStatusEnum::OPEN->name)->comment('游戏状态');
            $t->decimal('bet_amount', 14, 4)->unsigned()->nullable()->comment('投注金额');
            $t->decimal('winnings', 14, 4)->nullable()->comment('赢得奖金');
            $t->decimal('profit', 14, 4)->nullable()->comment('游戏收益：投注-奖金');
            $t->index(['user_id', 'room_number', 'hand_number']);
            $t->index(['user_id', 'created_at', 'id']);
            $t->index(['user_id', 'status']);
            $t->timestamps(6);
            $t->unique(['user_id', 'game_type', 'room_number', 'hand_number'], 'games_user_type_room_hand_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
