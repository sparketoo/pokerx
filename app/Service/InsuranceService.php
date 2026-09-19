<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GameStatusEnum;
use App\Exception\GatewayException;
use App\Model\Game;
use App\Model\User;
use App\Model\UserInsurance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Hyperf\DbConnection\Db;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

/**
 * @phpstan-type Quote array{hand_uuid: string, pot_id: int, stage: string, outs: list<string>, remaining_card_num: int, odds: string, breakeven: int, min_insurance: int, max_insurance: int, pot: int, amount?: int}
 */
final class InsuranceService
{
    public function __construct(
        private readonly ValidatorFactoryInterface $validatorFactory,
        private readonly UserGameConfigService $configs,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{hand_uuid: string, amount: int|null}
     */
    public function suggest(User $user, array $payload): array
    {
        $quote = $this->validate($payload);
        $game = $this->game($user, $quote['hand_uuid']);
        if ($game->status !== GameStatusEnum::OPEN) {
            throw GatewayException::eventInvalid();
        }
        $outsCount = count($quote['outs']);
        $configs = $this->configs->all($user, $game->network);
        $ratio = ($outsCount <= 8 ? ($configs['insurance_outs_'.$outsCount] ?? null) : null)
            ?? $configs['insurance_default'] ?? null;
        if ($ratio !== null && ! is_string($ratio)) {
            throw GatewayException::eventInvalid();
        }

        return ['hand_uuid' => $game->uuid, 'amount' => $ratio === null ? null : $this->amount($quote, $ratio)];
    }

    /** @param array<string, mixed> $payload */
    public function submit(User $user, string $uuid, array $payload): Game
    {
        $this->validatorFactory->make(['uuid' => $uuid], ['uuid' => ['required', 'uuid']])->validate();
        $quote = $this->validate($payload, true);
        $uuid = strtolower($uuid);

        return Db::transaction(function () use ($user, $uuid, $quote): Game {
            // Serialize submissions on the owned game, including late confirmations after hand_over.
            $game = $this->game($user, $quote['hand_uuid'], true);
            $attributes = $quote;
            unset($attributes['hand_uuid']);
            $attributes['game_id'] = $game->id;
            $attributes['user_id'] = $user->id;
            $existing = UserInsurance::query()->where('user_id', $user->id)
                ->where(function ($query) use ($uuid, $game, $quote) {
                    $query->where('uuid', $uuid)->orWhere(function ($query) use ($game, $quote) {
                        $query->where('game_id', $game->id)->where('pot_id', $quote['pot_id'])->where('stage', $quote['stage']);
                    });
                })->get();
            foreach ($existing as $record) {
                foreach ($attributes as $key => $value) {
                    $actual = $record->getAttribute($key);
                    if ($actual instanceof \UnitEnum) {
                        $actual = $actual->name;
                    }
                    if ($actual !== $value) {
                        throw GatewayException::eventConflict();
                    }
                }
            }
            if ($existing->isEmpty()) {
                UserInsurance::query()->create(['uuid' => $uuid] + $attributes);
            }

            return $game;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Quote
     */
    private function validate(array $payload, bool $submitted = false): array
    {
        $rules = [
            'hand_uuid' => ['required', 'uuid'],
            'pot_id' => ['required', 'integer:strict', 'min:0', 'max:4294967295'],
            'stage' => ['required', 'in:flop,turn'],
            'outs' => ['required', 'array', 'list', 'min:1', 'max:52'],
            'outs.*' => ['required', 'string', 'regex:/^[2-9TJQKA][cdhs]$/D', 'distinct:strict'],
            'remaining_card_num' => ['required', 'integer:strict', 'min:1', 'max:52'],
            'odds' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,18})?$/D'],
        ];
        foreach (['breakeven', 'min_insurance', 'max_insurance', 'pot'] as $field) {
            $rules[$field] = ['required', 'integer:strict', 'min:0', 'max:9007199254740991'];
        }
        if ($submitted) {
            $rules['amount'] = ['required', 'integer:strict', 'min:1', 'max:9007199254740991'];
        }
        /** @var Quote $quote */
        $quote = $this->validatorFactory->make($payload, $rules)->validate();
        $odds = BigDecimal::of($quote['odds']);
        if ($odds->isLessThanOrEqualTo(0) || count($quote['outs']) > $quote['remaining_card_num']
            || $quote['min_insurance'] > $quote['max_insurance']
            || ($submitted && (! isset($quote['amount']) || $quote['amount'] < $quote['min_insurance'] || $quote['amount'] > $quote['max_insurance']))) {
            throw GatewayException::eventInvalid();
        }
        $quote['odds'] = (string) $odds->toScale(18);
        $quote['stage'] = strtoupper($quote['stage']);
        $quote['hand_uuid'] = strtolower($quote['hand_uuid']);
        // Outs are a set; normalize order so retries with reordered cards remain idempotent.
        sort($quote['outs']);

        return $quote;
    }

    /** @param Quote $quote */
    private function amount(array $quote, string $ratio): int
    {
        $min = $quote['min_insurance'];
        $max = $quote['max_insurance'];
        if ($ratio === '0') {
            if ($min > 0) {
                throw GatewayException::eventInvalid(['reason' => 'insurance_minimum_required']);
            }

            return 0;
        }
        if ($ratio === 'full') {
            return $max;
        }
        if ($ratio === '1') {
            $amount = $quote['breakeven'];
        } else {
            // Divide before converting to int: intermediate products may exceed integer precision.
            $amount = BigDecimal::of($quote['pot'])->multipliedBy($ratio)
                ->dividedBy($quote['odds'], 0, RoundingMode::Floor);
            $amount = $amount->isGreaterThan($max) ? $max : $amount->toInt();
        }

        return min($max, max($min, $amount));
    }

    private function game(User $user, string $uuid, bool $lock = false): Game
    {
        $query = Game::query()->where('user_id', $user->id)->where('uuid', $uuid);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var Game|null $game */
        $game = $query->first();
        if ($game === null) {
            throw GatewayException::eventInvalid();
        }

        return $game;
    }
}
