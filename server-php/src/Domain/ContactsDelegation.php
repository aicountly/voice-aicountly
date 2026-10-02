<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Support\Clock;

/**
 * Contacts delegation grants for campaigns (contract v1 §3.9; G18#1).
 *
 * ## The problem
 *
 * The campaign worker runs from cron. Nobody is signed in, and the person who
 * built the campaign may have gone home. It used to call Contacts with an
 * empty session, read the 401 as "directory unavailable", and re-queue every
 * attempt every ten minutes forever — a running campaign that never called
 * anybody.
 *
 * ## The rule now
 *
 *   ISSUED while a person is present: starting, resuming or renewing a campaign
 *   (and saving a Contacts audience) asks Contacts for a company-scoped,
 *   read-only grant with that person's session plus Voice's product key.
 *   Contacts checks the session, Manage membership and that `contacts.read` is
 *   within what Voice may hold, and records them as the actor.
 *
 *   USED by the worker: `Authorization: Delegation <token>` + Voice's product
 *   headers, only on this company's company endpoints.
 *
 *   EXPIRY AND RENEWAL: a grant lives at most 24 hours (VOICE_CONTACTS_GRANT_TTL,
 *   default 8). A grant close to expiry is renewed whenever a person with launch
 *   permission next acts on the campaign; one past it — or one Contacts
 *   refuses (revoked, the person left the company) — PAUSES the campaign with a
 *   reason, instead of dialling from stale data or looping.
 *
 *   HONEST FAILURE: no product key, no encryption key, Contacts saying no or not
 *   answering — each refuses the start with the reason, so nobody launches a
 *   campaign that cannot call its Contacts audience.
 *
 * Only the token's encrypted form is stored; Contacts keeps only its hash.
 */
final class ContactsDelegation
{
    public const SCOPES = ['contacts.read'];

    /** Renew a live grant on the next interactive touch when it has less than this left. */
    private const RENEW_WITHIN_SECONDS = 4 * 3600;

    /** A grant with less than this left is not handed to the worker. */
    private const USABLE_MARGIN_SECONDS = 120;

    public static function ttlSeconds(): int
    {
        $raw = (int) (Env::get('VOICE_CONTACTS_GRANT_TTL') ?: 28800);

        return max(600, min(86400, $raw));
    }

    /** Does this campaign's audience come from Contacts at all? */
    public static function needed(int $campaignId): bool
    {
        return (int) (Db::scalar(
            "SELECT COUNT(*) FROM voice_campaign_audience_refs WHERE campaign_id = :id AND source = 'contacts'",
            ['id' => $campaignId],
        ) ?? 0) > 0;
    }

    /**
     * What stops Voice holding a grant at all on this server, or null.
     */
    public static function configurationGap(): ?string
    {
        if (ContactsClient::serviceKey() === '') {
            return 'CONTACTS_SERVICE_KEY is not set on the Voice server, so Contacts cannot grant campaigns access. '
                . 'Contacts must also list its sha256 in CONTACTS_SERVICE_CREDENTIALS.';
        }
        if (!Crypto::isConfigured()) {
            return 'CREDENTIAL_ENCRYPTION_KEY is not set, so a Contacts grant cannot be stored safely.';
        }

        return null;
    }

    /**
     * Issue (or renew) the grant for a campaign, with the present person's session.
     *
     * @return array{ok: bool, code: ?string, message: ?string, expires_at: ?string}
     */
    public static function issueForCampaign(Context $ctx, Auth $auth, int $campaignId, bool $onlyIfDue = false): array
    {
        $gap = self::configurationGap();
        if ($gap !== null) {
            return self::outcome(false, 'directory_access_unconfigured', $gap);
        }
        if ($auth->isService() || $auth->sesKey() === '') {
            return self::outcome(false, 'directory_access_needs_person',
                'Contacts access for a campaign is granted by a signed-in person; a product key cannot grant it.');
        }

        $current = self::active($ctx->cmpId, $campaignId);
        if ($onlyIfDue && $current !== null && self::secondsLeft($current) > self::RENEW_WITHIN_SECONDS) {
            return self::outcome(true, null, null, (string) $current['expires_at']);
        }

        $result = (new ContactsClient())->withSession($auth->sesKey())
            ->issueDelegation($ctx->cmpId, self::SCOPES, self::ttlSeconds(), 'voice.campaign:' . $campaignId);

        if (!$result['ok']) {
            $why = match ($result['kind']) {
                'forbidden'      => 'Aicountly Contacts refused: you, or Voice, may not read this company’s contacts there.',
                'unavailable'    => 'Aicountly Contacts could not be reached to grant access. Try again shortly.',
                'not_configured' => (string) $result['message'],
                'unauthorized'   => 'Aicountly Contacts did not accept your session or Voice’s product key.',
                default          => 'Aicountly Contacts did not grant access: ' . (string) $result['message'],
            };

            return self::outcome(false, 'directory_access_refused', $why);
        }

        $grant = $result['data'];
        $token = (string) ($grant['token'] ?? '');
        $expires = Clock::parse((string) ($grant['expiresAt'] ?? ''));
        if ($token === '' || $expires === null) {
            return self::outcome(false, 'directory_access_refused', 'Aicountly Contacts answered without a usable grant.');
        }

        Db::transaction(static function () use ($ctx, $auth, $campaignId, $grant, $token, $expires): void {
            Db::run(
                "UPDATE voice_directory_grants SET status = 'replaced', ended_at = NOW()
                  WHERE campaign_id = :id AND status = 'active'",
                ['id' => $campaignId],
            );
            Db::insert('voice_directory_grants', [
                'cmp_id'      => $ctx->cmpId,
                'campaign_id' => $campaignId,
                'purpose'     => 'voice.campaign:' . $campaignId,
                'grant_id'    => (string) ($grant['grantId'] ?? ''),
                'token_enc'   => Crypto::encrypt($token),
                'scopes'      => array_values((array) ($grant['scopes'] ?? self::SCOPES)),
                'actor_uuid'  => $auth->uuid,
                'environment' => isset($grant['environment']) ? (string) $grant['environment'] : null,
                'expires_at'  => Clock::sql($expires),
            ], 'delegation_id');
        });

        // The grant it replaced is revoked at Contacts too, best effort: an
        // unrevoked spare only expires, it is never used again here.
        if ($current !== null) {
            (new ContactsClient())->withSession($auth->sesKey())->revokeDelegation($ctx->cmpId, (string) $current['grant_id']);
        }

        Audit::record($ctx, $auth, Audit::ACCESS_CHANGED, 'campaign', (string) $campaignId, [
            'change' => 'directory_grant_issued', 'grant_id' => (string) ($grant['grantId'] ?? ''),
            'expires_at' => Clock::iso($expires), 'scopes' => self::SCOPES,
        ]);

        return self::outcome(true, null, null, Clock::iso($expires));
    }

