<?php

declare(strict_types=1);

namespace App\Factory;

use Illuminate\Encryption\Encrypter;

use function Hyperf\Config\config;

final class EncryptionFactory
{
    public function __invoke(): Encrypter
    {
        $key = (string) config('security.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }

        return new Encrypter($key, 'AES-256-CBC');
    }
}
