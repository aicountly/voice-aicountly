<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Single-use, 30-second tickets for the live event stream (G18#14, G18#24).
 *
 * Minted by `POST /v1/events/ticket` — an ordinary authenticated request with
 * the session in the Authorization header, the company verified with Manage —
 * and redeemed once by `GET /v1/events?ticket=…`. A ticket names one user, one
 * company and nothing else; it cannot call another product, and a leaked one is
 * useless after its first use or 30 seconds. Only its sha256 is stored.
 */
final class StreamTickets
{
    public const TTL_SECONDS = 30;

    /** @return array{ticket: string, expires_in: int} */
    public static function issue(Context $ctx, Auth $auth): array
    {
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Db::run(
            'INSERT INTO voice_stream_tickets (ticket_hash, user_uuid, cmp_id, bo_id, is_owner, expires_at)
             VALUES (:hash, :uuid, :cmp, :bo, :owner, NOW() + (:ttl || \' seconds\')::interval)',
            [
                'hash'  => hash('sha256', $ticket),
                'uuid'  => $auth->uuid,
                'cmp'   => $ctx->cmpId,
                'bo'    => $ctx->boId,
                'owner' => $ctx->isOwner($auth) ? 'true' : 'false',
                'ttl'   => self::TTL_SECONDS,
            ],
        );
        // Spent and stale tickets are not worth keeping.
        Db::run("DELETE FROM voice_stream_tickets WHERE expires_at < NOW() - INTERVAL '1 hour'");

        return ['ticket' => $ticket, 'expires_in' => self::TTL_SECONDS];
    }

    /**
     * Use a ticket, once. Null when unknown, used or expired.
     *
     * @return array<string, mixed>|null
     */
    public static function redeem(string $ticket): ?array
    {
        if ($ticket === '' || strlen($ticket) > 128) {
            return null;
        }

        try {
            return Db::first(
                'UPDATE voice_stream_tickets SET used_at = NOW()
                  WHERE ticket_hash = :hash AND used_at IS NULL AND expires_at > NOW()
              RETURNING user_uuid, cmp_id, bo_id, is_owner',
                ['hash' => hash('sha256', $ticket)],
            );
        } catch (\Throwable $e) {
            error_log('[stream-ticket] redeem failed: ' . $e::class);

            return null;
        }
    }
}