    /**
     * The client the worker reads this campaign's Contacts audience with, or
     * why there is none.
     *
     * @return array{ok: bool, client: ?ContactsClient, code: ?string, message: ?string}
     */
    public static function workerClient(int $cmpId, int $campaignId): array
    {
        $row = self::active($cmpId, $campaignId);
        if ($row === null) {
            return ['ok' => false, 'client' => null, 'code' => 'directory_access_missing',
                'message' => 'No Contacts access has been granted for this campaign. Resume it to grant access.'];
        }
        if (self::secondsLeft($row) < self::USABLE_MARGIN_SECONDS) {
            self::end((int) $row['delegation_id'], 'invalid', 'expired');

            return ['ok' => false, 'client' => null, 'code' => 'directory_access_expired',
                'message' => 'Contacts access for this campaign expired. A person with launch permission must resume it to renew access.'];
        }
        $token = Crypto::decrypt((string) $row['token_enc']);
        if ($token === null || $token === '') {
            self::end((int) $row['delegation_id'], 'invalid', 'undecryptable');

            return ['ok' => false, 'client' => null, 'code' => 'directory_access_missing',
                'message' => 'The stored Contacts grant cannot be read (was CREDENTIAL_ENCRYPTION_KEY changed?). Resume the campaign to renew access.'];
        }

        return ['ok' => true, 'client' => (new ContactsClient())->withDelegation($token), 'code' => null, 'message' => null];
    }

    /** Contacts refused the grant (expired, revoked, the person left): stop using it. */
    public static function markRefused(int $campaignId, string $detail): void
    {
        $row = Db::first(
            "SELECT delegation_id FROM voice_directory_grants WHERE campaign_id = :id AND status = 'active'",
            ['id' => $campaignId],
        );
        if ($row !== null) {
            self::end((int) $row['delegation_id'], 'invalid', $detail);
        }
    }

    /** For the campaign screen: is there access, until when? */
    public static function describe(int $cmpId, int $campaignId): array
    {
        $row = self::active($cmpId, $campaignId);

        return $row === null
            ? ['granted' => false, 'expires_at' => null]
            : ['granted' => true, 'expires_at' => $row['expires_at'], 'granted_by' => $row['actor_uuid']];
    }

    /** @return array<string, mixed>|null */
    private static function active(int $cmpId, int $campaignId): ?array
    {
        return Db::first(
            "SELECT * FROM voice_directory_grants
              WHERE cmp_id = :cmp AND campaign_id = :id AND status = 'active'
              ORDER BY delegation_id DESC LIMIT 1",
            ['cmp' => $cmpId, 'id' => $campaignId],
        );
    }

    /** @param array<string, mixed> $row */
    private static function secondsLeft(array $row): int
    {
        $expires = Clock::parse((string) $row['expires_at']);

        return $expires === null ? 0 : $expires->getTimestamp() - Clock::now()->getTimestamp();
    }

    private static function end(int $delegationId, string $status, string $detail): void
    {
        Db::run(
            'UPDATE voice_directory_grants SET status = :status, status_detail = :detail, ended_at = NOW()
              WHERE delegation_id = :id',
            ['status' => $status, 'detail' => $detail, 'id' => $delegationId],
        );
    }

    /** @return array{ok: bool, code: ?string, message: ?string, expires_at: ?string} */
    private static function outcome(bool $ok, ?string $code, ?string $message, ?string $expiresAt = null): array
    {
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'expires_at' => $expiresAt];
    }
}
