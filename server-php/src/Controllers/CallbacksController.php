<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallbackDiary;
use Aicountly\Api\Domain\CallbackService;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;

/** The callback queue. */
final class CallbacksController extends Controller
{
    public static function index(): never
    {
        [, $ctx] = self::enter('voice.callbacks.view');
        $params = Http::listParams(['due_at', 'created_at', 'priority'], 'due_at', 'asc');

        [$scope, $bindings] = $ctx->scopeClause();
        $where = [$scope];

        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $bindings['status'] = $status;
        }
        if (Http::param('overdue') === '1') {
            $where[] = "status IN ('open', 'scheduled') AND due_at < NOW()";
        }
        $agentId = Http::intParam('assigned_agent_id');
        if ($agentId !== null && $agentId > 0) {
            $where[] = 'assigned_agent_id = :agent';
            $bindings['agent'] = $agentId;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM voice_callbacks WHERE ' . $whereSql, $bindings) ?? 0);

        $rows = Db::all(
            'SELECT * FROM voice_callbacks WHERE ' . $whereSql . '
              ORDER BY ' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST
              LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            $bindings,
        );

        Http::list(
            array_map(static fn (array $r): array => CallbackService::present($r), $rows),
            $total,
            $params['limit'],
            $params['offset'],
            [
                // What a diary entry is, in words a screen can show as they are.
                'calendar_note' => self::CALENDAR_NOTE,
                'calendar' => [
                    // Configured, not proven: Integrations probes whether
                    // Calendar accepts Voice's key.
                    'enabled' => Features::enabled('CALENDAR') && CallbackDiary::schemaReady(),
                    'reason'  => Features::explain('CALENDAR')
                        ?? (CallbackDiary::schemaReady() ? null : CallbackDiary::SCHEMA_MISSING_REASON),
                ],
            ],
        );
    }

    /**
     * Exactly what a diary entry is and is not. Shown under the queue.
     */
    private const CALENDAR_NOTE = 'Due times are kept here, in Voice. A callback can also hold its time in the '
        . 'assigned agent’s Aicountly Calendar diary (or, with nobody assigned, the diary of the person who created it): '
        . 'a ' . CallbackDiary::SLOT_MINUTES . '-minute busy entry titled with the callback number only. Voice moves or '
        . 'cancels it when the callback is rescheduled, reassigned or cancelled here; it cannot be moved from Calendar. '
        . 'Voice sends no reminder.';

    /**
     * Would this time clash in the diary the callback would go to?
     *
     * Advisory, before a time is promised to a caller: only the diary write
     * itself decides. ?due_at=&assigned_agent_id=&callback_id=
     */
    public static function diaryCheck(): never
    {
        [$auth, $ctx] = self::enter('voice.callbacks.manage');

        $agentId = Http::intParam('assigned_agent_id');
        $callbackId = Http::intParam('callback_id');

        Http::data(CallbackDiary::check(
            $ctx,
            $auth,
            Http::param('due_at'),
            $agentId !== null && $agentId > 0 ? $agentId : null,
            $callbackId !== null && $callbackId > 0 ? $callbackId : null,
        ));
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.callbacks.manage');

        // Claimed before anything runs: a retry that arrives while this request
        // is still running must not create a second callback (and a second
        // diary entry for it).
        $key = Idempotency::fromRequest();
        $claim = Idempotency::claim($ctx, 'callback.create', $key);
        if ($claim['status'] === 'replay' && $claim['replay'] !== null) {
            Http::json($claim['replay']['status'], $claim['replay']['body']);
        }
        if ($claim['status'] === 'in_progress') {
            Http::error(409, 'request_in_progress', 'This callback is still being created. Try again in a moment.', ['retryable' => true]);
        }

        try {
            $result = CallbackService::create($ctx, $auth, Http::body());
        } catch (\Throwable $e) {
            Idempotency::release($ctx, 'callback.create', $key);
            throw $e;
        }
        if (!$result['ok']) {
            Idempotency::release($ctx, 'callback.create', $key);
            self::fail($result['code'], $result['message']);
        }

        $body = ['data' => $result['callback'], 'message' => $result['message']];
        Idempotency::remember($ctx, 'callback.create', $key, 201, $body);
        Http::json(201, $body);
    }

    public static function update(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.callbacks.manage');

        $result = CallbackService::update($ctx, $auth, self::id($id), Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        // The callback changed whatever happened to its diary entry; `message`
        // says what did, when it is not simply "done".
        Http::json(200, ['data' => $result['callback'] ?? [], 'message' => $result['message']]);
    }
}
