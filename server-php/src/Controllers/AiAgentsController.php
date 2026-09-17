<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AiAgentService;
use Aicountly\Api\Domain\FlowValidator;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\ProviderRegistry;

/** AI voice agents, their versions, rehearsals and publishing. */
final class AiAgentsController extends Controller
{
    public static function index(): never
    {
        [, $ctx] = self::enter('voice.ai.view');
        [$scope, $params] = $ctx->scopeClause();

        $rows = Db::all(
            'SELECT * FROM voice_ai_agents WHERE ' . $scope . ' ORDER BY updated_at DESC LIMIT 100',
            $params,
        );

        Http::data([
            'agents' => array_map(static fn (array $r): array => self::present($r), $rows),
            'tools'  => AiClient::TOOLS,
            'ai'     => AiClient::describeAvailability(),
            'node_types' => FlowValidator::NODE_TYPES,
            'provider_capabilities' => ProviderRegistry::forCompany($ctx)->capabilities(),
        ]);
    }

    public static function show(string $id): never
    {
        [, $ctx] = self::enter('voice.ai.view');
        $agentId = self::id($id);

        $row = AiAgentService::row($ctx, $agentId);
        if ($row === null) {
            Http::notFound('That agent is not in this company.');
        }

        $versions = Db::all(
            'SELECT * FROM voice_ai_agent_versions WHERE ai_agent_id = :id AND cmp_id = :cmp
              ORDER BY version_no DESC LIMIT 50',
            ['id' => $agentId, 'cmp' => $ctx->cmpId],
        );

        Http::data(self::present($row) + [
            'versions' => array_map(static fn (array $v): array => AiAgentService::presentVersion($v), $versions),
            'tests'    => array_map(
                static fn (array $row): array => [
                    'test_run_id'    => (int) $row['test_run_id'],
                    'scenario'       => (string) $row['scenario'],
                    'mode'           => (string) $row['mode'],
                    'status'         => (string) $row['status'],
                    'checks'         => Db::jsonColumn($row['checks'] ?? null),
                    'failure_reason' => $row['failure_reason'],
                    'started_at'     => $row['started_at'],
                    'finished_at'    => $row['finished_at'],
                ],
                Db::all(
                    'SELECT test_run_id, scenario, mode, status, checks, failure_reason, started_at, finished_at
                       FROM voice_ai_test_runs WHERE ai_agent_id = :id AND cmp_id = :cmp
                      ORDER BY started_at DESC LIMIT 25',
                    ['id' => $agentId, 'cmp' => $ctx->cmpId],
                ),
            ),
            'tools' => AiClient::TOOLS,
        ]);
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter('voice.ai.manage');
        $body = Http::body();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give the agent a name.', ['name' => 'A name is required.']);
        }

        $agentId = (int) Db::insert('voice_ai_agents', [
            'cmp_id'      => $ctx->cmpId,
            'bo_id'       => $ctx->boId,
            'name'        => $name,
            'role'        => trim((string) ($body['role'] ?? '')),
            'description' => trim((string) ($body['description'] ?? '')),
            'status'      => 'draft',
            'created_by'  => $auth->uuid,
        ], 'ai_agent_id');

        // A first draft version, so the agent has something to validate against
        // rather than existing as a name with no configuration.
        AiAgentService::saveDraft($ctx, $auth, $agentId, $body);

        Http::json(201, ['data' => self::present(AiAgentService::row($ctx, $agentId) ?? [])]);
    }

    /** Save the draft. A published version is never modified — this makes a new draft. */
    public static function saveVersion(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.ai.manage');

        $result = AiAgentService::saveDraft($ctx, $auth, self::id($id), Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data($result['version'] ?? []);
    }

    public static function test(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.ai.manage');

        $scenario = (string) (Http::body()['scenario'] ?? '');
        if ($scenario === '') {
            Http::validationFailed('Pick a scenario to rehearse.');
        }

        $result = AiAgentService::rehearse($ctx, $auth, self::id($id), $scenario);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::json(201, ['data' => $result['run']]);
    }

    public static function publish(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.ai.publish');

        $result = AiAgentService::publish($ctx, $auth, self::id($id));
        if (!$result['ok']) {
            // The validation detail goes back so the Studio screen can list
            // exactly what is outstanding rather than saying "not ready".
            self::fail($result['code'], $result['message'], ['validation' => $result['validation']]);
        }

        Http::data(['published' => true, 'validation' => $result['validation']]);
    }

    public static function rollback(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.ai.publish');

        $versionId = (int) (Http::body()['version_id'] ?? 0);
        if ($versionId <= 0) {
            Http::validationFailed('Which version should be restored?');
        }

        $result = AiAgentService::rollback($ctx, $auth, self::id($id), $versionId);
        if (!$result['ok']) {
            self::fail($result['code'], $result['message']);
        }

        Http::data(['message' => $result['message']]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'ai_agent_id' => (int) $row['ai_agent_id'],
            'name'        => (string) $row['name'],
            'role'        => (string) $row['role'],
            'description' => (string) $row['description'],
            'status'      => (string) $row['status'],
            'published_version_id' => $row['published_version_id'] === null ? null : (int) $row['published_version_id'],
            'draft_version_id'     => $row['draft_version_id'] === null ? null : (int) $row['draft_version_id'],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
