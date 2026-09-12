<?php

use App\Factory\EncryptionFactory;
use App\Factory\LoggerFactory;
use App\Poker\VerifiedClient;
use Hyperf\WebSocketClient\Client;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

return [
    Client::class => VerifiedClient::class,
    Encrypter::class => EncryptionFactory::class,
    LoggerInterface::class => LoggerFactory::class,
];
