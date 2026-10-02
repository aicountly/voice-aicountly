<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Env;
use Aicountly\Api\Environment;

/**
 * Voice's client for the AI Pulse gateway, adapted from Pulse's reference client
 * (pulse-aicountly: docs/ai-gateway/PulseAiClient.php; contract docs/AI_GATEWAY.md).
 *
 * Every model call Voice makes goes through here, by way of AiClient. Pulse picks
 * the model (Console's binding), enforces budgets and reports usage per product and
 * feature; Voice holds no model key and calls no model provider. Voice keeps its own
 * prompts, its own checks on what comes back and its own messages — and there is no
 * fallback model: when Pulse cannot answer, a feature takes its rule-based path.
 *
 * Only what Voice uses is here: text generation and the status check. Realtime
 * secrets, embeddings and attachments are in the reference client for the day a
 * Voice feature needs them.
 *
 * Who is calling:
 *   - a signed-in user started the action: pass their ses_key (the Bearer this API
 *     received) and cmp_id / bo_id. Pulse checks the session and the company itself.
 *   - nobody with a session (another product's backend calling Voice with
 *     X-Service-Key): leave the ses_key null and PULSE_SERVICE_KEY is sent instead,
 *     falling back to CONSOLE_SERVICE_KEY (the same estate service key).
 *
 * Configuration (server-php/.env), both optional:
 *   PULSE_API_ORIGIN   https://pulse.aicountly.com (sandbox: https://pulse.gh.aicountly.com).
 *                      If unset, picked from the CONFIGURED environment (AIC_ENVIRONMENT,
 *                      else APP_ENV — see Environment): production → production Pulse,
 *                      sandbox and local → the sandbox. Never from the request's Host. With
 *                      no configured environment no call is made. A trailing /api is ignored.
 *   PULSE_SERVICE_KEY  only for calls with no user session.
 *
 * Never throws, never logs content. Every method returns
 *   ['ok' => bool, 'status' => int, 'code' => ?string, 'message' => ?string, 'retryable' => bool, 'data' => ?array]
 * where data is the gateway's `data` (id, text, json, stop_reason, model, tier, usage, …).
 */
final class PulseAiClient
{
    /** Sent as X-Pulse-Product. Budgets, Console usage and Pulse's audit are kept under it. */
    public const PRODUCT    = 'voice';
    public const PRODUCTION = 'https://pulse.aicountly.com';
    public const SANDBOX    = 'https://pulse.gh.aicountly.com';

    /** @var \Closure(string, string, list<string>, ?string, float, float): array{status: int, body: ?string, error: ?string} */
    private \Closure $transport;

    /**
     * @param float $timeoutSeconds Voice's AI answers somebody waiting on a screen — a short call
     *                              summary, not a long import — so this is far below the reference
     *                              client's 170 s. It still leaves room for Pulse's own checks and a
     *                              retry on another credential; past it the feature's rule-based
     *                              path answers.
     * @param callable|null $transport how a request goes out; curl unless a test passes a fake:
     *     fn (string $method, string $url, list<string> $headers, ?string $body, float $timeoutSeconds, float $connectTimeoutSeconds)
     *         => ['status' => int, 'body' => ?string, 'error' => null | 'timeout' | 'unreachable']
     */
    public function __construct(
        private ?string $origin = null,
        private float $timeoutSeconds = 30.0,
        ?callable $transport = null,
    ) {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : self::curl(...);
    }

