<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $part) {
            $out .= substr(self::ALPHABET, intval($part, 2), 1);
        }

        return $out;
    }

    public function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $bits = '';
        foreach (str_split($secret) as $char) {
            $n = strpos(self::ALPHABET, $char);
            if ($n === false) {
                throw new InvalidArgumentException('Invalid secret');
            }
            $bits .= str_pad(decbin($n), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $part) {
            if (strlen($part) === 8) {
                $key .= chr(intval($part, 2));
            }
        }
        $h = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($h[19]) & 15;
        $number = ((ord($h[$offset]) << 24) | (ord($h[$offset + 1]) << 16) | (ord($h[$offset + 2]) << 8) | ord($h[$offset + 3])) & 0x7FFFFFFF;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function counter(string $secret, string $code): ?int
    {
        for ($offset = -1;
            $offset <= 1;
            $offset++) {
            $time = time() + $offset * 30;
            if (hash_equals($this->code($secret, $time), $code)) {
                return intdiv($time, 30);
            }
        }

        return null;
    }
}
