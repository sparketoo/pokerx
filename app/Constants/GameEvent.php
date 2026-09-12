<?php

namespace App\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;

#[Constants]
class GameEvent extends AbstractConstants
{
    public const string PING = 'ping';

    public const string PONG = 'pong';

    public const string GAME_START = 'game_start';

    public const string GAME_STAGE = 'game_stage';

    public const string GAME_PLAY_ACTED = 'game_play_acted';

    public const string GAME_KNOWN_PLAY_CARDS = 'game_known_play_cards';

    public const string GAME_REQUEST_ACTION = 'game_request_action';

    public const string GAME_OVER = 'game_over';
}
