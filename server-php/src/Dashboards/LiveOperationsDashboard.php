<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\CallService;
use Aicountly\Api\Domain\PresenceService;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Dashboard 2 — Live Operations.
 *
 * For agents and supervisors: the queue, who is free, and the call in front of
 * you right now.
 *
 * ## State comes from the backend
 *
 * Every call here carries the state the provider last told us, and a flag when
 * that information has gone stale. The browser counts the seconds because that
 * feels responsive; it never decides whether a call is still up.
 *
 * ## Controls are capability-gated AND permission-gated
 *
 * `controls` below says which buttons this user may see on this connection.
 * Both have to agree: a supervisor with monitoring permission on a provider
 * that cannot monitor gets no button, and so does an agent on a provider that
 * can.
 */
final class LiveOperationsDashboard extends Dashboard
{
    public function id(): string
    {
        return 'live';
    }

    public function build(): array
    {
        $queues = $this->queues();
        $presence = PresenceService::summary($this->ctx);
        $active = $this->activeCalls();

        $waiting = 0;
        $longest = 0;
        foreach ($queues as $queue) {
            $waiting += (int) $queue['waiting'];
            $longest = max($longest, (int) $queue['longest_wait']);
        }

        $metrics = [
            Metric::make('active_calls', 'Active calls', count($active), 'count', null, 'neutral', 'Calls connected or ringing now.'),
            Metric::make('waiting', 'Waiting in queue', $waiting, 'count', null, 'down_is_good',
                $queues === [] ? 'No queues configured.' : 'Across ' . count($queues) . ' ' . (count($queues) === 1 ? 'queue' : 'queues') . '.'),
            Metric::make('available_agents', 'Available agents', $presence['available'], 'count', null, 'up_is_good',
                $presence['unknown'] > 0
                    ? $presence['unknown'] . ' whose availability we cannot confirm.'
                    : 'Of ' . $presence['total'] . ' configured.'),
            Metric::make('longest_wait', 'Longest wait', $longest, 'seconds', null, 'down_is_good',
                $longest === 0 ? 'Nobody waiting.' : 'In ' . $this->longestQueueName($queues) . '.'),
        ];

        return $this->envelope($metrics, [
            'queues'      => $queues,
            'agents'      => PresenceService::roster($this->ctx),
            'agent_summary' => $presence,
            'active_calls' => $active,
            'controls'    => $this->controls(),
            'wrap_up_seconds' => (int) $this->settings()['wrap_up_seconds'],
            'transcription' => $this->transcriptionState(),
        ], [
            'definitions' => [
                'active_calls' => 'Calls in ringing, answered, held or transferring, per the provider’s last event.',
                'waiting'      => 'Callers in a queue who have not been connected to anybody.',
                'unknown_presence' => 'An agent whose console stopped reporting. Not counted as available.',
            ],
        ]);
    }

