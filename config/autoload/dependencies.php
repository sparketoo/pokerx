<?php

use App\Factory\EncryptionFactory;
use App\Factory\LoggerFactory;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

return [
    Encrypter::class => EncryptionFactory::class,
    LoggerInterface::class => LoggerFactory::class,
];
