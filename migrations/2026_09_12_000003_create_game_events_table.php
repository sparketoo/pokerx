<?php

use App\Enum\GameEventTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->unsignedBigInteger('game_id')->comment('游戏ID');
            $table->enum('type', GameEventTypeEnum::names())->comment('事件类型');
            $table->unsignedBigInteger('timestamp')->comment('事件时间戳');
            $table->json('payload');
            $table->timestamps(6);

            $table->index('game_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'game_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
