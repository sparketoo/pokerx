<?php

declare(strict_types=1);

use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Model\User;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use Tests\HttpTestCase;
use Tests\Support\TestData;

final class GameConfigApiTest extends HttpTestCase
{
    public function test_config_is_validated_and_isolated_by_user_and_network(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $other = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor($user);
        $url = '/api/mine/game_config';
        $post = fn (array $items) => TestData::inCoroutine(fn () => $this->json($url.'/save', ['network' => 'ok', 'items' => $items], $headers));
        $read = fn () => TestData::inCoroutine(fn () => $this->get($url, ['network' => 'ok'], $headers));
        self::assertSame('auth_required', TestData::inCoroutine(fn () => $this->get($url, ['network' => 'ok']))['code']);
        self::assertSame('auth_required', TestData::inCoroutine(fn () => $this->json($url.'/save', ['network' => 'ok', 'items' => []]))['code']);
        self::assertSame([], $read()['data']['items']);
        $items = [['key' => 'insurance_default', 'value' => '0.125']];
        foreach (range(1, 8) as $outs) {
            $items[] = ['key' => 'insurance_outs_'.$outs, 'value' => '1'];
        }
        $saved = $post($items);
        self::assertSame('success', $saved['code']);
        self::assertSame($items, $saved['data']['items']);
        $items[2]['value'] = 'full';
        self::assertSame($items, $post([$items[2]])['data']['items']);
        self::assertSame([], TestData::inCoroutine(fn () => $this->get($url, ['network' => 'we'], $headers))['data']['items']);
        $otherHeaders = $this->headersFor($other);
        self::assertSame([], TestData::inCoroutine(fn () => $this->get($url, ['network' => 'ok'], $otherHeaders))['data']['items']);
        $invalid = [[], [['key' => 'insurance_outs_2']], [$items[0], $items[0]], [['key' => 'insurance_outs_2', 'value' => '1', 'extra' => true]]];
        foreach (['insurance_outs_0', 'insurance_outs_9', 'insurance_outs_01', 'other', 'insurance_default ', ''] as $key) {
            $invalid[] = [['key' => $key, 'value' => '1']];
        }
        foreach (['', ' ', '2', '-1', '0.0', '1e-1', '1.5', '0'."\n", 0, 0.5, false, [], ['ratio' => '1']] as $value) {
            $invalid[] = [['key' => 'insurance_outs_2', 'value' => $value]];
        }
        $invalid[] = [['key' => 'insurance_outs_2', 'value' => null], ['key' => 'insurance_outs_3', 'value' => 'bad']];
        foreach ($invalid as $batch) {
            self::assertSame('event_invalid', $post($batch)['code']);
            self::assertSame($items, $read()['data']['items']);
        }
    }

    public function test_clearing_rules_falls_back_to_default_then_empty_advice(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $other = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor($user);
        $post = fn (array $items) => TestData::inCoroutine(fn () => $this->json('/api/mine/game_config/save', ['network' => 'ok', 'items' => $items], $headers));
        $configs = new UserGameConfigService;
        \Tests\run(function () use ($user, $other, $configs): void {
            $configs->save($other, NetworkEnum::OK, [['key' => 'insurance_outs_2', 'value' => 'full']]);
            $configs->save($user, NetworkEnum::WE, [['key' => 'insurance_outs_2', 'value' => '1']]);
        });
        $game = TestData::inCoroutine(fn () => TestData::game($user, ['network' => NetworkEnum::OK, 'status' => GameStatusEnum::OPEN]));
        $quote = ['hand_uuid' => $game->uuid, 'pot_id' => 1, 'stage' => 'flop', 'outs' => ['6s', '6h'], 'remaining_card_num' => 35, 'odds' => '16', 'breakeven' => 9, 'min_insurance' => 0, 'max_insurance' => 18, 'pot' => 299];
        $suggest = fn () => TestData::inCoroutine(fn () => \App\Support\di(InsuranceService::class)->suggest($user, $quote));
        self::assertNull($suggest()['amount']);
        self::assertSame('success', $post([['key' => 'insurance_default', 'value' => 'full'], ['key' => 'insurance_outs_2', 'value' => '0']])['code']);
        self::assertSame(0, $suggest()['amount']);
        $cleared = $post([['key' => 'insurance_outs_2', 'value' => null]]);
        self::assertSame('success', $cleared['code']);
        self::assertSame([['key' => 'insurance_default', 'value' => 'full']], $cleared['data']['items']);
        self::assertSame(18, $suggest()['amount']);
        self::assertSame('success', $post([['key' => 'insurance_outs_2', 'value' => null]])['code']);
        self::assertSame([], $post([['key' => 'insurance_default', 'value' => null]])['data']['items']);
        self::assertSame(['hand_uuid' => $game->uuid, 'amount' => null], $suggest());
        \Tests\run(function () use ($user, $other, $configs): void {
            self::assertSame(['insurance_outs_2' => 'full'], $configs->all($other, NetworkEnum::OK));
            self::assertSame(['insurance_outs_2' => '1'], $configs->all($user, NetworkEnum::WE));
        });
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        return ['authorization' => 'Bearer '.TestData::inCoroutine(fn (): string => TestData::token($user))];
    }
}
