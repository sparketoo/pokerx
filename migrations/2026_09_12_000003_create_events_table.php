<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('game_id')->constrained();
            $t->unsignedBigInteger('seq');
            $t->string('type', 32);
            $t->json('payload');
            $t->unique(['user_id', 'uuid']);
            $t->unique(['game_id', 'seq']);
            $t->index(['user_id', 'created_at', 'id']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