    /**
     * One call to POST /api/ai/v1/generate. Pass the user's ses_key whenever a user is
     * behind the request; leave it null only when there is none (then the service key
     * is sent).
     *
     * @param array<string, mixed> $request gateway body: feature, system, input|messages,
     *                                      response_format, tier, max_output_tokens, cmp_id, bo_id, actor_uuid
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function generate(array $request, ?string $userSesKey = null, ?string $idempotencyKey = null): array
    {
        $caller = $this->callerHeader($userSesKey);
        if ($caller === null) {
            return self::result(false, 0, 'not_configured', 'No user session and no PULSE_SERVICE_KEY for an AI call with no user.', false);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Pulse-Product: ' . self::PRODUCT, $caller];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return self::result(false, 0, 'invalid_request', 'The request could not be encoded as JSON.', false);
        }
        if ($this->origin() === '') {
            return self::result(false, 0, 'not_configured', Environment::explainUnconfigured(), false);
        }

        $res = ($this->transport)('POST', $this->origin() . '/api/ai/v1/generate', $headers, $encoded, $this->timeoutSeconds, 5.0);
        if (($res['error'] ?? null) !== null) {
            // The outcome is unknown: retry only with the same Idempotency-Key.
            return self::result(false, 0, $res['error'] === 'timeout' ? 'timeout' : 'pulse_unreachable', 'AI Pulse did not answer.', true);
        }
        $status = (int) ($res['status'] ?? 0);
        $json = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($json)) {
            return self::result(false, $status, 'bad_response', "AI Pulse answered HTTP {$status} without JSON.", $status >= 500);
        }
        if ($status >= 200 && $status < 300 && ($json['status'] ?? 0) === 1) {
            $data = is_array($json['data'] ?? null) ? $json['data'] : null;
            if (($data['stop_reason'] ?? '') === 'refused') {
                // An answer, not a failure of the gateway: the model declined. Not retried elsewhere.
                return self::result(false, $status, 'refused', 'The model declined this request.', false, $data);
            }

            return self::result(true, $status, null, null, false, $data);
        }

        return self::result(
            false,
            $status,
            (string) ($json['code'] ?? 'error'),
            (string) ($json['message'] ?? "AI Pulse answered HTTP {$status}."),
            (bool) ($json['retryable'] ?? $status >= 500),
        );
    }

    /**
     * Plain text: the product's instructions in `system`, the data in `input`.
     *
     * @param array<string, mixed> $options any other gateway fields (tier, max_output_tokens, cmp_id, …)
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function text(string $feature, string $system, string $input, array $options = [], ?string $userSesKey = null): array
    {
        return $this->generate(['feature' => $feature, 'system' => $system, 'input' => $input] + $options, $userSesKey);
    }

    /**
     * Is AI available to Voice right now (GET /api/ai/v1/status)? For a status line.
     *
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function status(?string $userSesKey = null): array
    {
        $caller = $this->callerHeader($userSesKey);
        if ($caller === null) {
            return self::result(false, 0, 'not_configured', 'No user session and no PULSE_SERVICE_KEY to ask AI Pulse with.', false);
        }
        $headers = ['Accept: application/json', 'X-Pulse-Product: ' . self::PRODUCT, $caller];
        if ($this->origin() === '') {
            return self::result(false, 0, 'not_configured', Environment::explainUnconfigured(), false);
        }
        $res = ($this->transport)('GET', $this->origin() . '/api/ai/v1/status', $headers, null, 8.0, 3.0);
        if (($res['error'] ?? null) !== null) {
            return self::result(false, 0, 'pulse_unreachable', 'AI Pulse did not answer.', true);
        }
        $status = (int) ($res['status'] ?? 0);
        $json = json_decode((string) ($res['body'] ?? ''), true);

        return is_array($json) && ($json['status'] ?? 0) === 1
            ? self::result(true, $status, null, null, false, is_array($json['data'] ?? null) ? $json['data'] : null)
            : self::result(
                false,
                $status,
                (string) (is_array($json) ? ($json['code'] ?? 'error') : 'bad_response'),
                (string) (is_array($json) ? ($json['message'] ?? "AI Pulse answered HTTP {$status}.") : "AI Pulse answered HTTP {$status} without JSON."),
                true,
            );
    }

    /**
     * Pulse's origin for the environment this Voice API is configured as.
     *
     * PULSE_API_ORIGIN when set; otherwise production Pulse for a production
     * deployment and the sandbox for sandbox and local ones. Never from the
     * request's Host header (see Environment). '' when the environment is not
     * configured, and then no call is made.
     */
    public function origin(): string
    {
        $origin = $this->origin !== null && trim($this->origin) !== '' ? trim($this->origin) : Env::get('PULSE_API_ORIGIN');
        if ($origin === '') {
            $origin = match (Environment::siblingTier()) {
                'production' => self::PRODUCTION,
                'sandbox'    => self::SANDBOX,
                default      => '',
            };
        }
        if ($origin === '') {
            return '';
        }
        $origin = rtrim($origin, '/');

        return preg_replace('#/api$#i', '', $origin) ?? $origin;
    }

    /** The user's session when a user is behind the call, else the estate service key; null when neither. */
    private function callerHeader(?string $userSesKey): ?string
    {
        if ($userSesKey !== null && $userSesKey !== '') {
            return 'Authorization: Bearer ' . $userSesKey;
        }
        $key = trim(Env::get('PULSE_SERVICE_KEY')) ?: trim(Env::get('CONSOLE_SERVICE_KEY'));

        return $key !== '' ? 'X-Pulse-Service-Key: ' . $key : null;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: ?string, error: ?string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds, float $connectTimeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'unreachable'];
        }
        $options = [
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS        => (int) round($timeoutSeconds * 1000),
            CURLOPT_NOSIGNAL          => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['status' => 0, 'body' => null, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable'];
        }

        return ['status' => $status, 'body' => (string) $raw, 'error' => null];
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array} */
    private static function result(bool $ok, int $status, ?string $code, ?string $message, bool $retryable, ?array $data = null): array
    {
        return ['ok' => $ok, 'status' => $status, 'code' => $code, 'message' => $message, 'retryable' => $retryable, 'data' => $data];
    }
}
