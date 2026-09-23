<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Enum\CardSuitEnum;
use App\Exception\GameException;
use App\Vo\Game\CardVo;
use Tests\TestCase;

final class CardVoTest extends TestCase
{
    public function test_short_cards_use_the_expected_suit_letters_and_keep_existing_strings(): void
    {
        self::assertSame('As', (new CardVo('A', CardSuitEnum::SPADES))->toShortString());
        self::assertSame('Th', (new CardVo('T', CardSuitEnum::HEARTS))->toShortString());
        self::assertSame('2d', (new CardVo('2', CardSuitEnum::DIAMONDS))->toShortString());
        self::assertSame('Kc', (new CardVo('K', CardSuitEnum::CLUBS))->toShortString());
        self::assertSame('As|Kh|7d', CardVo::cardsToShort([
            new CardVo('A', CardSuitEnum::SPADES),
            'Kh',
            new CardVo('7', CardSuitEnum::DIAMONDS),
        ], '|'));
    }

    public function test_invalid_rank_is_rejected(): void
    {
        try {
            new CardVo('1', CardSuitEnum::SPADES);
            self::fail('Invalid rank must be rejected');
        } catch (GameException $error) {
            self::assertSame('card_invalid', $error->getErrorCode());
        }
    }
}
