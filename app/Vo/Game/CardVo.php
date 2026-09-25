<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Constants\ErrorCode;
use App\Enum\CardSuitEnum;
use App\Exception\GameException;
use App\Vo\Vo;

use function Hyperf\Translation\__;

class CardVo extends Vo
{
    /**
     * @var list<string>
     */
    public static array $numbers = [
        'A',
        '2',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
        'T',
        'J',
        'Q',
        'K',
    ];

    public function __construct(
        public readonly string $number,
        public readonly CardSuitEnum $suit,
    ) {
        if (! in_array($this->number, self::$numbers)) {
            throw new GameException(__('messages.game.card_invalid'), ErrorCode::BUSINESS_ERROR);
        }
    }

    public function toShortString(): string
    {
        return $this->number.match ($this->suit) {
            CardSuitEnum::CLUBS => 'c',
            CardSuitEnum::DIAMONDS => 'd',
            CardSuitEnum::SPADES => 's',
            CardSuitEnum::HEARTS => 'h',
        };
    }

    /**
     * @param  list<CardVo|string>  $cards
     */
    public static function cardsToShort(array $cards, string $separator = ','): string
    {
        return implode($separator, array_map(
            static fn (CardVo|string $card): string => $card instanceof CardVo ? $card->toShortString() : $card,
            $cards,
        ));
    }
}
