<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Encryption for provider credentials at rest.
 *
 * A telephony credential is a key to somebody's phone bill: whoever holds it
 * can place calls the company pays for. It is stored encrypted, decrypted only
 * in the process that is about to use it, and never returned by any endpoint —
 * not masked, not partially, not "for verification".
 *
 * AES-256-GCM, so a tampered ciphertext fails to decrypt rather than decrypting
 * to something attacker-chosen. The key comes from CREDENTIAL_ENCRYPTION_KEY in
 * the server .env (32 bytes, base64) and never from a request.
 *
 * With no key configured, storing a credential is REFUSED rather than stored in
 * the clear. A product that silently downgrades to plaintext when its key is
 * missing is a product whose encryption is decorative.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1.';

    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    /** @throws \RuntimeException when no key is configured */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException(
                'CREDENTIAL_ENCRYPTION_KEY is not set, so provider credentials cannot be stored.',
            );
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt the credential.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** null when the key is wrong, missing, or the ciphertext has been altered. */
    public static function decrypt(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Store a credential map, or throw.
     *
     * @param array<string, mixed> $credentials
     */
    public static function sealCredentials(array $credentials): string
    {
        return self::encrypt((string) json_encode($credentials, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public static function openCredentials(?string $stored): array
    {
        $plain = self::decrypt($stored);
        if ($plain === null) {
            return [];
        }
        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * What a screen may show about a stored credential: that it exists, and
     * which fields are set. Never any part of the value itself — the last four
     * characters of an API key are four characters an attacker no longer has to
     * guess.
     *
     * @return array{configured: bool, fields: list<string>}
     */
    public static function describe(?string $stored): array
    {
        $open = self::openCredentials($stored);

        return [
            'configured' => $open !== [],
            'fields'     => array_values(array_keys($open)),
        ];
    }

    private static function key(): ?string
    {
        $configured = Env::get('CREDENTIAL_ENCRYPTION_KEY');
        if ($configured === '') {
            return null;
        }

        $decoded = base64_decode($configured, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        // A key that is present but the wrong length is a configuration error,
        // not something to paper over by hashing it into shape — that would let
        // a typo silently create a second, different key.
        error_log('[crypto] CREDENTIAL_ENCRYPTION_KEY must be 32 bytes, base64-encoded.');

        return null;
    }
}
