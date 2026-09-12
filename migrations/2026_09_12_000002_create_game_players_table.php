<?php

use App\Enum\SeatTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained();
            $t->unsignedSmallInteger('seat');
            $t->string('name', 64)->collation(\Hyperf\Config\config('databases.default.driver') === 'mysql' ? 'utf8mb4_bin' : 'BINARY');
            $t->boolean('is_hero')->default(false);
            $t->unsignedBigInteger('stack');
            $t->enum('seat_type', SeatTypeEnum::names());
            $t->unsignedBigInteger('blind_amount')->nullable();
            $t->unsignedBigInteger('ante')->default(0);
            $t->json('cards')->nullable();
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
