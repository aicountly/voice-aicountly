<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\FlowValidator;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Call flows.
 *
 * The definition stored here is EXECUTABLE — it is what a call is walked
 * through, not a picture drawn beside the real configuration. The editor in the
 * browser renders this same graph.
 *
 * Publishing re-validates server-side and refuses while errors stand.
 */
final class CallFlowsController extends Controller
{
    public static function index(): never
    {
        [, $ctx] = self::enter('voice.call.view');
        [$scope, $params] = $ctx->scopeClause('f');

        $rows = Db::all(
            'SELECT f.*, pv.version_no AS published_version_no, dv.version_no AS draft_version_no,
                    dv.validation AS draft_validation
               FROM voice_call_flows f
               LEFT JOIN voice_call_flow_versions pv ON pv.version_id = f.published_version_id
               LEFT JOIN voice_call_flow_versions dv ON dv.version_id = f.draft_version_id
              WHERE ' . $scope . ' ORDER BY f.name',
            $params,
        );

        Http::data([
            'flows' => array_map(static fn (array $r): array => [
                'flow_id'     => (int) $r['flow_id'],
                'name'        => (string) $r['name'],
                'description' => (string) $r['description'],
                'published_version_no' => $r['published_version_no'] === null ? null : (int) $r['published_version_no'],
                'draft_version_no'     => $r['draft_version_no'] === null ? null : (int) $r['draft_version_no'],
                'validation'  => Db::jsonColumn($r['draft_validation'] ?? null),
                'is_active'   => (bool) $r['is_active'],
            ], $rows),
            'node_types' => FlowValidator::NODE_TYPES,
            'capabilities' => ProviderRegistry::forCompany($ctx)->capabilities(),
        ]);
    }

    public static function show(string $id): never
    {
        [, $ctx] = self::enter('voice.call.view');
        $flowId = self::id($id);

        $flow = Db::first(
            'SELECT * FROM voice_call_flows WHERE flow_id = :id AND cmp_id = :cmp',
            ['id' => $flowId, 'cmp' => $ctx->cmpId],
        );
        if ($flow === null) {
            Http::notFound('That call flow is not in this company.');
        }

        Http::data([
            'flow_id'     => $flowId,
            'name'        => (string) $flow['name'],
            'description' => (string) $flow['description'],
            // Decoded, not passed through: PDO hands a jsonb column back as a
            // string, and a string where the editor expects a graph is a blank
            // screen rather than an error.
            'versions'    => array_map(
                static fn (array $row): array => [
                    'version_id'   => (int) $row['version_id'],
                    'version_no'   => (int) $row['version_no'],
                    'status'       => (string) $row['status'],
                    'definition'   => Db::jsonColumn($row['definition'] ?? null),
                    'validation'   => Db::jsonColumn($row['validation'] ?? null),
                    'published_at' => $row['published_at'],
                    'created_at'   => $row['created_at'],
                ],
                Db::all(
                    'SELECT version_id, version_no, status, definition, validation, published_at, created_at
                       FROM voice_call_flow_versions WHERE flow_id = :id AND cmp_id = :cmp
                      ORDER BY version_no DESC LIMIT 25',
                    ['id' => $flowId, 'cmp' => $ctx->cmpId],
                ),
            ),
            'node_types'   => FlowValidator::NODE_TYPES,
            'capabilities' => ProviderRegistry::forCompany($ctx)->capabilities(),
        ]);
    }

