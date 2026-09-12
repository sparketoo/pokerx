<?php

use App\Enum\CreditRecordTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $foreign = $t->foreignId('hand_id');
            $foreign->nullable();
            $foreign->constrained('games');
            $foreign = $t->foreignId('solve_id');
            $foreign->nullable()->unique();
            $foreign->constrained();
            $t->enum('type', CreditRecordTypeEnum::names());
            $t->bigInteger('amount');
            $t->unsignedBigInteger('balance')->nullable();
            $t->string('description')->nullable();
            $t->index(['user_id', 'created_at', 'id']);
            $t->index(['user_id', 'hand_id', 'id']);
            $t->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_records');
    }
};
