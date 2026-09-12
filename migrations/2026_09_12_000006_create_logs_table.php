<?php

use App\Enum\LogDirectionEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $foreign = $t->foreignId('user_id');
            $foreign->nullable();
            $foreign->constrained();
            $foreign = $t->foreignId('game_id');
            $foreign->nullable();
            $foreign->constrained();
            $t->enum('direction', LogDirectionEnum::names());
            $t->string('type', 64);
            $t->json('payload');
            $t->string('error_code', 64)->nullable();
            $t->index(['user_id', 'created_at', 'id']);
            $t->index(['user_id', 'game_id', 'created_at', 'id']);
            $t->index(['user_id', 'direction', 'created_at', 'id']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
