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
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $t->string('room_id', 64);
            $t->string('hand_number', 64);
            $t->string('provider', 32);
            $t->unsignedBigInteger('big_blind');
            $t->unsignedBigInteger('ante')->default(0);
            $t->enum('status', GameStatusEnum::names())->default(GameStatusEnum::OPEN->name);
            $t->unsignedBigInteger('invested')->nullable();
            $t->unsignedBigInteger('awarded')->nullable();
            $t->bigInteger('profit')->nullable();
            $t->index(['user_id', 'room_id', 'hand_number']);
            $t->index(['user_id', 'created_at', 'id']);
            $t->index(['user_id', 'status']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
