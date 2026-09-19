<?php

declare(strict_types=1);

use App\Enum\StageEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_insurances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('game_id')->constrained();
            $table->unsignedInteger('pot_id');
            $table->enum('stage', [StageEnum::FLOP->name, StageEnum::TURN->name]);
            $table->json('outs');
            $table->unsignedTinyInteger('remaining_card_num');
            $table->decimal('odds', 24, 18);
            foreach (['breakeven', 'min_insurance', 'max_insurance', 'pot', 'amount'] as $column) {
                $table->bigInteger($column)->unsigned();
            }
            $table->timestamps(6);
            $table->unique(['user_id', 'uuid']);
            $table->unique(['game_id', 'pot_id', 'stage']);
            $table->index(['user_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_insurances');
    }
};
