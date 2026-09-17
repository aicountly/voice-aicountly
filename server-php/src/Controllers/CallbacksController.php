<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallbackService;
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
            // The reference only. A screen that needs the event's time reads it
            // from Calendar; Voice does not hold a copy.
            ['calendar_note' => 'calendar_event_ref points at Calendar. Times are read from Calendar, not stored here.'],
        );
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.callbacks.manage');

        $key = Idempotency::fromRequest();
        $replay = Idempotency::replay($ctx, 'callback.create', $key);
        if ($replay !== null) {
            Http::json($replay['status'], $replay['body']);
        }

        $result = CallbackService::create($ctx, $auth, Http::body());
        if (!$result['ok']) {
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

        Http::data($result['callback'] ?? []);
    }
}
