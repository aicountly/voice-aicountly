<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * UUID v4, and the check that a string is one.
 *
 * Every id this product mints is a v4 — not a sequence, not a hash of anything
 * meaningful. A booking id that can be guessed from the one before it is a
 * booking id somebody can walk, and this product puts ids in links that reach
 * clients who have no account and no session.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value),
        );
    }

    /**
     * A URL-safe token for public booking links and hold receipts.
     *
     * Longer than a uuid and not formatted like one, so nothing downstream
     * mistakes a public token for an internal id and tries to look it up.
     */
    public static function token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
