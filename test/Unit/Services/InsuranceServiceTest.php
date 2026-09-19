<?php

declare(strict_types=1);

use App\Enum\GameStatusEnum;
use App\Exception\GatewayException;
use App\Model\Game;
use App\Model\User;
use App\Model\UserGameConfig;
use App\Model\UserInsurance;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Container;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Tests\Support\InMemoryGameDatabase;

use function App\Support\di;

final class InsuranceFixture
{
    public ConnectionResolverInterface $previous;

    public User $user;

    public Game $game;

    public InsuranceService $service;

    /** @var array<string, mixed> */
    public array $quote;
}
$insurance = new InsuranceFixture;

beforeEach(function () use ($insurance): void {
    $insurance->previous = InMemoryGameDatabase::install();
    Db::statement('CREATE TABLE user_game_config (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, key TEXT, value TEXT, UNIQUE(user_id, network, key))');
    Db::statement('CREATE TABLE user_insurances (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, user_id INTEGER, game_id INTEGER, pot_id INTEGER, stage TEXT, outs TEXT, remaining_card_num INTEGER, odds TEXT, breakeven INTEGER, min_insurance INTEGER, max_insurance INTEGER, pot INTEGER, amount INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(user_id, uuid), UNIQUE(game_id, pot_id, stage))');
    $insurance->user = new User(['id' => 1]);
    $insurance->game = Game::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => 1, 'network' => 'OK', 'status' => 'OPEN']);
    $insurance->service = new InsuranceService(di(ValidatorFactoryInterface::class), new UserGameConfigService);
    $insurance->quote = ['hand_uuid' => $insurance->game->uuid, 'pot_id' => 1, 'stage' => 'flop', 'outs' => ['6s', '6h'], 'remaining_card_num' => 35, 'odds' => '16', 'breakeven' => 9, 'min_insurance' => 0, 'max_insurance' => 18, 'pot' => 299];
});
afterEach(function () use ($insurance): void {
    $container = ApplicationContext::getContainer();
    assert($container instanceof Container);
    $container->set(ConnectionResolverInterface::class, $insurance->previous);
});

it('returns empty advice for every outs band when no strategy is configured', function () use ($insurance): void {
    $cards = ['As', 'Kd', 'Qc', 'Jh', 'Ts', '9d', '8h', '7h', '6c'];
    foreach ([1, 2, 3, 5, 6, 8, 9] as $count) {
        $quote = array_replace($insurance->quote, ['outs' => array_slice($cards, 0, $count)]);
        expect($insurance->service->suggest($insurance->user, $quote))->toBe(['hand_uuid' => $insurance->game->uuid, 'amount' => null]);
    }
    expect(UserInsurance::query()->count())->toBe(0);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['min_insurance' => 3]))['amount'])->toBeNull();
});

it('uses exact user and network configuration with decimal arithmetic', function () use ($insurance): void {
    UserGameConfig::query()->create(['user_id' => 2, 'network' => 'OK', 'key' => 'insurance_outs_2', 'value' => '0']);
    UserGameConfig::query()->create(['user_id' => 1, 'network' => 'WE', 'key' => 'insurance_outs_2', 'value' => 'full']);
    expect($insurance->service->suggest($insurance->user, $insurance->quote)['amount'])->toBeNull();
    $config = UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_outs_2', 'value' => '0']);
    foreach (['0' => 0, '1' => 9, 'full' => 18, '0.5' => 9, '0.125' => 2] as $ratio => $amount) {
        $config->update(['value' => (string) $ratio]);
        expect($insurance->service->suggest($insurance->user, $insurance->quote)['amount'])->toBe($amount);
    }
    $config->update(['value' => '0.29']);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['pot' => 100, 'odds' => '1', 'max_insurance' => 100]))['amount'])->toBe(29);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['pot' => 9007199254740991, 'odds' => '0.000001']))['amount'])->toBe(18);
});

it('enforces platform limits without overriding an explicit no-buy configuration', function () use ($insurance): void {
    $config = UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_outs_2', 'value' => '0']);
    expect(fn () => $insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['min_insurance' => 3])))->toThrow(GatewayException::class);
    $config->update(['value' => '0.01']);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['min_insurance' => 3]))['amount'])->toBe(3);
    $config->update(['value' => '1']);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['breakeven' => 99]))['amount'])->toBe(18);
    expect($insurance->service->suggest($insurance->user, array_replace($insurance->quote, ['outs' => ['As', 'Kd', 'Qc'], 'max_insurance' => 0]))['amount'])->toBeNull();
});

