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
 *  2. A product backend — `X-Service-Key` + `X-AIC-Environment`, held to
 *     ServicePolicy: only its listed routes and permissions, only companies its
 *     acting person belongs to (their session forwarded as the Bearer, asked of
 *     Manage) or that are explicitly bound to it. A bare `X-Actor-Uuid` is a
 *     claim, recorded and never acted on. Lobby books a callback this way.
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
        // A service call naming a person by X-Actor-Uuid without their session:
        // recorded for the audit trail, never acted on (ServicePolicy).
        public readonly ?string $claimedActor = null,
        // Set only for the live stream opened with a ticket: the company the
        // ticket was issued for, and Manage's ownership verdict at that moment.
        public readonly ?int $streamCompany = null,
        public readonly bool $streamOwner = false,
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

        // Per user, so a stand-in for Manage can tell an owner from a member.
        return new self($uuid, $kind, $sourceApp, $kind === 'user' ? 'test-ses-key-' . $uuid : '', $session);
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
            return self::resolveService($serviceKey);
        }

        // The live stream authenticates with a single-use ticket, never with
        // a session key in its URL (see StreamTickets).
        $ticket = self::streamTicket();
        if ($ticket !== '') {
            $row = StreamTickets::redeem($ticket);

            return $row === null ? null : new self(
                (string) $row['user_uuid'], 'user', Env::get('APP_PRODUCT_KEY', 'voice'), '', null, null,
                (int) $row['cmp_id'], (bool) $row['is_owner'],
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

    /**
     * Another product's backend (ServicePolicy). The key proves WHICH product;
     * the environment must be ours; a person is named only by their own
     * session sent as the Bearer, which then also binds the company (Manage is
     * asked with it). Without a session the product acts as itself, only on
     * the routes and companies explicitly allowed to it.
     */
    private static function resolveService(string $serviceKey): ?self
    {
        $app = ServiceKeys::resolveApp($serviceKey);
        if ($app === null) {
            return null;
        }
        if (!ServicePolicy::environmentMatches(Http::header('X-AIC-Environment'))) {
            Http::error(401, 'service_environment_mismatch',
                'A service call must say which environment it is for (X-AIC-Environment), and it must be this one.');
        }

        // Proven by the key, not claimed in a header. Recording it is what
        // stops us calling that product back inside its own request.
        CrossServiceCallContext::adoptAuthenticatedOrigin($app);
        $claimed = Http::header('X-Actor-Uuid');

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return new self('service:' . $app, 'service', $app, '', null, $claimed !== '' ? $claimed : null);
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }
        $uuid = (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? '');
        if ($claimed !== '' && $claimed !== $uuid) {
            Http::error(401, 'actor_mismatch', 'X-Actor-Uuid does not match the session sent with it.');
        }

        // The verified person, acting through the product: their session is
        // kept so Manage decides whether they belong to the company.
        return new self($uuid, 'service', $app, $sesKey, $session, null);
    }

    /** A service call that carries the acting person's own, validated session. */
    public function hasVerifiedActor(): bool
    {
        return $this->isService() && $this->sesKey !== '';
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

    // There is deliberately no accessType(). The portal's validatesession never
    // returns `acs_type`; who owns a company is Manage's answer, read per
    // company in Context::assertAllowed and asked through Context::isOwner().

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
     * The stream ticket, on the one route that takes it (/v1/events).
     *
     * The browser's EventSource cannot set headers. It used to carry the
     * ses_key in the URL instead — a bearer for every product, in access logs
     * and history. Now the URL carries a 30-second, single-use ticket bound to
     * this user, company and route, minted over an authenticated POST.
     */
    private static function streamTicket(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if (!str_ends_with(rtrim($path, '/'), '/v1/events')) {
            return '';
        }
        $ticket = $_GET['ticket'] ?? '';

        return is_string($ticket) ? trim($ticket) : '';
    }

    /** The session key from the Authorization header — never from a URL. */
    private static function bearer(): string
    {
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
