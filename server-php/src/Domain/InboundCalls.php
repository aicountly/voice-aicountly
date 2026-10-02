<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\PhoneNumber;
use Aicountly\Api\Support\Uuid;

/**
 * Inbound calls, and who is calling (G18#4).
 *
 * ## Creating the call
 *
 * The gateway sends a signed `call.inbound` event for a call it received on
 * one of this company's numbers. That event only ever moves Voice-owned state:
 * it creates the inbound call row (idempotently, keyed by the provider's leg
 * reference) and nothing else. A carrier callback carries no Aicountly identity
 * and must never reach another product — so it does NOT ask Contacts.
 *
 * ## Identifying the caller
 *
 * Done when an agent's console shows the call, with THAT agent's session, by a
 * COMPANY lookup in Contacts (contract v1 §3.4):
 *
 *   matchCount 1, and the contact holds this exact number → linked (contact_ref)
 *   matchCount 0  → no_match: only now may the console say "not linked"
 *   matchCount 2+ → ambiguous: NOT linked automatically; a person chooses
 *   Contacts down → unavailable: the number is shown, nothing is claimed
 *   no access     → forbidden
 *
 * An unmatched number never creates a contact. A wrong number is not a new
 * customer.
 */
final class InboundCalls
{
    public const STATES = ['not_attempted', 'matched', 'no_match', 'ambiguous', 'unavailable', 'forbidden'];

    /**
     * Create the inbound call a gateway event announces, or return the one it
     * already created. Null when the event cannot be an inbound call.
     *
     * @param array<string, mixed> $connection voice_provider_connections row
     * @param array<string, mixed> $event      parsed by the adapter
     * @param array<string, mixed> $payload    the signed body
     */
    public static function ingest(array $connection, array $event, array $payload): ?int
    {
        if (($event['event_type'] ?? '') !== 'call.inbound') {
            return null;
        }
        $legRef = (string) ($event['leg_ref'] ?? $event['provider_ref'] ?? '');
        if ($legRef === '') {
            return null;
        }

        $cmpId = (int) $connection['cmp_id'];
        $existing = Db::scalar(
            'SELECT call_id FROM voice_call_legs WHERE cmp_id = :cmp AND provider_leg_ref = :ref LIMIT 1',
            ['cmp' => $cmpId, 'ref' => $legRef],
        );
        if ($existing !== null) {
            return (int) $existing;
        }

        $ctx = Context::forCompany($cmpId, (int) ($connection['bo_id'] ?? 0));

        // The number that was called says which country a national caller id
        // is in; the company's region otherwise.
        $toRaw = (string) ($payload['to'] ?? '');
        $local = PhoneNumber::toE164($toRaw, CallingPolicy::regionFor($ctx));
        $number = $local === null ? null : Db::first(
            'SELECT number_id, country, bo_id FROM voice_numbers WHERE cmp_id = :cmp AND e164 = :e164 LIMIT 1',
            ['cmp' => $cmpId, 'e164' => $local],
        );
        $remote = PhoneNumber::toE164(
            (string) ($payload['from'] ?? ''),
            CallingPolicy::regionFor($ctx, $number === null ? null : (string) $number['country']),
        );

        return Db::transaction(static function () use ($ctx, $connection, $number, $local, $remote, $legRef): int {
            $callUuid = Uuid::v4();
            $callId = (int) Db::insert('voice_calls', [
                'call_uuid'      => $callUuid,
                'cmp_id'         => $ctx->cmpId,
                'bo_id'          => $number === null ? $ctx->boId : (int) $number['bo_id'],
                'connection_id'  => (int) $connection['connection_id'],
                'number_id'      => $number === null ? null : (int) $number['number_id'],
                'direction'      => 'inbound',
                'origin'         => 'INBOUND',
                'remote_e164'    => $remote,
                'local_e164'     => $local,
                'handled_by'     => 'human',
                'state'          => 'initiated',
                'recording_state' => 'none',
                'consent_state'  => 'not_applicable',
                'correlation_id' => $callUuid,
                'contact_lookup_state' => $remote === null ? 'no_match' : 'not_attempted',
                'initiated_at'   => Clock::sql(Clock::now()),
            ], 'call_id');

            Db::insert('voice_call_legs', [
                'call_id'          => $callId,
                'cmp_id'           => $ctx->cmpId,
                'provider_leg_ref' => $legRef,
                'leg_role'         => 'customer',
                'endpoint'         => $remote,
                'state'            => 'initiated',
            ], 'leg_id');

            return $callId;
        });
    }

    /**
     * Who is calling? A company lookup under the viewing agent's session.
     *
     * @return array{state: string, matchCount: ?int, contact: ?array<string, mixed>, candidates: list<array<string, mixed>>, message: ?string}
     */
    public static function identify(Context $ctx, Auth $auth, int $callId): array
    {
        $call = CallService::row($ctx, $callId);
        if ($call === null) {
            return ['state' => 'not_found', 'matchCount' => null, 'contact' => null, 'candidates' => [], 'message' => 'That call is not in this company.'];
        }

        $remote = (string) ($call['remote_e164'] ?? '');
        if ($remote === '') {
            return self::record($callId, $ctx, 'no_match', 0, null, 'There is no caller number to look up.');
        }

        $client = (new ContactsClient())->withSession($auth->sesKey());
        $region = CallingPolicy::regionFor($ctx);

        // Already linked: read the contact for display, link unchanged.
        if (($call['contact_ref'] ?? null) !== null && (string) $call['contact_ref'] !== '') {
            $linked = $client->contact($ctx->cmpId, (string) $call['contact_ref'], $region);
            if ($linked['ok']) {
                return ['state' => 'matched', 'matchCount' => 1, 'contact' => $linked['data'], 'candidates' => [], 'message' => null];
            }
        }

        $result = $client->lookupPhone($ctx->cmpId, $remote, $region);
        if (!$result['ok']) {
            return match ($result['kind']) {
                'forbidden' => self::record($callId, $ctx, 'forbidden', null, null, 'You cannot read this company’s contacts in Aicountly Contacts.'),
                default     => self::record($callId, $ctx, 'unavailable', null, null, 'The contact directory could not be checked right now. The number is shown as it arrived.'),
            };
        }

        $count = (int) ($result['meta']['matchCount'] ?? 0);
        if ($count === 0) {
            return self::record($callId, $ctx, 'no_match', 0, null, null);
        }
        if ($count === 1 && ($result['meta']['attributable'] ?? false) === true) {
            $contact = $result['data'][0];
            Db::update('voice_calls', ['contact_ref' => (string) $contact['id']], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);

            return self::record($callId, $ctx, 'matched', 1, $contact, null);
        }

        // Two people share the number (a switchboard, a family phone): never
        // pick one. The candidates are shown so a person can choose.
        $out = self::record($callId, $ctx, 'ambiguous', $count, null,
            $count . ' contacts in this company have this number, so none was linked automatically.');
        $out['candidates'] = $result['data'];

        return $out;
    }

    /** @return array{state: string, matchCount: ?int, contact: ?array<string, mixed>, candidates: list<array<string, mixed>>, message: ?string} */
    private static function record(int $callId, Context $ctx, string $state, ?int $count, ?array $contact, ?string $message): array
    {
        Db::update('voice_calls', [
            'contact_lookup_state'   => $state,
            'contact_lookup_at'      => Clock::sql(Clock::now()),
            'contact_lookup_matches' => $count,
        ], ['call_id' => $callId, 'cmp_id' => $ctx->cmpId]);

        return ['state' => $state, 'matchCount' => $count, 'contact' => $contact, 'candidates' => [], 'message' => $message];
    }
}
