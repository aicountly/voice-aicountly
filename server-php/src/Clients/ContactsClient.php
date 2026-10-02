<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Support\PhoneNumber;
use AicountlyContacts\ContactMergedException;
use AicountlyContacts\ContactsApiClient;
use AicountlyContacts\ContactsApiException;
use AicountlyContacts\ContactsUnauthorizedException;
use AicountlyContacts\ContactsUnavailableException;
use AicountlyContacts\ContactsValidationException;

require_once __DIR__ . '/vendor-contacts-client/ContactsApiClient.php';

/**
 * Voice's one adapter onto Aicountly Contacts — contract v1, COMPANY endpoints.
 *
 * Every call goes through the shared, vendored ContactsApiClient
 * (contacts-react-app clients/php, kept identical by
 * scripts/ci/contacts-client-drift-check.sh). This class only adds what is
 * Voice's: which company, which credential, the transport (re-entry guard,
 * timeouts, path-only logging) and one flat result shape for controllers.
 *
 * ## Why the company endpoints
 *
 * Voice is a company product: calls, callbacks and campaign audiences belong to
 * a company and are seen by every member entitled to them. The personal book
 * (`/api/contacts`) is one staff member's private address book, so a
 * contact_ref from it resolves for nobody else (G18#3). Every read here is
 * `/api/companies/{cmp}/contacts…`, where Contacts asks Manage about the
 * caller and applies the caller's Contacts role.
 *
 * ## Why one result shape
 *
 * The shapes Contacts really returns (displayName, phones[{value}], …) are read
 * ONCE, in view(), and callers see Voice's view of a contact. The old code
 * parsed a guessed flat shape (name / mobile) that only Voice's own test stub
 * produced, so real contacts were "Unnamed contact" with no number (G18#2).
 *
 * ## Credentials
 *
 *   withSession($sesKey)     the signed-in user, so THEIR Contacts access applies
 *   withDelegation($token)   a campaign worker, through a grant a user issued
 *                            while present (Domain\ContactsDelegation)
 *
 * Contacts outages are `unavailable`, never "no contact" and never a sign-out.
 *
 * ## Two things this product must never do
 *
 * 1. Read a name out of a TRANSCRIPT and treat it as a contact record.
 * 2. Create a contact just because an unknown number rang. Contacts are created
 *    deliberately, by a person, there, with an idempotency key.
 */
final class ContactsClient extends ApiClient
{
    /** This product's name to Contacts (X-AIC-Service). */
    public const PRODUCT = 'voice';

