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
            $table->uuid('uuid')->unique()->comment('游戏UUID');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->enum('network', NetworkEnum::names())->comment('扑克网络');
            $table->string('game_key', 32)->collation('utf8mb4_bin')->comment('平台牌局标识');
            $table->string('hero_uid', 64)->nullable()->comment('本人UID');
            $table->string('provider', 32)->comment('服务商');
            $table->unsignedTinyInteger('players')->comment('玩家数量');
            $table->enum('status', GameStatusEnum::names())->default(GameStatusEnum::OPEN->name)->comment('游戏状态');

            $table->unsignedBigInteger('big_blind')->comment('大盲注');
            $table->unsignedBigInteger('small_blind')->comment('小盲注');
            $table->unsignedBigInteger('ante')->default(0)->comment('前注');
            $table->unsignedBigInteger('pot')->comment('底池：主池+边池');
            $table->unsignedBigInteger('total')->comment('本人总下注金额：包含前注+盲注+主动下注');
            $table->unsignedBigInteger('winnings')->default(0)->comment('本人赢得奖金');
            $table->unsignedBigInteger('insurance_amount')->default(0)->comment('本人保险购买总金额');
            $table->unsignedBigInteger('insurance_payout')->default(0)->comment('本人保险实际赔付总金额');
            $table->bigInteger('profit')->comment('本人游戏收益：winnings-total-insurance_amount+insurance_payout');
            $table->timestamps(6);

            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'profit']);
            $table->unique(['user_id', 'network', 'game_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
