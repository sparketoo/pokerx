<?php

use App\Enum\ActionEnum;
use App\Enum\SolveStatusEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('game_id')->constrained();
            $foreign = $t->foreignId('event_id');
            $foreign->unique();
            $foreign->constrained();
            $t->enum('status', SolveStatusEnum::names())->default(SolveStatusEnum::PENDING->name);
            $t->enum('action', ActionEnum::names())->nullable();
            $t->unsignedBigInteger('amount')->nullable();
            $t->unsignedBigInteger('cost')->default(0);
            $t->string('error_code', 64)->nullable();
            $t->string('reason')->nullable();
            $t->index(['user_id', 'created_at', 'id']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solves');
    }
};