it('rejects malformed quotes and other users games', function () use ($insurance): void {
    foreach ([['outs' => ['As', 'As']], ['outs' => ['10s']], ['stage' => 'river'], ['pot_id' => -1], ['pot' => 2.1], ['odds' => '0.1234567890123456789'], ['odds' => '1e2'], ['odds' => 16]] as $change) {
        expect(fn () => $insurance->service->suggest($insurance->user, array_replace($insurance->quote, $change)))->toThrow(ValidationException::class);
    }
    foreach ([['odds' => '0'], ['remaining_card_num' => 1], ['min_insurance' => 19]] as $change) {
        expect(fn () => $insurance->service->suggest($insurance->user, array_replace($insurance->quote, $change)))->toThrow(GatewayException::class);
    }
    expect(fn () => $insurance->service->suggest(new User(['id' => 2]), $insurance->quote))->toThrow(GatewayException::class);
    expect(fn () => $insurance->service->submit(new User(['id' => 2]), (string) Str::uuid(), $insurance->quote + ['amount' => 9]))->toThrow(GatewayException::class);
});

it('records actual successes once per game pot and street and rejects conflicts', function () use ($insurance): void {
    $id = (string) Str::uuid();
    $payload = $insurance->quote + ['amount' => 7]; // Actual purchase need not equal advice.
    $insurance->service->submit($insurance->user, $id, $payload);
    $insurance->service->submit($insurance->user, $id, array_replace($payload, ['outs' => ['6h', '6s'], 'odds' => '16.000000']));
    $insurance->service->submit($insurance->user, (string) Str::uuid(), $payload);
    expect(UserInsurance::query()->count())->toBe(1);
    expect(UserInsurance::query()->firstOrFail()->amount)->toBe(7);
    foreach ([$id, (string) Str::uuid()] as $retry) {
        expect(fn () => $insurance->service->submit($insurance->user, $retry, array_replace($payload, ['amount' => 8])))->toThrow(GatewayException::class);
    }
    expect(fn () => $insurance->service->submit($insurance->user, $id, array_replace($payload, ['pot_id' => 2])))->toThrow(GatewayException::class);
    $insurance->service->submit($insurance->user, (string) Str::uuid(), array_replace($payload, ['pot_id' => 2]));
    $insurance->service->submit($insurance->user, (string) Str::uuid(), array_replace($payload, ['stage' => 'turn']));
    expect(UserInsurance::query()->count())->toBe(3);
});

it('accepts a late confirmed purchase without reopening the hand and rejects zero purchases', function () use ($insurance): void {
    $insurance->game->update(['status' => GameStatusEnum::CLOSED]);
    expect(fn () => $insurance->service->suggest($insurance->user, $insurance->quote))->toThrow(GatewayException::class);
    expect(fn () => $insurance->service->submit($insurance->user, (string) Str::uuid(), $insurance->quote + ['amount' => 0]))->toThrow(ValidationException::class);
    expect(fn () => $insurance->service->submit($insurance->user, (string) Str::uuid(), $insurance->quote + ['amount' => 19]))->toThrow(GatewayException::class);
    $game = $insurance->service->submit($insurance->user, (string) Str::uuid(), $insurance->quote + ['amount' => 9]);
    expect($game->status)->toBe(GameStatusEnum::CLOSED)->and($game->events()->count())->toBe(0)->and(UserInsurance::query()->count())->toBe(1);
});

it('uses insurance_default with exact one-to-eight keys taking precedence', function () use ($insurance): void {
    UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_default', 'value' => 'full']);
    expect($insurance->service->suggest($insurance->user, $insurance->quote)['amount'])->toBe(18);
    UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_outs_2', 'value' => '0']);
    expect($insurance->service->suggest($insurance->user, $insurance->quote)['amount'])->toBe(0);
    $quote = array_replace($insurance->quote, ['outs' => ['As', 'Ks', 'Qs', 'Js', 'Ts', '9s', '8s', '7s', '6s']]);
    expect($insurance->service->suggest($insurance->user, $quote)['amount'])->toBe(18);
    UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_outs_8', 'value' => '0']);
    expect($insurance->service->suggest($insurance->user, array_replace($quote, ['outs' => array_slice($quote['outs'], 0, 8)]))['amount'])->toBe(0);
    $insurance->game->update(['network' => 'WE']);
    expect($insurance->service->suggest($insurance->user, $quote)['amount'])->toBeNull();
});

it('preserves native float32 odds rather than rounding before a premium calculation', function () use ($insurance): void {
    UserGameConfig::query()->create(['user_id' => 1, 'network' => 'OK', 'key' => 'insurance_outs_2', 'value' => '0.5']);
    $quote = array_replace($insurance->quote, ['pot' => 22, 'odds' => '2.200000047683716']);
    expect($insurance->service->suggest($insurance->user, $quote)['amount'])->toBe(4);
    $id = (string) Str::uuid();
    $insurance->service->submit($insurance->user, $id, $quote + ['amount' => 4]);
    $insurance->service->submit($insurance->user, $id, $quote + ['amount' => 4]);
    expect(UserInsurance::query()->firstOrFail()->odds)->toBe('2.200000047683716000');
});
