<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\FlowValidator;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Dashboard 3 — AI Voice Studio.
 *
 * For whoever is answerable for what the AI says to customers.
 *
 * ## The readiness figures are real
 *
 * "18 checks passed" is counted from voice_ai_test_runs — actual rehearsals
 * that actually ran. There is no hardcoded badge on this screen. An agent that
 * has never been tested shows as never tested, which is the useful answer.
 *
 * ## Publishing is gated on the checks, server-side
 *
 * The Publish button being disabled in React is a courtesy. AiAgentService
 * refuses the publish call outright while validation errors stand.
 */
final class StudioDashboard extends Dashboard
{
    public function id(): string
    {
        return 'studio';
    }

    public function build(): array
    {
        $agents = $this->agents();

        $published = 0;
        $needsReview = 0;
        $checksPassed = 0;
        $checksFailed = 0;

        foreach ($agents as $agent) {
            if ($agent['status'] === 'published') {
                $published++;
            }
            if ($agent['validation']['errors'] !== []) {
                $needsReview++;
            }
            $checksPassed += $agent['tests']['passed'];
            $checksFailed += $agent['tests']['failed'];
        }

        $metrics = [
            Metric::make('agents', 'Configured agents', count($agents), 'count', null, 'neutral', 'AI voice agents in this company.'),
            Metric::make('published', 'Published', $published, 'count', null, 'up_is_good', 'Versions live on calls now.'),
            Metric::make('checks_passed', 'Checks passed', $checksPassed, 'count', null, 'up_is_good',
                $checksPassed + $checksFailed === 0
                    ? 'No rehearsals have been run yet.'
                    : 'From ' . ($checksPassed + $checksFailed) . ' recorded rehearsal checks.'),
            Metric::make('needs_review', 'Needs review', $needsReview, 'count', null, 'down_is_good',
                $needsReview === 0 ? 'Nothing blocking publication.' : 'Publishing is blocked until these are fixed.'),
        ];

        return $this->envelope($metrics, [
            'agents' => $agents,
            'ai'     => AiClient::describeAvailability(),
            'scenarios' => $this->scenarioCatalogue(),
            'provider_capabilities' => ProviderRegistry::forCompany($this->ctx)->capabilities(),
            'node_types' => FlowValidator::NODE_TYPES,
        ], [
            'definitions' => [
                'checks_passed' => 'Individual checks inside recorded rehearsal runs. Not a fixed badge.',
                'needs_review'  => 'Agents whose draft version has validation errors.',
                'published'     => 'Agents with a published, immutable version. A live call keeps the version it started on.',
            ],
        ]);
    }

    /**
     * Every agent, with its real validation state and real test history.
     *
     * @return list<array<string, mixed>>
     */
    private function agents(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('g');

        $rows = Db::all(
            'SELECT g.*,
                    dv.version_no   AS draft_version_no,
                    dv.validation   AS draft_validation,
                    dv.action_permissions,
                    dv.languages,
                    dv.persona,
                    dv.flow_id,
                    pv.version_no   AS published_version_no,
                    pv.published_at
               FROM voice_ai_agents g
               LEFT JOIN voice_ai_agent_versions dv ON dv.version_id = g.draft_version_id
               LEFT JOIN voice_ai_agent_versions pv ON pv.version_id = g.published_version_id
              WHERE ' . $scope . '
              ORDER BY g.updated_at DESC',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $agentId = (int) $row['ai_agent_id'];
            $validation = Db::jsonColumn($row['draft_validation'] ?? null);

            $out[] = [
                'ai_agent_id' => $agentId,
                'name'        => (string) $row['name'],
                'role'        => (string) $row['role'],
                'description' => (string) $row['description'],
                'status'      => (string) $row['status'],
                'persona'     => Db::jsonColumn($row['persona'] ?? null),
                'languages'   => Db::jsonColumn($row['languages'] ?? null),
                'action_permissions' => Db::jsonColumn($row['action_permissions'] ?? null),
                'flow_id'     => $row['flow_id'] === null ? null : (int) $row['flow_id'],
                'draft_version_no'     => $row['draft_version_no'] === null ? null : (int) $row['draft_version_no'],
                'published_version_no' => $row['published_version_no'] === null ? null : (int) $row['published_version_no'],
                'published_at' => $row['published_at'],
                'validation'  => [
                    'valid'    => (bool) ($validation['valid'] ?? false),
                    'errors'   => $validation['errors'] ?? [],
                    'warnings' => $validation['warnings'] ?? [],
                ],
                'tests'       => $this->testSummary($agentId),
            ];
        }

        return $out;
    }

