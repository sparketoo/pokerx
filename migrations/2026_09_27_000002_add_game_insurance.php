<?php

declare(strict_types=1);

use App\Enum\GameEventTypeEnum;
use App\Enum\StageEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->unsignedBigInteger('insurance_amount')->default(0)->comment('本人保险购买总金额');
            $table->unsignedBigInteger('insurance_payout')->default(0)->comment('本人保险实际赔付总金额');
        });

        Schema::create('game_insurances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_id')->comment('游戏ID');
            $table->string('record_key', 32)->comment('本手内保险流水幂等键');
            $table->enum('type', ['PURCHASE', 'PAYOUT'])->comment('购买或实际赔付');
            $table->enum('stage', [StageEnum::FLOP->name, StageEnum::TURN->name])->nullable()->comment('购买阶段');
            $table->unsignedBigInteger('pot_id')->nullable()->comment('游戏平台保险标识');
            $table->decimal('odds', 12, 2)->nullable()->comment('购买赔率');
            $table->unsignedBigInteger('amount')->default(0)->comment('确认购买金额');
            $table->unsignedBigInteger('payout')->default(0)->comment('实际赔付金额');
            $table->unsignedBigInteger('timestamp')->comment('上报事件时间戳');
            $table->timestamps(6);

            $table->unique(['game_id', 'record_key']);
            $table->index(['game_id', 'type']);
        });

        // 当前安装环境没有 DBAL，沿用已有迁移的 ENUM 修改方式。
        Db::statement("ALTER TABLE game_events MODIFY COLUMN type ENUM('".implode("','", GameEventTypeEnum::names())."') NOT NULL COMMENT '事件类型'");
    }

    public function down(): void
    {
        if (Db::table('game_events')->where('type', GameEventTypeEnum::INSURANCE_PURCHASED->name)->exists()) {
            throw new RuntimeException('Cannot remove insurance event types while insurance events exist.');
        }

        Db::statement("ALTER TABLE game_events MODIFY COLUMN type ENUM('START','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
        Schema::dropIfExists('game_insurances');
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['insurance_amount', 'insurance_payout']);
        });
    }
};