    private string $sesKey = '';
    private string $delegationToken = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);
        $clone->delegationToken = '';

        return $clone;
    }

    public function withDelegation(string $token): self
    {
        $clone = clone $this;
        $clone->delegationToken = trim($token);
        $clone->sesKey = '';

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('CONTACTS');
    }

    /** Voice's product key for Contacts (the raw key; Contacts holds only its sha256). */
    public static function serviceKey(): string
    {
        $key = trim(Env::get('CONTACTS_SERVICE_KEY'));

        return str_starts_with($key, 'CHANGE_ME') ? '' : $key;
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** Search the company directory. data = list of view(); meta = paging. */
    public function search(int $cmpId, string $term, int $page = 1, int $perPage = 20, ?string $region = null): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $term, $page, $perPage, $region): array {
            $result = $c->listCompanyContacts($cmpId, [
                'q'        => $term,
                'page'     => max(1, $page),
                'per_page' => max(1, min(100, $perPage)),
            ]);

            return self::ok(
                array_map(static fn (array $row): array => self::view($row, $region), $result['data']),
                $result['meta'],
            );
        });
    }

    /**
     * One company contact, following a merge to its survivor.
     *
     * kind 'gone' when Contacts says it was deleted or the id is unknown here;
     * 'merged' is followed transparently and reported in meta.resolved_from.
     */
    public function contact(int $cmpId, string $contactId, ?string $region = null): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $contactId, $region): array {
            $requested = $contactId;
            for ($hop = 0; $hop < 5; $hop++) {
                try {
                    $row = $c->getCompanyContact($cmpId, $contactId);
                } catch (ContactMergedException $e) {
                    $row = null;
                    $survivor = $e->survivorId();
                    if ($survivor !== null && $survivor !== $contactId) {
                        $contactId = $survivor;
                        continue;
                    }
                }

                if ($row !== null && ($row['state'] ?? 'active') !== 'merged') {
                    return self::ok(self::view($row, $region), [
                        'resolved_from' => $requested !== $contactId ? $requested : null,
                    ]);
                }

                // Not readable as itself: ask Contacts what became of it.
                $resolved = $c->resolveCompany($cmpId, $contactId);
                $state = (string) ($resolved['state'] ?? 'unknown');
                $survivor = isset($resolved['survivorId']) ? (string) $resolved['survivorId'] : '';
                if ($state === 'merged' && $survivor !== '' && $survivor !== $contactId) {
                    $contactId = $survivor;
                    continue;
                }
                if (is_array($resolved['contact'] ?? null) && $state !== 'deleted') {
                    return self::ok(self::view($resolved['contact'], $region), [
                        'resolved_from' => $requested !== $contactId ? $requested : null,
                    ]);
                }

                return self::fail('gone', 404, 'contact_' . ($state === 'deleted' ? 'deleted' : 'not_found'),
                    $state === 'deleted'
                        ? 'That contact was deleted in Aicountly Contacts.'
                        : 'That contact is not in this company’s directory.');
            }

            return self::fail('gone', 404, 'merge_chain_too_long', 'That contact could not be resolved.');
        });
    }

    /**
     * Who has this number, in this company? Never auto-attribute unless
     * meta.matchCount is exactly 1 (contract v1 §3.4) AND the contact really
     * carries this number.
     *
     * data = list of view(); meta = {matchCount, attributable}.
     */
    public function lookupPhone(int $cmpId, string $e164, ?string $region = null): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $e164, $region): array {
            $found = $c->lookupCompany($cmpId, ['phone' => $e164]);
            $views = array_map(static fn (array $row): array => self::view($row, $region), $found['contacts']);

            // Belt and braces: the single match must hold this exact number.
            $holds = $found['matchCount'] === 1 && count($views) === 1
                && in_array($e164, array_column($views[0]['phones'], 'e164'), true);

            return self::ok($views, [
                'matchCount'   => $found['matchCount'],
                'attributable' => $holds,
            ]);
        });
    }

    /**
     * Resolve many stored ids at once (audience checks). data = list of
     * {id, state, survivorId, readable}.
     *
     * @param list<string> $ids at most 100
     */
    public function resolveMany(int $cmpId, array $ids): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $ids): array {
            $rows = $c->resolveManyCompany($cmpId, array_values(array_slice($ids, 0, 100)));
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'id'         => (string) ($row['id'] ?? ''),
                    'state'      => (string) ($row['state'] ?? 'unknown'),
                    'survivorId' => isset($row['survivorId']) ? (string) $row['survivorId'] : null,
                    'readable'   => is_array($row['contact'] ?? null),
                ];
            }

            return self::ok($out);
        });
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Create (or find the existing) company contact, when a person asked for one.
     *
     * @param array{display_name?:string, phone?:string, email?:string, organization?:string} $input already validated
     */
    public function create(int $cmpId, array $input, string $idempotencyKey, ?string $region = null): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $input, $idempotencyKey, $region): array {
            $body = ['displayName' => (string) ($input['display_name'] ?? '')];
            if (($input['phone'] ?? '') !== '') {
                $body['phones'] = [['value' => (string) $input['phone']]];
            }
            if (($input['email'] ?? '') !== '') {
                $body['emails'] = [['value' => (string) $input['email']]];
            }
            if (($input['organization'] ?? '') !== '') {
                $body['organizationName'] = (string) $input['organization'];
            }

            $result = $c->findOrCreateCompanyContact($cmpId, $body, $idempotencyKey);

            return self::ok(self::view($result['data'], $region), [
                'created'  => (bool) ($result['body']['created'] ?? true),
                'replayed' => $result['replayed'],
            ]);
        });
    }

    // -----------------------------------------------------------------------
    // Delegation grants (contract v1 §3.9) — issued while a user is present
    // -----------------------------------------------------------------------

    /**
     * Ask Contacts for a company-scoped grant a worker can use later. Needs
     * the user's session (withSession) AND Voice's product key.
     *
     * @param list<string> $scopes
     */
    public function issueDelegation(int $cmpId, array $scopes, int $ttlSeconds, string $purpose): array
    {
        if ($this->sesKey === '') {
            return self::fail('unauthorized', 401, 'no_session', 'A grant can only be issued while a signed-in person is present.');
        }
        if (self::serviceKey() === '') {
            return self::fail('not_configured', 0, 'service_key_missing',
                'CONTACTS_SERVICE_KEY is not set on this server, so Contacts cannot grant Voice access.');
        }

        return $this->raw('POST', 'companies/' . $cmpId . '/delegations', [
            'scopes'     => array_values($scopes),
            'ttlSeconds' => $ttlSeconds,
            'purpose'    => $purpose,
        ]);
    }

    /** Revoke a grant (the user who issued it, or an admin). */
    public function revokeDelegation(int $cmpId, string $grantId): array
    {
        if ($this->sesKey === '') {
            return self::fail('unauthorized', 401, 'no_session', 'Revoking a grant needs a signed-in person.');
        }

        return $this->raw('DELETE', 'companies/' . $cmpId . '/delegations/' . rawurlencode($grantId), null);
    }

    // -----------------------------------------------------------------------
    // The view Voice uses
    // -----------------------------------------------------------------------

    /**
     * Voice's view of a canonical Contacts contact. The ONE place the wire
     * shape is read.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function view(array $row, ?string $region = null): array
    {
        $phones = [];
        foreach (is_array($row['phones'] ?? null) ? $row['phones'] : [] as $phone) {
            $value = is_array($phone) ? trim((string) ($phone['value'] ?? '')) : '';
            if ($value === '') {
                continue;
            }
            $phones[] = [
                'value' => $value,
                'e164'  => PhoneNumber::toE164($value, $region),
                'label' => is_array($phone) && isset($phone['label']) ? (string) $phone['label'] : null,
            ];
        }

        $emails = [];
        foreach (is_array($row['emails'] ?? null) ? $row['emails'] : [] as $email) {
            $value = is_array($email) ? trim((string) ($email['value'] ?? '')) : '';
            if ($value !== '') {
                $emails[] = ['value' => $value, 'label' => is_array($email) && isset($email['label']) ? (string) $email['label'] : null];
            }
        }

        $state = (string) ($row['state'] ?? (($row['archivedAt'] ?? null) !== null ? 'archived' : 'active'));

        return [
            'id'           => (string) ($row['id'] ?? ''),
            'display_name' => (string) ($row['displayName'] ?? ''),
            'organization' => (string) ($row['organizationName'] ?? ''),
            'phones'       => $phones,
            'emails'       => $emails,
            'state'        => $state,
            'merged_into'  => isset($row['mergedIntoId']) && $row['mergedIntoId'] !== '' ? (string) $row['mergedIntoId'] : null,
            'archived'     => $state === 'archived',
            'scope'        => isset($row['cmpId']) ? 'company' : 'personal',
        ];
    }

    /**
     * The number to dial for a contact view, deliberately: a phone labelled
     * mobile when Contacts has labels, else the first phone that is a valid
     * E.164 in the company region (Contacts keeps phones in the owner's order,
     * first = primary). Null when none can be dialled.
     *
     * @param array<string, mixed> $view
     */
    public static function dialable(array $view): ?string
    {
        $phones = is_array($view['phones'] ?? null) ? $view['phones'] : [];
        foreach ($phones as $phone) {
            if (($phone['e164'] ?? null) !== null && strtolower((string) ($phone['label'] ?? '')) === 'mobile') {
                return (string) $phone['e164'];
            }
        }
        foreach ($phones as $phone) {
            if (($phone['e164'] ?? null) !== null) {
                return (string) $phone['e164'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------------

    /**
     * Run one call through the shared client and turn its exceptions into one
     * result shape:
     *   ok, status, kind (ok|unavailable|unauthorized|delegation_invalid|forbidden|
     *   not_found|gone|validation|conflict|rate_limited|not_configured|error),
     *   code, message, data, meta, retryable
     *
     * @param callable(ContactsApiClient): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function run(callable $call): array
    {
        if (!$this->configured()) {
            return self::fail('not_configured', 0, 'directory_disabled', 'The contact directory is not enabled for this deployment.');
        }
        $root = $this->apiRoot();
        if ($root === '') {
            return self::fail('not_configured', 0, 'environment_not_configured', \Aicountly\Api\Environment::explainUnconfigured());
        }
        if (CrossServiceCallContext::isInboundFrom('contacts')) {
            return self::fail('unavailable', 0, 'directory_reentrant_call_refused', 'Contacts is waiting on this request.');
        }

        $client = new ContactsApiClient($root, ['transport' => $this->transport(...)]);
        if ($this->delegationToken !== '') {
            $client = $client->withDelegation($this->delegationToken, self::PRODUCT, self::serviceKey());
        } elseif ($this->sesKey !== '') {
            $client = $client->withSession($this->sesKey);
        } else {
            return self::fail('unauthorized', 401, 'no_credential', 'Contacts is read as a signed-in person or through a grant; there is neither.');
        }

        try {
            return $call($client);
        } catch (ContactsUnavailableException $e) {
            return self::fail('unavailable', $e->httpStatus, $e->errorCode, 'Aicountly Contacts could not answer right now.', true);
        } catch (ContactsUnauthorizedException $e) {
            return $e->errorCode === 'delegation_invalid'
                ? self::fail('delegation_invalid', 401, 'delegation_invalid', 'The Contacts grant for this work has expired or was revoked.')
                : self::fail('unauthorized', 401, $e->errorCode, 'Aicountly Contacts did not accept the session.');
        } catch (ContactsValidationException $e) {
            return self::fail('validation', 400, $e->errorCode, $e->getMessage(), false, $e->details);
        } catch (ContactsApiException $e) {
            return match (true) {
                $e->httpStatus === 403 => self::fail('forbidden', 403, $e->errorCode, 'You do not have access to this in Aicountly Contacts.', false, $e->details),
                $e->httpStatus === 404 => self::fail('not_found', 404, $e->errorCode, 'Not found in Aicountly Contacts.'),
                $e->httpStatus === 409 => self::fail('conflict', 409, $e->errorCode, $e->getMessage(), false, $e->details),
                $e->httpStatus === 429 => self::fail('rate_limited', 429, $e->errorCode, 'Aicountly Contacts asked us to slow down.', true),
                $e->httpStatus >= 500  => self::fail('unavailable', $e->httpStatus, $e->errorCode, 'Aicountly Contacts could not answer right now.', true),
                default                => self::fail('error', $e->httpStatus, $e->errorCode, $e->getMessage()),
            };
        } catch (\InvalidArgumentException $e) {
            return self::fail('validation', 400, 'invalid_request', $e->getMessage());
        } catch (\JsonException) {
            return self::fail('validation', 400, 'invalid_request', 'The request could not be encoded.');
        }
    }

    /** A call the shared client has no method for (delegations), with the same result shape. */
    private function raw(string $method, string $path, ?array $body): array
    {
        if (!$this->configured()) {
            return self::fail('not_configured', 0, 'directory_disabled', 'The contact directory is not enabled for this deployment.');
        }
        $headers = [
            'Authorization'     => 'Bearer ' . $this->sesKey,
            'X-AIC-Service'     => self::PRODUCT,
            'X-AIC-Service-Key' => self::serviceKey(),
        ];
        $result = $this->request($method, $path, $body, $headers, true);
        if ($result['ok']) {
            return self::ok(is_array($result['body']['data'] ?? null) ? $result['body']['data'] : []);
        }

        $code = (string) ($result['body']['error']['code'] ?? $result['error'] ?? 'error');
        $message = (string) ($result['body']['error']['message'] ?? $result['body']['message'] ?? $result['error'] ?? 'Contacts refused.');
        $status = (int) $result['status'];

        return match (true) {
            $result['error'] === 'environment_not_configured' => self::fail('not_configured', 0, 'environment_not_configured', \Aicountly\Api\Environment::explainUnconfigured()),
            $status === 0 || $status >= 500 => self::fail('unavailable', $status, $code, 'Aicountly Contacts could not answer right now.', true),
            $status === 401 => self::fail('unauthorized', 401, $code, $message),
            $status === 403 => self::fail('forbidden', 403, $code, $message),
            $status === 404 => self::fail('not_found', 404, $code, $message),
            $status === 400 => self::fail('validation', 400, $code, $message),
            default         => self::fail('error', $status, $code, $message),
        };
    }

    /**
     * The shared client's transport: Voice's timeouts, our name on the call
     * (so Contacts does not call us back inside it), path-only logging.
     *
     * @param list<string> $headerLines
     * @return array{0:int, 1:array<string,string>, 2:string}
     */
    private function transport(string $method, string $url, array $headerLines, ?string $payload): array
    {
        $headerLines[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $headerLines[] = 'X-Source-App: ' . $this->selfName();

        $respHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, [], ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => $method === 'GET' ? self::TOTAL_TIMEOUT_OPTIONAL : self::TOTAL_TIMEOUT_REQUIRED,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 0 || $status >= 400) {
            error_log(sprintf(
                '[cross-service] service=contacts outcome=%s operation=%s status=%d',
                $status === 0 ? 'failed' : 'error',
                (string) (parse_url($url, PHP_URL_PATH) ?? ''),
                $status,
            ));
        }

        return [$status, $respHeaders, $raw === false ? '' : (string) $raw];
    }

    /** @return array<string, mixed> */
    private static function ok(mixed $data, array $meta = []): array
    {
        return ['ok' => true, 'status' => 200, 'kind' => 'ok', 'code' => null, 'message' => null,
            'data' => $data, 'meta' => $meta, 'retryable' => false, 'details' => []];
    }

    /** @return array<string, mixed> */
    private static function fail(string $kind, int $status, string $code, string $message, bool $retryable = false, array $details = []): array
    {
        return ['ok' => false, 'status' => $status, 'kind' => $kind, 'code' => $code, 'message' => $message,
            'data' => null, 'meta' => [], 'retryable' => $retryable, 'details' => $details];
    }
}
