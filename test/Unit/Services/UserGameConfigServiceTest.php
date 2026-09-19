<?php

declare(strict_types=1);

use App\Enum\NetworkEnum;
use App\Model\UserGameConfig;
use App\Service\UserGameConfigService;
use Hyperf\Database\Model\JsonEncodingException;
use Tests\Support\TestData;

use function Tests\run;

it('preserves JSON types and updates only the selected user network and key', function (): void {
    run(function (): void {
        $service = new UserGameConfigService;
        $user = TestData::user();
        $other = TestData::user();
        $values = ['object' => ['nested' => ['text' => '游戏']], 'list' => [1, '1', false, null], 'boolean' => false, 'integer' => 0, 'decimal' => 0.5, 'string' => '0.125'];
        $items = [];
        foreach ($values as $key => $value) {
            $items[] = ['key' => $key, 'value' => $value];
        }
        $service->save($user, NetworkEnum::OK, $items);
        ksort($values);
        expect($service->all($user, NetworkEnum::OK))->toBe($values)
            ->and($service->all($other, NetworkEnum::OK))->toBe([])
            ->and($service->all($user, NetworkEnum::WE))->toBe([]);
        $service->save($other, NetworkEnum::OK, [['key' => 'string', 'value' => 'other']]);
        $service->save($user, NetworkEnum::WE, [['key' => 'string', 'value' => 'we']]);
        $service->save($user, NetworkEnum::OK, [['key' => 'string', 'value' => 'full']]);
        $values['string'] = 'full';
        expect($service->all($user, NetworkEnum::OK))->toBe($values);
        $service->save($user, NetworkEnum::OK, [['key' => 'string', 'value' => null]]);
        $service->save($user, NetworkEnum::OK, [['key' => 'string', 'value' => null]]);
        unset($values['string']);
        expect($service->all($user, NetworkEnum::OK))->toBe($values)
            ->and($service->all($other, NetworkEnum::OK))->toBe(['string' => 'other'])
            ->and($service->all($user, NetworkEnum::WE))->toBe(['string' => 'we']);
        expect(UserGameConfig::query()->where('user_id', $user->id)->where('network', 'OK')->count())->toBe(count($values));
    });
});

it('rolls back the entire batch when a value cannot be encoded as JSON', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new UserGameConfigService;
        $service->save($user, NetworkEnum::OK, [['key' => 'existing', 'value' => 'before']]);
        expect(fn () => $service->save($user, NetworkEnum::OK, [
            ['key' => 'existing', 'value' => null],
            ['key' => 'new', 'value' => 'added'],
            ['key' => 'invalid', 'value' => NAN],
        ]))->toThrow(JsonEncodingException::class);
        expect($service->all($user, NetworkEnum::OK))->toBe(['existing' => 'before']);
    });
});
