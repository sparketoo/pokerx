<?php

use App\Enum\SeatTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Poker money is stored as integer chips. Only game.vue divides by 100 for display.
        Schema::create('game_players', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained();
            $t->unsignedSmallInteger('seat')->comment('座位号');
            $t->string('name', 64)->comment('玩家名称');
            $t->boolean('is_hero')->default(false)->comment('是否本人');
            $t->bigInteger('stack')->unsigned()->comment('总筹码');
            $t->enum('seat_type', SeatTypeEnum::names())->comment('座位类型');
            $t->bigInteger('blind_amount')->unsigned()->nullable()->comment('大盲注');
            $t->json('cards')->nullable()->comment('已知手牌');
            $t->unique(['game_id', 'seat']);
            $t->unique(['game_id', 'name']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
