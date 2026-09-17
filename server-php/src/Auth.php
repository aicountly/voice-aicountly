<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Two ways in, and a third thing that is deliberately NOT a way in:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     The ses_key is kept so Voice can call Contacts, CRM, Calendar and Manage
 *     AS THAT USER. That is what makes their permissions apply over there
 *     instead of Voice re-implementing another product's access rules.
 *
 *  2. A trusted product backend — `X-Service-Key`, plus `X-Actor-Uuid` naming
 *     the human it is acting for. Lobby books a callback this way: the visitor
 *     at the desk has no session here, and the receptionist who typed it is not
 *     the agent whose queue it lands in.
 *
 *  3. NOT a telephony provider. A carrier callback carries no AICOUNTLY
 *     identity and must never resolve to one — it is authenticated by the
 *     provider's own signature scheme against the connection it claims to be
 *     for, in Telephony\WebhookVerifier, and it may only move Voice-owned call
 *     state. It cannot read a transcript, launch a campaign or reach another
 *     product. See Controllers/WebhooksController.
 *
 * `sourceApp` is decided HERE and never read from a header: a service key
 * resolves to its product, a human session is always this product. A call
 * record that claims `origin: LOBBY` while arriving on a browser session is a
 * caller trying to launder the origin of a call, and the origin it gets is the
 * one proven by its credential.
 */
final class Auth
{
    private function __construct(
        public readonly string $uuid,
        public readonly string $kind,      // 'user' | 'service'
        public readonly string $sourceApp,
        private readonly string $sesKey,
        private readonly ?array $session,
    ) {
    }

    /**
     * The caller a CLI test has stood in as.
     *
     * The same seam as ResponseSent, for the same reason: a controller resolves
     * its caller from HTTP headers, which a test has none of. Rather than let
     * tests reach past the controllers into the services — where the permission
     * checks are not — they adopt an identity and call the real endpoint.
     *
     * CLI ONLY. Under a web SAPI this is ignored outright, so it cannot become
     * an authentication bypass however it is called.
     */
    private static ?self $adopted = null;

    public static function adopt(?self $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$adopted = $auth;
    }

    /** Build an identity for a test. CLI only, for the same reason as adopt(). */
    public static function forTesting(string $uuid, string $kind = 'user', string $sourceApp = 'voice', array $session = []): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Auth::forTesting is CLI only.');
        }

        return new self($uuid, $kind, $sourceApp, $kind === 'user' ? 'test-ses-key' : '', $session);
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        if (PHP_SAPI === 'cli' && self::$adopted !== null) {
            return self::$adopted;
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            $app = ServiceKeys::resolveApp($serviceKey);
            if ($app === null) {
                return null;
            }
            // Proven by the key, not claimed in a header. Recording it is what
            // stops us calling that product back inside its own request.
            CrossServiceCallContext::adoptAuthenticatedOrigin($app);
            $actor = Http::header('X-Actor-Uuid');

            return new self(
                $actor !== '' ? $actor : 'service:' . $app,
                'service',
                $app,
                '',
                null,
            );
        }

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return null;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            'user',
            Env::get('APP_PRODUCT_KEY', 'voice'),
            $sesKey,
            $session,
        );
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The session key, for calling Contacts / CRM / Calendar / Manage as this user.
     *
     * Empty for a service caller, which is correct: a service acts with its own
     * key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself, which must not reach a log or a cache key. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    /** Portal access type for the company when the portal reported one: 1 = owner. */
    public function accessType(): ?int
    {
        return isset($this->session['acs_type']) ? (int) $this->session['acs_type'] : null;
    }

    public function displayName(): string
    {
        foreach (['name', 'full_name', 'user_name', 'email'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->uuid;
    }

    /**
     * The call origin this caller is entitled to claim.
     *
     * Decided by the credential, never by the request body. A dashboard that
     * attributes calls to Lobby is only worth reading if a browser cannot write
     * that label onto its own calls.
     */
    public function provenOrigin(): string
    {
        if (!$this->isService()) {
            return 'AGENT_CONSOLE';
        }

        return match ($this->sourceApp) {
            'lobby'        => 'LOBBY',
            'crm'          => 'CRM',
            'sales'        => 'SALES',
            'appointments' => 'APPOINTMENTS',
            'pos'          => 'POS',
            default        => 'API_INTEGRATION',
        };
    }

    /**
     * The session key from the request.
     *
     * Normally the Authorization header. The one exception is the EventSource
     * stream: the browser's EventSource cannot set headers at all, so the key
     * may also arrive as `access_token` on that ONE route.
     *
     * That is a real widening and it is bounded deliberately:
     *   - only /v1/events accepts it, checked against the request path here;
     *   - the request is same-origin, so the key does not cross a domain;
     *   - the server never logs the query string (see Clients\ApiClient::log).
     *
     * Allowing it everywhere would put session keys in access logs, in
     * `Referer` headers and in browser history for every request this product
     * makes, which is exactly why it is not allowed everywhere.
     */
    private static function bearer(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if (str_ends_with(rtrim($path, '/'), '/v1/events')) {
            $fromQuery = $_GET['access_token'] ?? '';
            if (is_string($fromQuery) && $fromQuery !== '') {
                return trim($fromQuery);
            }
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
