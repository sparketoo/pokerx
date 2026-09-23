<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Poker money is stored as integer chips. Only game.vue divides by 100 for display.
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id')->comment('游戏ID');
            $table->unsignedTinyInteger('seat')->comment('座位号');
            $table->string('uid', 64)->comment('玩家UID');
            $table->string('name', 64)->comment('玩家名称');
            $table->boolean('is_hero')->default(false)->comment('是否本人');
            $table->unsignedBigInteger('stack')->comment('初始总筹码');
            $table->unsignedBigInteger('ante')->default(0)->comment('前注');
            $table->unsignedBigInteger('blind')->nullable()->comment('盲注：大盲或小盲');
            $table->unsignedBigInteger('bet')->default(0)->comment('主动下注金额');
            $table->unsignedBigInteger('total')->default(0)->comment('累计投注：主动下注+前注+盲注');
            $table->string('cards')->nullable()->comment('已知手牌');
            $table->timestamps(6);

            $table->unique(['game_id', 'uid']);
            $table->unique(['game_id', 'seat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
