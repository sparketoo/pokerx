<?php

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class GameEvent extends AbstractConstants
{
    public const string PING = 'ping';

    public const string PONG = 'pong';

    public const string HAND_START = 'hand_start';

    public const string FORCE_BET = 'force_bet';

    public const string HAND_CARD = 'hand_card';

    /**
     * An authoritative snapshot of one hand, used to recover reporting after a
     * client reconnects or joins the hand after it has started.
     */
    public const string HAND_REFRESH = 'hand_refresh';

    public const string STAGE_START = 'stage_start';

    public const string PLAYER_ACTED = 'player_acted';

    public const string KNOWN_PLAY_CARDS = 'known_play_cards';

    public const string REQUEST_ACTION = 'request_action';

    public const string HAND_OVER = 'hand_over';
}
