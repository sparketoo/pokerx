<?php

namespace Tests\Fixtures;

use App\Game\Reducer;
use App\Vo\Game\GameContextVo;
use Hyperf\Stringable\Str;

final class GameFixture
{
    /**
     * @return non-empty-list<array<string, mixed>>
     */
    public static function events(): array
    {
        $x = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/GATEWAY-V1-EXAMPLES.json') ?: throw new \RuntimeException('Missing fixture'), true, 512, JSON_THROW_ON_ERROR);
        $events = [];
        foreach ($x['packets'] as $key => $v) {
            if (str_starts_with($key, 'client_') && str_starts_with($v['type'] ?? '', 'game_')) {
                $events[] = $v;
            }
        } usort($events, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        if ($events === []) {
            throw new \RuntimeException('Empty fixture');
        }

        return $events;
    }

    public static function context(int $until = 22): GameContextVo
    {
        $events = self::events();
        $r = new Reducer;
        $uuid = $events[1]['payload']['hand_id'];
        $c = $r->start(1, $uuid, $events[0]);
        foreach ($events as $e) {
            if ($e['seq'] > $until) {
                break;
            }
            $c = $r->apply($c, $e);
        }

        return $c;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function event(string $type, array $p, int $seq): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'seq' => $seq, 'payload' => $p];
    }
}