    /**
     * Which call controls to render.
     *
     * The intersection of what the provider can do and what this user may do.
     * Where a control is absent, `reasons` says which of the two is missing, so
     * an administrator can tell "buy a better trunk" from "grant a permission".
     *
     * @return array<string, mixed>
     */
    private function controls(): array
    {
        $adapter = ProviderRegistry::forCompany($this->ctx);
        $capabilities = $adapter->capabilities();

        $matrix = [
            'mute'      => ['capability' => 'mute',              'permission' => 'voice.call.handle'],
            'hold'      => ['capability' => 'hold',              'permission' => 'voice.call.handle'],
            'dtmf'      => ['capability' => 'dtmf',              'permission' => 'voice.call.handle'],
            'transfer'  => ['capability' => 'blind_transfer',    'permission' => 'voice.call.transfer'],
            'attended_transfer' => ['capability' => 'attended_transfer', 'permission' => 'voice.call.transfer'],
            'hangup'    => ['capability' => 'end_call',          'permission' => 'voice.call.handle'],
            'record'    => ['capability' => 'recording',         'permission' => 'voice.call.handle'],
            'monitor'   => ['capability' => 'monitor_listen',    'permission' => 'voice.supervisor.monitor'],
        ];

        $available = [];
        $reasons = [];
        foreach ($matrix as $control => $requirement) {
            $hasCapability = (bool) ($capabilities[$requirement['capability']] ?? false);
            $hasPermission = Permissions::allows($this->ctx, $this->auth, $requirement['permission']);

            if ($hasCapability && $hasPermission) {
                $available[] = $control;
                continue;
            }

            $reasons[$control] = !$hasCapability
                ? 'provider_unsupported'
                : 'permission_required';
        }

        // Monitoring is surveillance of a colleague; the company can switch it
        // off entirely even for people who hold the permission.
        if (!$this->settings()['supervisor_monitoring']) {
            $available = array_values(array_filter($available, static fn (string $c) => $c !== 'monitor'));
            $reasons['monitor'] = 'disabled_by_policy';
        }

        return [
            'available' => $available,
            'reasons'   => $reasons,
            'provider'  => $adapter->label(),
            'browser_calling' => (bool) ($capabilities['browser_calling'] ?? false),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeCalls(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('c');

        $rows = Db::all(
            'SELECT c.*, q.name AS queue_name, a.extension, a.user_uuid AS agent_uuid
               FROM voice_calls c
               LEFT JOIN voice_queues q ON q.queue_id = c.queue_id
               LEFT JOIN voice_agents a ON a.agent_id = c.owner_agent_id
              WHERE ' . $scope . ' AND c.ended_at IS NULL
              ORDER BY c.initiated_at
              LIMIT 100',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $call = CallService::present($row);
            $call['queue_name'] = $row['queue_name'];
            $call['agent_extension'] = $row['extension'];
            // The portal owns the agent's name; this is the handle to resolve it.
            $call['agent_uuid'] = $row['agent_uuid'];
            $call['waiting_seconds'] = $row['state'] === 'queued'
                ? max(0, Clock::now()->getTimestamp() - (Clock::parse((string) $row['initiated_at'])?->getTimestamp() ?? 0))
                : 0;
            $out[] = $call;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function queues(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');

        return Db::all(
            'SELECT q.queue_id, q.name, q.kind, q.strategy, q.max_waiting,
                    COUNT(c.call_id) FILTER (WHERE c.ended_at IS NULL AND c.state = \'queued\')   AS waiting,
                    COUNT(c.call_id) FILTER (WHERE c.ended_at IS NULL AND c.state = \'answered\') AS in_call,
                    COALESCE(MAX(EXTRACT(EPOCH FROM (NOW() - c.initiated_at)))
                             FILTER (WHERE c.ended_at IS NULL AND c.state = \'queued\'), 0)::int  AS longest_wait,
                    (SELECT COUNT(*) FROM voice_queue_members m
                      WHERE m.queue_id = q.queue_id AND m.is_active = TRUE)                       AS members
               FROM voice_queues q
               LEFT JOIN voice_calls c ON c.queue_id = q.queue_id
              WHERE ' . $scope . ' AND q.is_active = TRUE
              GROUP BY q.queue_id, q.name, q.kind, q.strategy, q.max_waiting
              ORDER BY waiting DESC, q.name',
            $params,
        );
    }

    /** @param list<array<string, mixed>> $queues */
    private function longestQueueName(array $queues): string
    {
        $name = '—';
        $longest = -1;
        foreach ($queues as $queue) {
            if ((int) $queue['longest_wait'] > $longest) {
                $longest = (int) $queue['longest_wait'];
                $name = (string) $queue['name'];
            }
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function transcriptionState(): array
    {
        $enabled = \Aicountly\Api\Features::enabled('TRANSCRIPTION');

        return [
            'enabled' => $enabled,
            'reason'  => $enabled ? null : \Aicountly\Api\Features::explain('TRANSCRIPTION'),
            'note'    => 'Live transcription runs in the voice gateway. Partial text is marked as such until the recogniser commits to it.',
        ];
    }
}
