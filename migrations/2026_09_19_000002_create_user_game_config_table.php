<?php

declare(strict_types=1);

use App\Enum\NetworkEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_game_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->enum('network', NetworkEnum::names());
            $table->string('key', 64);
            $table->json('value');
            $table->unique(['user_id', 'network', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_game_config');
    }
};
