<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\PresenceService;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * Server-sent events for the live screens.
 *
 * ## Filtering happens HERE, on the server
 *
 * The stream is opened for one authenticated session, for one company, and the
 * query that produces every event carries that company's scope. There is no
 * broadcast-everything-and-filter-in-the-browser: a client that could see other
 * tenants' events is a client that can see other tenants' calls.
 *
 * ## Permissions are revalidated inside the loop
 *
 * A long-lived stream outlives a permission change. Access is rechecked
 * periodically, and a session that has lost its grant is closed rather than
 * quietly continuing to receive call events.
 *
 * ## Event IDs and recovery
 *
 * Every event carries a monotonic id, and the browser sends `Last-Event-ID` on
 * reconnect. A gap bigger than the buffer is answered with a `resync` event
 * telling the client to re-read authoritative state over REST, rather than by
 * replaying half a history and leaving the screen subtly wrong.
 *
 * ## Bounded
 *
 * The loop runs for a fixed span and then closes cleanly with a `reconnect`
 * event. A PHP-FPM worker held open forever is a worker not serving anything
 * else, and this is a product where every worker counts.
 */
final class EventsController extends Controller
{
    /** How long one connection is held before the client is asked to reconnect. */
    private const MAX_SECONDS = 50;

    /** How often the database is polled for changes. */
    private const POLL_SECONDS = 2;

    /** How often permissions are rechecked mid-stream. */
    private const REVALIDATE_EVERY = 10;

    public static function stream(): never
    {
        [$auth, $ctx] = self::enter('voice.dashboard.view');

        if (!Features::enabled('REALTIME')) {
            Http::error(503, 'realtime_disabled', 'Live updates are switched off for this deployment. The screens will poll instead.');
        }

        // Anything buffering this response makes it useless.
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $lastEventId = (int) (Http::header('Last-Event-ID') ?: Http::intParam('last_event_id', 0) ?? 0);
        $cursor = self::cursor($ctx);

        // A client returning after longer than we can reconstruct is told to
        // re-read rather than handed a partial history.
        if ($lastEventId > 0 && $lastEventId < $cursor['floor']) {
            self::send('resync', $cursor['head'], [
                'reason' => 'gap',
                'message' => 'Too much has changed to catch up incrementally. Reload the current state.',
            ]);
        }

        $startedAt = time();
        $ticks = 0;
        $seen = $lastEventId > 0 ? $lastEventId : $cursor['head'];

        self::send('ready', $cursor['head'], [
            'company' => $ctx->cmpId,
            'poll_seconds' => self::POLL_SECONDS,
        ]);

        while (time() - $startedAt < self::MAX_SECONDS) {
            if (connection_aborted()) {
                break;
            }

            $ticks++;

            // A stream opened an hour ago must not outlive the grant it was
            // opened under.
            if ($ticks % self::REVALIDATE_EVERY === 0) {
                \Aicountly\Api\Permissions::forget($ctx, $auth);
                if (!\Aicountly\Api\Permissions::allows($ctx, $auth, 'voice.dashboard.view')) {
                    self::send('forbidden', $seen, ['message' => 'Your access changed. Reload to continue.']);
                    break;
                }
            }

            foreach (self::changes($ctx, $seen) as $event) {
                self::send($event['type'], $event['id'], $event['data']);
                $seen = max($seen, (int) $event['id']);
            }

            // A comment line keeps proxies from closing an idle connection.
            echo ": keep-alive\n\n";
            flush();

            sleep(self::POLL_SECONDS);
        }

        self::send('reconnect', $seen, ['reason' => 'window_elapsed']);
        exit;
    }

    /**
     * What changed since the client last heard.
     *
     * Every query is scoped to the company in SQL.
     *
     * @return list<array{type: string, id: int, data: array<string, mixed>}>
     */
    private static function changes(Context $ctx, int $since): array
    {
        $out = [];
        [$scope, $params] = $ctx->scopeClause('c');
        $params['since'] = $since;

        foreach (Db::all(
            'SELECT c.call_id, c.call_uuid, c.state, c.state_stale_at, c.direction, c.queue_id,
                    c.owner_agent_id, c.handled_by, c.outcome, c.ended_at, c.updated_at,
                    EXTRACT(EPOCH FROM c.updated_at)::bigint AS event_id
               FROM voice_calls c
              WHERE ' . $scope . ' AND EXTRACT(EPOCH FROM c.updated_at)::bigint > :since
              ORDER BY c.updated_at LIMIT 100',
            $params,
        ) as $row) {
            $out[] = [
                'type' => 'call.updated',
                'id'   => (int) $row['event_id'],
                'data' => [
                    'call_id'   => (int) $row['call_id'],
                    'call_uuid' => (string) $row['call_uuid'],
                    'state'     => $row['state_stale_at'] !== null && $row['ended_at'] === null
                        ? 'unknown' : (string) $row['state'],
                    'state_is_stale' => $row['state_stale_at'] !== null && $row['ended_at'] === null,
                    'direction' => (string) $row['direction'],
                    'queue_id'  => $row['queue_id'] === null ? null : (int) $row['queue_id'],
                    'owner_agent_id' => $row['owner_agent_id'] === null ? null : (int) $row['owner_agent_id'],
                    'handled_by' => (string) $row['handled_by'],
                    'outcome'   => $row['outcome'],
                    'ended'     => $row['ended_at'] !== null,
                ],
            ];
        }

        [$agentScope, $agentParams] = $ctx->scopeClause('a');
        $agentParams['since'] = $since;

        foreach (Db::all(
            'SELECT a.agent_id, a.presence, a.presence_expires_at,
                    EXTRACT(EPOCH FROM a.updated_at)::bigint AS event_id
               FROM voice_agents a
              WHERE ' . $agentScope . ' AND EXTRACT(EPOCH FROM a.updated_at)::bigint > :since
              ORDER BY a.updated_at LIMIT 100',
            $agentParams,
        ) as $row) {
            $stale = $row['presence_expires_at'] !== null
                && (Clock::parse((string) $row['presence_expires_at'])?->getTimestamp() ?? 0) < time();

            $out[] = [
                'type' => 'agent.presence.updated',
                'id'   => (int) $row['event_id'],
                'data' => [
                    'agent_id' => (int) $row['agent_id'],
                    'presence' => $stale ? 'unknown' : (string) $row['presence'],
                    'presence_is_stale' => $stale,
                ],
            ];
        }

        // Queue depth is a derived figure, so it is sent as a snapshot rather
        // than as a change feed.
        if ($out !== []) {
            $out[] = [
                'type' => 'queue.updated',
                'id'   => time(),
                'data' => ['agents' => PresenceService::summary($ctx)],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $out;
    }

    /** @return array{head: int, floor: int} */
    private static function cursor(Context $ctx): array
    {
        $head = time();

        return [
            'head' => $head,
            // Anything older than five minutes is not reconstructable from the
            // updated_at cursor, so a client that far behind is told to resync.
            'floor' => $head - 300,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function send(string $event, int $id, array $data): void
    {
        echo 'id: ' . $id . "\n";
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
}
