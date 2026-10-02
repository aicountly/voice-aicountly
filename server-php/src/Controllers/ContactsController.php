<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Http;

/**
 * The company contact directory, read LIVE from Aicountly Contacts.
 *
 * ## There is no contacts table here
 *
 * Every response on these routes is assembled from a call to Contacts' COMPANY
 * endpoints (`/api/companies/{cmp}/contacts…`), made with the signed-in user's
 * own session — so Manage's verdict and THEIR Contacts role apply, rather than
 * Voice re-implementing another product's access rules. A contact_ref stored on
 * a call is therefore a company contact id that every entitled colleague can
 * open, not one person's private address-book id (G18#3).
 *
 * ## What comes back
 *
 * Voice's view of a contact (ContactsClient::view): id, display_name,
 * organization, phones[{value, e164, label}], emails, state — read from the
 * shape Contacts really returns (displayName, phones[{value}]), not a guessed
 * one (G18#2).
 *
 * ## When Contacts says no, or cannot answer
 *
 * Told apart (G18#7): unavailable → 503 retryable; Contacts refusing the
 * session → 502 (never a sign-out here); no access in Contacts → 403; not in
 * this company → 404; Contacts' own validation → 422 with its message. It never
 * serves a stale copy, because there is no copy.
 *
 * ## What Voice adds
 *
 * `calling_history` — Voice's own calls to that contact reference. That IS
 * Voice's data. It is returned alongside the live contact, never merged into it.
 */
final class ContactsController extends Controller
{
    public static function search(): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $client = (new ContactsClient())->withSession($auth->sesKey());
        $region = CallingPolicy::regionFor($ctx);
        $phone = trim((string) (Http::param('phone') ?? ''));

        if ($phone !== '') {
            // Who has this number? The number is put into E.164 the same way a
            // dial would be, so a national form finds the stored +CC form.
            $e164 = CallingPolicy::normaliseFor($ctx, $phone);
            if ($e164 === null) {
                Http::validationFailed('That is not a phone number Voice can read.', ['reason' => 'invalid_number']);
            }
            $result = $client->lookupPhone($ctx->cmpId, $e164, $region);
            self::answerFailure($result);

            Http::json(200, [
                'data' => $result['data'],
                'meta' => $result['meta'] + ['source' => 'contacts', 'scope' => 'company', 'live' => true, 'phone' => $e164],
            ]);
        }

        $page = max(1, Http::intParam('page', 1) ?? 1);
        $perPage = max(1, min(100, Http::intParam('per_page', 20) ?? 20));
        $result = $client->search($ctx->cmpId, trim((string) (Http::param('q') ?? '')), $page, $perPage, $region);
        self::answerFailure($result);

        Http::json(200, [
            'data' => $result['data'],
            'meta' => $result['meta'] + ['source' => 'contacts', 'scope' => 'company', 'live' => true],
        ]);
    }

    public static function show(string $contactRef): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $result = (new ContactsClient())->withSession($auth->sesKey())
            ->contact($ctx->cmpId, $contactRef, CallingPolicy::regionFor($ctx));
        self::answerFailure($result);

        $contact = $result['data'];

        // Voice's OWN calling history, for the id that was asked for and, when
        // Contacts merged it, the survivor it resolved to.
        [$scope, $params] = $ctx->scopeClause();
        $params['ref'] = $contactRef;
        $params['resolved'] = (string) $contact['id'];

        $history = Db::all(
            'SELECT call_id, call_uuid, direction, initiated_at, talk_seconds, outcome, remote_e164
               FROM voice_calls WHERE ' . $scope . ' AND contact_ref IN (:ref, :resolved)
              ORDER BY initiated_at DESC LIMIT 20',
            $params,
        );

        Http::json(200, [
            'data' => [
                // Straight from Contacts, on this request.
                'contact' => $contact,
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
                'resolved_from'  => $result['meta']['resolved_from'] ?? null,
                'note' => 'The contact is read live from Aicountly Contacts. The calling history is Voice’s own record.',
            ],
        ]);
    }

    /**
     * Create a company contact, deliberately.
     *
     * Not something that happens because an unknown number rang — a wrong
     * number is not a new customer. A person asks; the contact is created in
     * CONTACTS (find-or-create, so an existing match is returned rather than
     * duplicated) with an idempotency key. Only these fields are sent, mapped
     * to Contacts' shape — nothing else a browser adds reaches Contacts.
     */
    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.call.view');

        $body = Http::body();
        $name = trim((string) ($body['display_name'] ?? $body['displayName'] ?? $body['name'] ?? ''));
        $phoneRaw = trim((string) ($body['phone'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));

        $phone = null;
        if ($phoneRaw !== '') {
            $phone = CallingPolicy::normaliseFor($ctx, $phoneRaw);
            if ($phone === null) {
                Http::validationFailed('That is not a phone number Voice can read.', ['field' => 'phone']);
            }
        }
        if ($name === '' && $phone === null && $email === '') {
            Http::validationFailed('Give at least a name, a phone number or an e-mail.');
        }

        $key = \Aicountly\Api\Idempotency::fromRequest() ?? ('voice-contact-' . \Aicountly\Api\Support\Uuid::v4());
        $result = (new ContactsClient())->withSession($auth->sesKey())->create($ctx->cmpId, [
            'display_name' => $name,
            'phone'        => $phone ?? '',
            'email'        => $email,
            'organization' => trim((string) ($body['organization'] ?? '')),
        ], $key, CallingPolicy::regionFor($ctx));

        // Nothing is written locally as a fallback. There is nowhere to write
        // it, and a Voice-side contact would be a second master.
        self::answerFailure($result, 'The contact could not be created in Aicountly Contacts, so it has not been created anywhere.');

        Http::json(($result['meta']['created'] ?? true) ? 201 : 200, [
            'data' => $result['data'],
            'meta' => ['created' => (bool) ($result['meta']['created'] ?? true), 'idempotency_key' => $key],
        ]);
    }

    /**
     * Answer a Contacts failure with the status that means the same thing.
     *
     * @param array<string, mixed> $result
     */
    private static function answerFailure(array $result, ?string $unavailableMessage = null): void
    {
        if ($result['ok']) {
            return;
        }

        $detail = ['directory_code' => $result['code']];

        match ($result['kind']) {
            'not_configured' => self::fail('owner_unavailable', (string) $result['message'], $detail + ['retryable' => false]),
            'unavailable', 'rate_limited' => self::fail(
                'owner_unavailable',
                $unavailableMessage ?? 'The contact directory could not be reached. Numbers are still shown from the call record.',
                $detail,
            ),
            'unauthorized' => Http::error(502, 'directory_refused_session',
                'Aicountly Contacts did not accept your session. Reload; sign in again only if this keeps happening.',
                $detail + ['retryable' => true]),
            'forbidden' => Http::forbidden('You do not have access to this in Aicountly Contacts.'),
            'not_found', 'gone' => Http::notFound((string) ($result['message'] ?? 'That contact is not in this company’s directory.')),
            'validation' => Http::validationFailed((string) $result['message'], $detail + ['fields' => $result['details']['fields'] ?? null]),
            'conflict' => Http::conflict((string) $result['message'], $detail + ['retryable' => false]),
            default => self::fail('owner_unavailable', (string) $result['message'], $detail),
        };
    }
}
