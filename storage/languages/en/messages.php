<?php

return [
    'common' => [
        'business_error' => 'The operation failed. Please try again later.',
        'invalid_input' => 'The input is invalid.',
        'not_found' => 'The record was not found.',
        'rate_limited' => 'Too many requests. Please try again later.',
        'server_error' => 'The service is temporarily unavailable.',
        'status_invalid' => 'This operation is not allowed in the current state.',
    ],
    'auth' => [
        'failed' => 'The account or password is incorrect.',
        'required' => 'Please sign in.',
        'expired' => 'Your sign-in has expired.',
        'two_factor_required' => 'Enter your authenticator code.',
        'two_factor_invalid' => 'The two-factor code is invalid or has already been used.',
        'two_factor_already_enabled' => 'Two-factor authentication is already enabled.',
        'setup_expired' => 'The setup has expired.',
    ],
    'game' => [
        'already_exists' => 'The game already exists.',
        'not_found' => 'Game not found.',
        'event_invalid' => 'The event format or content is invalid.',
        'event_type_invalid' => 'The event type is not supported.',
        'card_invalid' => 'The card is invalid.',
        'action_in_progress' => 'An action recommendation is in progress. Please try again shortly.',
        'action_amount_invalid' => 'The action amount is invalid.',
        'hero_not_found' => 'The game hero was not found.',
        'big_blind_not_found' => 'The big blind player was not found.',
        'player_not_found' => 'The player was not found.',
        'players_empty' => 'The game has no players.',
    ],
    'provider' => [
        'unavailable' => 'The decision service is unavailable.',
        'failed' => 'The decision service failed to process the request.',
    ],
];
