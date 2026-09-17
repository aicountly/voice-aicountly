<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, CHECK THAT THIS SESSION MAY OPEN
 * THAT COMPANY, then check the permission — in that order, before a controller
 * touches a row. The tenant check is not something an individual endpoint
 * remembers to do: it happens here, once, for all of them.
 *
 * That matters more in this product than in most. The rows behind a company
 * scope here are recordings and transcripts of private conversations, and a
 * missing WHERE clause would not leak a list of invoices — it would play one
 * company's customer calls to another.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(?string $permission = null): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        if ($permission !== null) {
            Permissions::assert($ctx, $auth, $permission);
        }

        return [$auth, $ctx];
    }

    /** Authenticate without a company scope — the endpoints that take none. */
    protected static function enterUnscoped(): Auth
    {
        return Auth::require();
    }

    /**
     * Turn a domain service's failure into the right HTTP answer.
     *
     * The distinctions that matter to somebody on a call:
     *
     *   `outcome_unknown` is 202, not 500. The call may be ringing. The screen
     *   must say "we are not sure" and must NOT offer a Retry button that dials
     *   the customer a second time.
     *
     *   `provider_not_configured` is 503 with `retryable: false` — pressing
     *   again will not help; an administrator has to connect a provider.
     *
     *   `suppressed` and `outside_window` are 422: the request was understood
     *   and refused on policy, and the caller should not retry it.
     */
    protected static function fail(?string $code, ?string $message, array $detail = []): never
    {
        $message ??= 'That could not be done.';

        match ($code) {
            'outcome_unknown' => Http::json(202, [
                'data' => $detail,
                'message' => $message,
                'error' => ['code' => 'outcome_unknown', 'message' => $message, 'details' => $detail + ['retryable' => false]],
            ]),
            'provider_not_configured', 'storage_not_configured', 'crm_not_configured' =>
                Http::error(503, (string) $code, $message, $detail + ['retryable' => false]),
            'calendar_unavailable', 'owner_unavailable', 'context_unavailable' =>
                Http::error(503, (string) $code, $message, $detail + ['retryable' => true]),
            'capacity_reached', 'budget_exceeded' =>
                Http::error(429, (string) $code, $message, $detail + ['retryable' => true]),
            'capability_unsupported' =>
                Http::error(422, 'capability_unsupported', $message, $detail + ['retryable' => false]),
            'suppressed', 'outside_window', 'invalid_number', 'no_number',
            'no_caller_id', 'note_required', 'unknown_disposition', 'not_ready',
            'validation_failed' =>
                Http::validationFailed($message, $detail + ['reason' => (string) $code]),
            'illegal_transition', 'call_ended', 'conflict' =>
                Http::conflict($message, $detail + ['reason' => (string) $code, 'retryable' => false]),
            'not_found', 'no_draft', 'no_version', 'unknown_action' =>
                Http::notFound($message),
            'forbidden' => Http::forbidden($message),
            default => Http::error(422, $code ?? 'failed', $message, $detail),
        };
    }

    /**
     * A bounded, validated integer from the request.
     */
    protected static function id(string $raw): int
    {
        $id = (int) $raw;
        if ($id <= 0) {
            Http::notFound();
        }

        return $id;
    }
}
