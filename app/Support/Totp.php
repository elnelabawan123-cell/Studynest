<?php

namespace App\Support;

class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        return self::encodeBase32(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $email): string
    {
        $label = rawurlencode('StudyNest:' . $email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=StudyNest&algorithm=SHA1&digits=6&period=30";
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $key = self::decodeBase32($secret);
        $counter = intdiv($timestamp ?? time(), 30);

        for ($window = -1; $window <= 1; $window++) {
            $current = $counter + $window;
            $message = pack('N2', ($current >> 32) & 0xffffffff, $current & 0xffffffff);
            $hash = hash_hmac('sha1', $message, $key, true);
            $offset = ord($hash[19]) & 0x0f;
            $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
            $expected = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private static function encodeBase32(string $value): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($value) as $character) {
            $buffer = ($buffer << 8) | ord($character);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private static function decodeBase32(string $value): string
    {
        $decoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split(strtoupper(rtrim($value, '='))) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret.');
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
    }
}
