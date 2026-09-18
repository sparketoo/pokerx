<?php

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_players', 'bet_amount')) {
            Schema::table('game_players', function (Blueprint $t) {
                $t->bigInteger('bet_amount')->unsigned()->default(0)->comment('累计投注')->after('blind_amount');
            });
        }
        Db::table('games')->orderBy('id')->chunkById(100, function ($games): void {
            foreach ($games as $game) {
                $players = Db::table('game_players')->where('game_id', $game->id)->get();
                $playerIds = [];
                $bets = [];
                foreach ($players as $player) {
                    $playerIds[$player->name] = $player->id;
                    $bets[$player->id] = (int) $game->ante + (int) $player->blind_amount;
                }

                $events = Db::table('events')
                    ->where('game_id', $game->id)
                    ->whereIn('type', ['force_bet', 'player_acted'])
                    ->orderBy('seq')
                    ->get();
                foreach ($events as $event) {
                    $payload = json_decode((string) $event->payload, true);
                    if (! is_array($payload)) {
                        continue;
                    }
                    if ($event->type === 'force_bet') {
                        foreach ($payload['extra_bets'] ?? [] as $bet) {
                            if (is_array($bet) && is_string($bet['name'] ?? null)
                                && is_int($bet['amount'] ?? null) && isset($playerIds[$bet['name']])) {
                                $bets[$playerIds[$bet['name']]] += $bet['amount'];
                            }
                        }
                    }
                    if ($event->type === 'player_acted' && is_string($payload['name'] ?? null)
                        && is_int($payload['amount'] ?? null) && isset($playerIds[$payload['name']])) {
                        $bets[$playerIds[$payload['name']]] += $payload['amount'];
                    }
                }
                foreach ($bets as $playerId => $betAmount) {
                    Db::table('game_players')->where('id', $playerId)->update(['bet_amount' => $betAmount]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $t) {
            $t->dropColumn('bet_amount');
        });
    }
};