    public static function save(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.flows.manage');
        $flowId = self::id($id);

        $flow = Db::first(
            'SELECT * FROM voice_call_flows WHERE flow_id = :id AND cmp_id = :cmp',
            ['id' => $flowId, 'cmp' => $ctx->cmpId],
        );
        if ($flow === null) {
            Http::notFound('That call flow is not in this company.');
        }

        $definition = Http::body()['definition'] ?? null;
        if (!is_array($definition)) {
            Http::validationFailed('The flow definition is missing.');
        }

        $validation = FlowValidator::validate($definition, ProviderRegistry::forCompany($ctx)->capabilities());

        $draftId = $flow['draft_version_id'] === null ? null : (int) $flow['draft_version_id'];
        $isDraft = $draftId !== null && (string) (Db::scalar(
            'SELECT status FROM voice_call_flow_versions WHERE version_id = :id',
            ['id' => $draftId],
        ) ?? '') === 'draft';

        if ($isDraft) {
            Db::update('voice_call_flow_versions', [
                'definition' => $definition,
                'validation' => $validation,
            ], ['version_id' => $draftId, 'cmp_id' => $ctx->cmpId]);
            $versionId = $draftId;
        } else {
            $nextNo = (int) (Db::scalar(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM voice_call_flow_versions WHERE flow_id = :id',
                ['id' => $flowId],
            ) ?? 1);

            $versionId = (int) Db::insert('voice_call_flow_versions', [
                'flow_id'    => $flowId,
                'cmp_id'     => $ctx->cmpId,
                'version_no' => $nextNo,
                'status'     => 'draft',
                'definition' => $definition,
                'validation' => $validation,
                'created_by' => $auth->uuid,
            ], 'version_id');

            Db::update('voice_call_flows', [
                'draft_version_id' => $versionId,
                'updated_at'       => Clock::sql(Clock::now()),
            ], ['flow_id' => $flowId, 'cmp_id' => $ctx->cmpId]);
        }

        Http::data(['version_id' => $versionId, 'validation' => $validation]);
    }

    public static function publish(string $id): never
    {
        [$auth, $ctx] = self::enter('voice.flows.manage');
        $flowId = self::id($id);

        $flow = Db::first(
            'SELECT * FROM voice_call_flows WHERE flow_id = :id AND cmp_id = :cmp',
            ['id' => $flowId, 'cmp' => $ctx->cmpId],
        );
        if ($flow === null || $flow['draft_version_id'] === null) {
            Http::notFound('There is no draft to publish.');
        }

        $version = Db::first(
            'SELECT * FROM voice_call_flow_versions WHERE version_id = :id AND cmp_id = :cmp',
            ['id' => (int) $flow['draft_version_id'], 'cmp' => $ctx->cmpId],
        );
        if ($version === null) {
            Http::notFound('There is no draft to publish.');
        }

        // Re-validated here, against the CURRENT provider capabilities.
        $validation = FlowValidator::validate(
            Db::jsonColumn($version['definition'] ?? null),
            ProviderRegistry::forCompany($ctx)->capabilities(),
        );

        if (!$validation['valid']) {
            self::fail('validation_failed', 'This flow cannot be published while it has errors.', ['validation' => $validation]);
        }

        Db::transaction(static function () use ($ctx, $flowId, $version, $auth, $validation): void {
            Db::update('voice_call_flow_versions', [
                'status'       => 'published',
                'validation'   => $validation,
                'published_at' => Clock::sql(Clock::now()),
                'published_by' => $auth->uuid,
            ], ['version_id' => (int) $version['version_id'], 'cmp_id' => $ctx->cmpId]);

            Db::update('voice_call_flows', [
                'published_version_id' => (int) $version['version_id'],
                'draft_version_id'     => null,
                'updated_at'           => Clock::sql(Clock::now()),
            ], ['flow_id' => $flowId, 'cmp_id' => $ctx->cmpId]);
        });

        Http::data(['published' => true, 'validation' => $validation]);
    }

    /** Check a definition without saving it — what the editor calls as you type. */
    public static function validate(): never
    {
        [, $ctx] = self::enter('voice.call.view');

        $definition = Http::body()['definition'] ?? null;
        if (!is_array($definition)) {
            Http::validationFailed('The flow definition is missing.');
        }

        Http::data(FlowValidator::validate($definition, ProviderRegistry::forCompany($ctx)->capabilities()));
    }

    public static function create(): never
    {
        [, $ctx] = self::enter('voice.flows.manage');

        $name = trim((string) (Http::body()['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give the flow a name.');
        }

        $flowId = (int) Db::insert('voice_call_flows', [
            'cmp_id'      => $ctx->cmpId,
            'bo_id'       => $ctx->boId,
            'name'        => $name,
            'description' => trim((string) (Http::body()['description'] ?? '')),
        ], 'flow_id');

        Http::json(201, ['data' => ['flow_id' => $flowId, 'name' => $name]]);
    }
}