    /**
     * Rehearsal history for one agent.
     *
     * @return array<string, mixed>
     */
    private function testSummary(int $agentId): array
    {
        $row = Db::first(
            'SELECT
                COUNT(*)                                        AS runs,
                COUNT(*) FILTER (WHERE status = \'passed\')      AS runs_passed,
                COUNT(*) FILTER (WHERE status = \'failed\')      AS runs_failed,
                MAX(started_at)                                 AS last_run_at
               FROM voice_ai_test_runs WHERE ai_agent_id = :id AND cmp_id = :cmp',
            ['id' => $agentId, 'cmp' => $this->ctx->cmpId],
        ) ?? [];

        // Individual checks inside the most recent run, which is what the
        // readiness panel lists.
        $latest = Db::first(
            'SELECT checks, status, scenario, started_at FROM voice_ai_test_runs
              WHERE ai_agent_id = :id AND cmp_id = :cmp
              ORDER BY started_at DESC LIMIT 1',
            ['id' => $agentId, 'cmp' => $this->ctx->cmpId],
        );

        $passed = 0;
        $failed = 0;
        $checks = $latest === null ? [] : Db::jsonColumn($latest['checks'] ?? null);
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'passed') {
                $passed++;
            } elseif (($check['status'] ?? '') === 'failed') {
                $failed++;
            }
        }

        return [
            'runs'        => (int) ($row['runs'] ?? 0),
            'runs_passed' => (int) ($row['runs_passed'] ?? 0),
            'runs_failed' => (int) ($row['runs_failed'] ?? 0),
            'last_run_at' => $row['last_run_at'] ?? null,
            'passed'      => $passed,
            'failed'      => $failed,
            'latest'      => $latest === null ? null : [
                'scenario' => (string) $latest['scenario'],
                'status'   => (string) $latest['status'],
                'checks'   => $checks,
                'started_at' => $latest['started_at'],
            ],
            // The honest state for an agent nobody has rehearsed.
            'never_tested' => (int) ($row['runs'] ?? 0) === 0,
        ];
    }

    /**
     * The rehearsal scenarios, which are the hard cases rather than the happy
     * path — a demo that only tests "book me an appointment" tests nothing.
     *
     * @return list<array<string, string>>
     */
    private function scenarioCatalogue(): array
    {
        return [
            ['key' => 'date_change_mid_sentence', 'label' => 'Caller changes the date mid-sentence',
             'detail' => 'Checks the agent uses the corrected date, not the first one it heard.'],
            ['key' => 'interruption', 'label' => 'Caller interrupts',
             'detail' => 'Checks the agent stops talking and listens.'],
            ['key' => 'unsupported_question', 'label' => 'Question the agent cannot answer',
             'detail' => 'Checks it says so rather than inventing an answer.'],
            ['key' => 'api_unavailable', 'label' => 'The owning product is unavailable',
             'detail' => 'Checks it does not claim a booking it could not make.'],
            ['key' => 'duplicate_tool_call', 'label' => 'The same action is attempted twice',
             'detail' => 'Checks the second attempt does not create a second record.'],
            ['key' => 'asks_for_human', 'label' => 'Caller asks for a person',
             'detail' => 'Checks it hands over rather than persisting.'],
            ['key' => 'declines_recording', 'label' => 'Caller declines recording',
             'detail' => 'Checks recording stops and the refusal is recorded.'],
            ['key' => 'conflicting_details', 'label' => 'Caller gives conflicting details',
             'detail' => 'Checks it asks rather than picking one.'],
        ];
    }
}
