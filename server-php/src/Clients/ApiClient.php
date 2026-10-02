<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;
use Aicountly\Api\Environment;

/**
 * Base for every outbound call to another AICOUNTLY product.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: authoritative data owned by another
 * product is READ LIVE, here, on the request that needs it. It is never copied
 * into this product's database, never refreshed by a cron, never mirrored into
 * a "cache" table. A short-lived in-request memo (see $memo) is the only thing
 * that survives a call, and it dies with the process.
 *
 * That rule is sharpest for Calendar. An appointment row here holds a
 * `calendar_event_uuid` and nothing else about the event — not the start time,
 * not the end time, not the title. Every screen that shows when an appointment
 * is reads it from Calendar on that request, because a copied start time is a
 * start time that stays wrong after somebody moves the meeting in Google.
 *
 * Two timeout budgets, both with a connect bound, because a product that is up
 * but not accepting — every child blocked waiting on another pool — is held
 * only by CURLOPT_CONNECTTIMEOUT:
 *
 *   optional  connect 2s / total 6s   a screen degrades, stored data is shown
 *   required  connect 3s / total 20s  a write the user is waiting on
 */
abstract class ApiClient
{
    protected const CONNECT_TIMEOUT_OPTIONAL = 2;
    protected const TOTAL_TIMEOUT_OPTIONAL   = 6;
    protected const CONNECT_TIMEOUT_REQUIRED = 3;
    protected const TOTAL_TIMEOUT_REQUIRED   = 20;

    /**
     * Per-request memo of GET responses, keyed by method+url and the identity
     * the request was made as.
     *
     * This is deliberately process-local and dies with the request. It exists so
     * one screen that needs the same item list in three places costs one call,
     * not three — never so a later request can skip asking. Nothing here is
     * written to disk or to the database.
     *
     * The identity is part of the key because a service-key call names its
     * person in X-Actor-Uuid, not in Authorization: keyed on the URL alone, a
     * read for one agent would be answered with the previous agent's.
     *
     * @var array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private array $memo = [];

    /**
     * Whether GET answers may be reused within the request at all.
     *
     * A client whose reads decide writes — Calendar's lookup before a resend,
     * its free/busy before a booking — turns this off: an answer from earlier
     * in the same request may already be wrong.
     */
    protected function memoises(): bool
    {
        return true;
    }

    /** Product name this client talks to: calendar | contacts | manage | crm | pay | … */
    abstract public function service(): string;

    /** Production origin, used when nothing more specific is configured. */
    abstract protected function productionBase(): string;

    /** Sandbox origin. */
    abstract protected function sandboxBase(): string;

    /** Environment variable that overrides the derived base, e.g. BOOKS_API_BASE. */
    abstract protected function baseEnvKey(): string;

    /** This product's own name, sent so the callee will not call us back inside our own request. */
    protected function selfName(): string
    {
        return Env::get('APP_PRODUCT_KEY', 'voice');
    }

    /**
     * Where this product's sibling lives.
     *
     * From CONFIGURATION ONLY: an explicit `*_API_BASE`, else the sibling's
     * production or sandbox host for the environment this server is configured
     * as (see Environment). Never from the request's Host header — that is a
     * claim, and it used to decide which Manage answered the tenant check.
     *
     * Returns '' when the environment is not configured; request() then
     * refuses without calling anybody.
     */
    public function base(): string
    {
        $configured = Env::get($this->baseEnvKey());
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return match (Environment::siblingTier()) {
            'production' => $this->productionBase(),
            'sandbox'    => $this->sandboxBase(),
            default      => '',
        };
    }

    /** The API root. A configured base may already include /api, and a local spark origin serves it at the root. */
    public function apiRoot(): string
    {
        $base = $this->base();
        if ($base === '') {
            return '';
        }
        if (preg_match('#/api$#', $base) === 1 || preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $base) === 1) {
            return $base;
        }

        return $base . '/api';
    }

    /**
     * One call to the other product.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers extra headers (auth is added by the caller through withAuth)
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = [], bool $required = false): array
    {
        $method = strtoupper($method);

        // RE-ENTRY GUARD. If the product we are about to call is the one whose
        // request we are serving, its worker is already blocked on our response.
        // Calling it now parks a second one of its workers on a request that is
        // waiting on it. See CrossServiceCallContext.
        if (CrossServiceCallContext::isInboundFrom($this->service())) {
            $this->log('suppressed', $path, 0, 0.0, 'inbound_from_' . $this->service());

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_reentrant_call_refused'];
        }

        $root = $this->apiRoot();
        if ($root === '') {
            // Fail closed: with no configured environment there is no right
            // answer to "which Manage?", and a guess can be the wrong tenant.
            $this->log('refused', $path, 0, 0.0, 'environment_not_configured');

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'environment_not_configured'];
        }

        $url = $root . '/' . ltrim($path, '/');
        $memoKey = $method . ' ' . $url . ' ' . self::identityOf($headers);
        $memoise = $method === 'GET' && $this->memoises();
        if ($memoise && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init_failed'];
        }

        $wire = ['Accept: application/json'];
        if ($body !== null) {
            $wire[] = 'Content-Type: application/json';
        }
        // Name ourselves, so the callee suppresses its own calls back to us.
        $wire[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $wire[] = 'X-Source-App: ' . $this->selfName();
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $value;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => $required ? self::CONNECT_TIMEOUT_REQUIRED : self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => $required ? self::TOTAL_TIMEOUT_REQUIRED : self::TOTAL_TIMEOUT_OPTIONAL,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        $ms = (microtime(true) - $startedAt) * 1000;

        if ($raw === false || $status === 0) {
            $this->log('failed', $path, $status, $ms, $transportError !== '' ? 'transport' : 'no_response');

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $transportError !== '' ? $transportError : 'unreachable'];
        }

        $decoded = json_decode((string) $raw, true);
        $result = [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => null,
        ];
        if (!$result['ok']) {
            $result['error'] = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP ' . $status)
                : 'HTTP ' . $status;
            $this->log('error', $path, $status, $ms, null);
        }

        if ($memoise) {
            $this->memo[$memoKey] = $result;
        }

        return $result;
    }

    /**
     * Who a request was made as, for the memo key: the session, the service
     * key, the person it names and the company it acts for. Hashed, so no
     * credential sits in a key even in memory.
     *
     * @param array<string, string> $headers
     */
    private static function identityOf(array $headers): string
    {
        $identity = [];
        foreach (['Authorization', 'X-Service-Key', 'X-Actor-Uuid', 'X-Tenant-Ref'] as $name) {
            $identity[] = $headers[$name] ?? '';
        }

        return hash('sha256', implode("\n", $identity));
    }

    /**
     * Log the path only — never the query string, which carries identifiers, and
     * never tokens or response bodies. A fast success is silent: these run on
     * every company-scoped request and logging each one buries the failures the
     * log exists to surface.
     */
    private function log(string $outcome, string $path, int $status, float $ms, ?string $reason): void
    {
        $operation = explode('?', ltrim($path, '/'))[0];
        error_log(sprintf(
            '[cross-service] service=%s outcome=%s operation=%s status=%d ms=%d%s',
            $this->service(),
            $outcome,
            $operation,
            $status,
            (int) $ms,
            $reason !== null ? ' reason=' . $reason : '',
        ));
    }

    /** `?a=1&b=2` from a map, dropping nulls and empty strings. */
    protected static function query(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $clean === [] ? '' : '?' . http_build_query($clean);
    }
}
