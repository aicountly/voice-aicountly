<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Http;

/**
 * The contact directory, read LIVE from Aicountly Contacts.
 *
 * ## There is no contacts table here
 *
 * Every response on these routes is assembled from a call to Contacts, made
 * with the signed-in user's own session — so THEIR Contacts permissions apply,
 * rather than Voice re-implementing another product's access rules.
 *
 * ## When Contacts is unavailable
 *
 * The endpoint answers 503 and says so. It does not serve a stale copy, because
 * there is no copy. A call screen shows the number it has — which is Voice's
 * own record of what was dialled — and says the directory cannot be reached.
 *
 * ## What Voice adds
 *
 * `calling_history` — Voice's own calls to that contact reference. That IS
 * Voice's data: it is a record of calls this product placed and received. It is
 * returned alongside the live contact, never merged into it.
 */
final class ContactsController extends Controller
{
    public static function search(): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $client = new ContactsClient();
        if (!$client->configured()) {
            self::fail('owner_unavailable', 'The contact directory is not enabled for this deployment.');
        }

        $term = trim((string) (Http::param('q') ?? ''));
        $phone = Http::param('phone');

        $result = $phone !== null && $phone !== ''
            ? $client->withSession($auth->sesKey())->lookupByPhone((string) $phone, $ctx->asQuery())
            : $client->withSession($auth->sesKey())->search($term, $ctx->asQuery());

        if (!$result['ok']) {
            self::fail(
                'owner_unavailable',
                'The contact directory could not be reached. Numbers are still shown from the call record.',
                ['status' => $result['status']],
            );
        }

        $body = $result['body'] ?? [];

        Http::json(200, [
            'data' => $body['data'] ?? $body,
            'meta' => ($body['meta'] ?? []) + [
                'source' => 'contacts',
                'live'   => true,
            ],
        ]);
    }

    public static function show(string $contactRef): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $client = new ContactsClient();
        if (!$client->configured()) {
            self::fail('owner_unavailable', 'The contact directory is not enabled for this deployment.');
        }

        $result = $client->withSession($auth->sesKey())->contact($contactRef);
        if (!$result['ok']) {
            if ($result['status'] === 404) {
                Http::notFound('That contact is not in the directory.');
            }
            self::fail('owner_unavailable', 'The contact directory could not be reached.', ['status' => $result['status']]);
        }

        $body = $result['body'] ?? [];

        // Voice's OWN calling history for that reference. Not part of the
        // contact record, and returned as a separate key so nothing downstream
        // mistakes one for the other.
        [$scope, $params] = $ctx->scopeClause();
        $params['ref'] = $contactRef;

        $history = Db::all(
            'SELECT call_id, call_uuid, direction, initiated_at, talk_seconds, outcome, remote_e164
               FROM voice_calls WHERE ' . $scope . ' AND contact_ref = :ref
              ORDER BY initiated_at DESC LIMIT 20',
            $params,
        );

        Http::json(200, [
            'data' => [
                // Straight from Contacts, on this request.
                'contact' => $body['data'] ?? $body,
                'calling_history' => array_map(static fn (array $r): array => [
                    'call_id'      => (int) $r['call_id'],
                    'call_uuid'    => (string) $r['call_uuid'],
                    'direction'    => (string) $r['direction'],
                    'initiated_at' => $r['initiated_at'],
                    'talk_seconds' => (int) $r['talk_seconds'],
                    'outcome'      => $r['outcome'],
                    'remote_masked' => $r['remote_e164'] === null ? null : CallingPolicy::mask((string) $r['remote_e164']),
                ], $history),
            ],
            'meta' => [
                'contact_source' => 'contacts',
                'history_source' => 'voice',
                'note' => 'The contact is read live from Aicountly Contacts. The calling history is Voice’s own record.',
            ],
        ]);
    }

    /**
     * Create a contact, deliberately.
     *
     * Not something that happens because an unknown number rang — a wrong
     * number is not a new customer. This is an explicit action by a person, and
     * the contact is created in CONTACTS with an idempotency key so a double
     * tap does not produce two people.
     */
    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $client = new ContactsClient();
        if (!$client->configured()) {
            self::fail('owner_unavailable', 'The contact directory is not enabled for this deployment.');
        }

        $body = Http::body();
        $key = \Aicountly\Api\Idempotency::fromRequest() ?? ('voice-contact-' . \Aicountly\Api\Support\Uuid::v4());

        $result = $client->withSession($auth->sesKey())->create($body + $ctx->asQuery(), $key);

        if (!$result['ok']) {
            // Nothing is written locally as a fallback. There is nowhere to
            // write it, and a Voice-side contact would be a second master.
            self::fail(
                'owner_unavailable',
                'The contact could not be created in Aicountly Contacts, so it has not been created anywhere.',
                ['status' => $result['status']],
            );
        }

        Http::json(201, ['data' => $result['body']['data'] ?? $result['body'] ?? []]);
    }
}
