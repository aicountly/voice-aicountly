<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * AI voice agents: versions, validation, rehearsal and publishing.
 *
 * ## Published versions are immutable
 *
 * Editing a published agent creates a DRAFT. The published row is never
 * rewritten, because a call in progress is pinned to the version it started on
 * (voice_calls.ai_version_id) and the record of what a caller was actually told
 * has to survive somebody clicking Save.
 *
 * Rolling back re-points the agent at an earlier version. New calls get it;
 * calls already running finish on the version they began with.
 *
 * ## Rehearsals are simulated and produce real results
 *
 * A rehearsal never places a call and never writes to another product. Its
 * scenarios are the hard ones — the caller changes their mind mid-sentence, the
 * owning product is down, the same action is attempted twice — and the checks
 * are evaluated against the agent's ACTUAL configuration. The two about
 * writes into another product (the owner is down; the same action twice) run
 * Voice's own booking code against a stand-in for Appointments, in a
 * transaction that is rolled back. A check a rehearsal cannot exercise says
 * "not verified", and a run holding one is not a pass. Every badge on the
 * Studio screen is rendered from a row this produced. There is no hardcoded
 * "passed" anywhere in this product.
 *
 * ## Publishing is gated server-side
 *
 * `publish()` re-runs validation and refuses while errors stand, whatever the
 * browser thinks the state of the button was.
 */
final class AiAgentService
{
    /**
     * Save the draft version, validating as it goes.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, code: ?string, message: ?string, version: ?array<string, mixed>}
     */
    public static function saveDraft(Context $ctx, Auth $auth, int $agentId, array $input): array
    {
        $agent = self::row($ctx, $agentId);
        if ($agent === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That agent is not in this company.', 'version' => null];
        }

        $base = $agent['draft_version_id'] !== null
            ? self::version($ctx, (int) $agent['draft_version_id'])
            : ($agent['published_version_id'] !== null ? self::version($ctx, (int) $agent['published_version_id']) : null);

        $persona = $input['persona'] ?? ($base === null ? [] : Db::jsonColumn($base['persona'] ?? null));
        $languages = $input['languages'] ?? ($base === null ? [] : Db::jsonColumn($base['languages'] ?? null));
        $voiceConfig = $input['voice_config'] ?? ($base === null ? [] : Db::jsonColumn($base['voice_config'] ?? null));
        $knowledge = $input['knowledge'] ?? ($base === null ? [] : Db::jsonColumn($base['knowledge'] ?? null));
        $permissions = $input['action_permissions'] ?? ($base === null ? [] : Db::jsonColumn($base['action_permissions'] ?? null));
        $guardrails = $input['guardrails'] ?? ($base === null ? [] : Db::jsonColumn($base['guardrails'] ?? null));
        $flowId = array_key_exists('flow_id', $input)
            ? ($input['flow_id'] === null ? null : (int) $input['flow_id'])
            : ($base === null ? null : ($base['flow_id'] === null ? null : (int) $base['flow_id']));

        // The server decides what each permission actually means. A stored
        // "allowed" on a consequential action becomes "confirm_with_caller".
        $permissions = self::normalisePermissions(is_array($permissions) ? $permissions : []);

        $validation = self::validate($ctx, $flowId, $permissions, is_array($languages) ? $languages : [], is_array($guardrails) ? $guardrails : []);

        // A published agent keeps its published version; the edit becomes a new
        // draft rather than a change to what is live.
        $reuseDraft = $agent['draft_version_id'] !== null
            && (string) ($base['status'] ?? '') === 'draft';

        if ($reuseDraft) {
            $versionId = (int) $agent['draft_version_id'];
            Db::update('voice_ai_agent_versions', [
                'persona'            => $persona,
                'languages'          => $languages,
                'voice_config'       => $voiceConfig,
                'knowledge'          => $knowledge,
                'action_permissions' => $permissions,
                'guardrails'         => $guardrails,
                'flow_id'            => $flowId,
                'validation'         => $validation,
            ], ['version_id' => $versionId, 'cmp_id' => $ctx->cmpId]);
        } else {
            $nextNo = (int) (Db::scalar(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM voice_ai_agent_versions WHERE ai_agent_id = :id',
                ['id' => $agentId],
            ) ?? 1);

            $versionId = (int) Db::insert('voice_ai_agent_versions', [
                'ai_agent_id'        => $agentId,
                'cmp_id'             => $ctx->cmpId,
                'version_no'         => $nextNo,
                'status'             => 'draft',
                'persona'            => $persona,
                'languages'          => $languages,
                'voice_config'       => $voiceConfig,
                'knowledge'          => $knowledge,
                'action_permissions' => $permissions,
                'guardrails'         => $guardrails,
                'flow_id'            => $flowId,
                'validation'         => $validation,
                'created_by'         => $auth->uuid,
            ], 'version_id');

            Db::update('voice_ai_agents', [
                'draft_version_id' => $versionId,
                'updated_at'       => Clock::sql(Clock::now()),
            ], ['ai_agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]);
        }

        if (isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '') {
            Db::update('voice_ai_agents', [
                'name'       => trim($input['name']),
                'role'       => trim((string) ($input['role'] ?? $agent['role'])),
                'description' => trim((string) ($input['description'] ?? $agent['description'])),
                'updated_at' => Clock::sql(Clock::now()),
            ], ['ai_agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]);
        }

        return [
            'ok'      => true,
            'code'    => null,
            'message' => null,
            'version' => self::presentVersion(self::version($ctx, $versionId) ?? []),
        ];
    }

    /**
     * Publish the draft.
     *
     * @return array{ok: bool, code: ?string, message: ?string, validation: array<string, mixed>}
     */
    public static function publish(Context $ctx, Auth $auth, int $agentId): array
    {
        $agent = self::row($ctx, $agentId);
        if ($agent === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That agent is not in this company.', 'validation' => []];
        }
        if ($agent['draft_version_id'] === null) {
            return ['ok' => false, 'code' => 'no_draft', 'message' => 'There is no draft to publish.', 'validation' => []];
        }

        $version = self::version($ctx, (int) $agent['draft_version_id']);
        if ($version === null) {
            return ['ok' => false, 'code' => 'no_draft', 'message' => 'There is no draft to publish.', 'validation' => []];
        }

        $permissions = Db::jsonColumn($version['action_permissions'] ?? null);

        // Re-validated here, now, against the CURRENT provider capabilities.
        // The browser's idea of whether the button was enabled is not evidence.
        $validation = self::validate(
            $ctx,
            $version['flow_id'] === null ? null : (int) $version['flow_id'],
            $permissions,
            Db::jsonColumn($version['languages'] ?? null),
            Db::jsonColumn($version['guardrails'] ?? null),
        );

        if (!$validation['valid']) {
            Db::update('voice_ai_agent_versions', ['validation' => $validation], [
                'version_id' => (int) $version['version_id'], 'cmp_id' => $ctx->cmpId,
            ]);

            return [
                'ok'      => false,
                'code'    => 'validation_failed',
                'message' => 'This agent cannot be published until the outstanding checks pass.',
                'validation' => $validation,
            ];
        }

        Db::transaction(static function () use ($ctx, $agentId, $version, $auth, $validation): void {
            Db::update('voice_ai_agent_versions', [
                'status'       => 'published',
                'validation'   => $validation,
                'published_at' => Clock::sql(Clock::now()),
                'published_by' => $auth->uuid,
            ], ['version_id' => (int) $version['version_id'], 'cmp_id' => $ctx->cmpId]);

            Db::update('voice_ai_agents', [
                'status'               => 'published',
                'published_version_id' => (int) $version['version_id'],
                // The draft is now the published version; the next edit makes a
                // new draft rather than modifying what is live.
                'draft_version_id'     => null,
                'updated_at'           => Clock::sql(Clock::now()),
            ], ['ai_agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]);
        });

        Audit::record($ctx, $auth, Audit::AI_PUBLISHED, 'ai_agent', (string) $agentId, [
            'version_no' => (int) $version['version_no'],
        ]);

        return ['ok' => true, 'code' => null, 'message' => null, 'validation' => $validation];
    }

    /**
     * Point the agent at an earlier published version.
     *
     * Calls in progress are NOT rewritten: they finish on the version they
     * started with, because that is what the caller was actually told.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function rollback(Context $ctx, Auth $auth, int $agentId, int $versionId): array
    {
        $version = self::version($ctx, $versionId);
        if ($version === null || (int) $version['ai_agent_id'] !== $agentId) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That version does not belong to this agent.'];
        }
        if ((string) $version['status'] !== 'published') {
            return ['ok' => false, 'code' => 'not_published', 'message' => 'Only a previously published version can be restored.'];
        }

        Db::update('voice_ai_agents', [
            'published_version_id' => $versionId,
            'status'               => 'published',
            'updated_at'           => Clock::sql(Clock::now()),
        ], ['ai_agent_id' => $agentId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::AI_ROLLED_BACK, 'ai_agent', (string) $agentId, [
            'version_no' => (int) $version['version_no'],
        ]);

        return [
            'ok' => true, 'code' => null,
            'message' => 'Version ' . $version['version_no'] . ' is live for new calls. Calls in progress finish on the version they started.',
        ];
    }

    /**
     * Run a rehearsal.
     *
     * SIMULATED. No call is placed, no external product is written to. The
     * checks are evaluated against the agent's real configuration, so the
     * result means something.
     *
     * @return array{ok: bool, code: ?string, message: ?string, run: ?array<string, mixed>}
     */
    public static function rehearse(Context $ctx, Auth $auth, int $agentId, string $scenario): array
    {
        $agent = self::row($ctx, $agentId);
        if ($agent === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That agent is not in this company.', 'run' => null];
        }

        $versionId = $agent['draft_version_id'] ?? $agent['published_version_id'];
        $version = $versionId === null ? null : self::version($ctx, (int) $versionId);
        if ($version === null) {
            return ['ok' => false, 'code' => 'no_version', 'message' => 'This agent has no configuration to rehearse.', 'run' => null];
        }

        $runId = (int) Db::insert('voice_ai_test_runs', [
            'ai_agent_id' => $agentId,
            'version_id'  => (int) $version['version_id'],
            'cmp_id'      => $ctx->cmpId,
            'scenario'    => $scenario,
            'mode'        => 'simulated',
            'status'      => 'running',
            'started_by'  => $auth->uuid,
        ], 'test_run_id');

        $checks = self::runScenario($ctx, $version, $scenario);

        // A check a rehearsal could not exercise is "not verified", and a run
        // holding one is not a pass: the badge says so instead of going green.
        $statuses = array_column($checks, 'status');
        $verdict = in_array('failed', $statuses, true)
            ? 'failed'
            : (in_array('not_verified', $statuses, true) ? 'not_verified' : 'passed');

        Db::update('voice_ai_test_runs', [
            'status'      => $verdict,
            'checks'      => $checks,
            'finished_at' => Clock::sql(Clock::now()),
        ], ['test_run_id' => $runId, 'cmp_id' => $ctx->cmpId]);

        if ($verdict === 'passed' && (string) $agent['status'] === 'draft') {
            Db::update('voice_ai_agents', ['status' => 'tested', 'updated_at' => Clock::sql(Clock::now())], [
                'ai_agent_id' => $agentId, 'cmp_id' => $ctx->cmpId,
            ]);
        }

        return [
            'ok' => true, 'code' => null, 'message' => null,
            'run' => [
                'test_run_id' => $runId,
                'scenario'    => $scenario,
                'mode'        => 'simulated',
                'simulated'   => true,
                'status'      => $verdict,
                'checks'      => $checks,
            ],
        ];
    }

    /**
     * The checks for one scenario.
     *
     * Each is a real assertion about the agent's configuration — not a
     * transcript somebody wrote to look convincing.
     *
     * @param array<string, mixed> $version
     * @return list<array<string, mixed>>
     */
    private static function runScenario(Context $ctx, array $version, string $scenario): array
    {
        $permissions = Db::jsonColumn($version['action_permissions'] ?? null);
        $guardrails = Db::jsonColumn($version['guardrails'] ?? null);
        $flowId = $version['flow_id'] === null ? null : (int) $version['flow_id'];
        $flow = $flowId === null ? null : self::flowDefinition($ctx, $flowId);

        $checks = [];
        $check = static function (string $key, string $label, bool $passed, string $detail) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $passed ? 'passed' : 'failed', 'detail' => $detail];
        };
        // For a check whose answer is not simply pass or fail: not_applicable,
        // or not_verified when a rehearsal cannot exercise it.
        $record = static function (string $key, string $label, array $outcome) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $outcome['status'], 'detail' => $outcome['detail']];
        };

        switch ($scenario) {
            case 'date_change_mid_sentence':
                $hasConfirm = $flow !== null && self::flowHasNodeType($flow, 'confirm');
                $check('confirm_before_book', 'Confirms the final details with the caller', $hasConfirm,
                    $hasConfirm
                        ? 'The flow confirms with the caller before acting.'
                        : 'No confirmation step, so a corrected date would not be read back.');
                $mode = AiClient::resolveActionMode('create_booking', $permissions);
                $check('booking_requires_confirmation', 'Booking requires caller confirmation',
                    in_array($mode, ['confirm_with_caller', 'handoff', 'denied'], true),
                    'Booking is set to "' . $mode . '".');
                break;

            case 'interruption':
                $bargeIn = (bool) ($guardrails['barge_in'] ?? false);
                $capabilities = ProviderRegistry::forCompany($ctx)->capabilities();
                $supported = (bool) ($capabilities['live_transcript'] ?? false);
                $check('barge_in_configured', 'Stops speaking when interrupted', $bargeIn,
                    $bargeIn ? 'Barge-in is enabled.' : 'Barge-in is off, so the agent talks over the caller.');
                $check('barge_in_supported', 'The gateway supports interruption', $supported,
                    $supported ? 'Supported by this connection.' : 'This connection does not report live transcription, which barge-in needs.');
                break;

            case 'unsupported_question':
                $maxClarifications = (int) ($guardrails['max_clarifications'] ?? 0);
                $check('bounded_clarifications', 'Stops asking after a bounded number of tries',
                    $maxClarifications > 0 && $maxClarifications <= 3,
                    $maxClarifications === 0
                        ? 'No clarification limit, so the agent could loop.'
                        : 'Limit is ' . $maxClarifications . '.');
                $hasHandover = $flow !== null && self::flowHasNodeType($flow, 'handover');
                $check('falls_back_to_human', 'Offers a person when it cannot answer', $hasHandover,
                    $hasHandover ? 'A handover step exists.' : 'No handover step, so the caller has nowhere to go.');
                break;

            case 'api_unavailable':
                $hasFailure = $flow !== null && self::flowHasFailureBranch($flow);
                $check('failure_branch', 'Has a path for when the other product is down', $hasFailure,
                    $hasFailure
                        ? 'An on_failure branch exists.'
                        : 'No failure branch, so an outage would leave the caller mid-flow.');
                $record('no_local_fallback', 'Does not record a booking locally on failure',
                    self::externalWriteCheck($ctx, $flow, 'api_unavailable'));
                break;

            case 'duplicate_tool_call':
                $record('idempotent_external_writes', 'The same action twice creates one record',
                    self::externalWriteCheck($ctx, $flow, 'duplicate_tool_call'));
                break;

            case 'asks_for_human':
                $mode = AiClient::resolveActionMode('transfer_to_human', $permissions);
                $allowed = $mode === 'allowed';
                $check('handover_permitted', 'May hand the call to a person', $allowed,
                    'Transfer to a person is set to "' . $mode . '".');
                $destination = $flow === null ? '' : self::flowHandoverDestination($flow);
                $check('handover_destination', 'Knows who to hand over to', $destination !== '',
                    $destination === '' ? 'No handover destination is assigned.' : 'Hands over to "' . $destination . '".');
                break;

            case 'declines_recording':
                $settings = \Aicountly\Api\Settings::forCompany($ctx->cmpId);
                $disclosed = (bool) $settings['recording_disclosure'];
                $check('recording_disclosed', 'Tells the caller the call is recorded', $disclosed,
                    $disclosed ? 'Recording disclosure is on.' : 'Recording disclosure is off for this company.');
                $record('refusal_recorded', 'A refusal is recorded and stops recording', [
                    'status' => 'not_verified',
                    'detail' => 'Not exercised by a rehearsal: only a live call can show recording stopping. On a call, consent_state records the refusal and recording_state moves to "refused".',
                ]);
                break;

            case 'conflicting_details':
                $hasConfirm = $flow !== null && self::flowHasNodeType($flow, 'confirm');
                $check('asks_rather_than_picks', 'Asks instead of choosing between conflicting answers', $hasConfirm,
                    $hasConfirm ? 'A confirmation step exists.' : 'No confirmation step, so a conflict would be resolved silently.');
                break;

            default:
                $check('known_scenario', 'Known rehearsal scenario', false, 'This scenario is not one this product can rehearse.');
        }

        // Every scenario also checks the flow itself is executable.
        if ($flow !== null) {
            $validation = FlowValidator::validate(
                $flow,
                ProviderRegistry::forCompany($ctx)->capabilities(),
                array_map('strval', $permissions),
            );
            $check('flow_valid', 'The call flow is executable', $validation['valid'],
                $validation['valid']
                    ? $validation['checked'] . ' steps checked, no errors.'
                    : count($validation['errors']) . ' validation ' . (count($validation['errors']) === 1 ? 'error' : 'errors') . ' outstanding.');
        } else {
            $check('flow_valid', 'The call flow is executable', false, 'This agent has no call flow attached.');
        }

        return $checks;
    }

    /**
     * What the flow's writes into other products do when the same action
     * comes twice, or when the owner stops answering — found out by running
     * Voice's own executor against a stand-in for the owner, not asserted.
     *
     * @param array<string, mixed>|null $flow
     * @return array{status: string, detail: string}
     */
    private static function externalWriteCheck(Context $ctx, ?array $flow, string $scenario): array
    {
        if ($flow === null) {
            return ['status' => 'failed', 'detail' => 'This agent has no call flow attached.'];
        }

        $writes = [];
        foreach ($flow['nodes'] ?? [] as $node) {
            $action = is_array($node) && ($node['type'] ?? '') === 'api_action' ? (string) ($node['action'] ?? '') : '';
            if (in_array($action, AiActions::WRITES, true)) {
                $writes[$action] = true;
            }
        }
        if ($writes === []) {
            return ['status' => 'not_applicable', 'detail' => $scenario === 'api_unavailable'
                ? 'This flow writes nothing into another product, so there is no write to fall back from.'
                : 'This flow writes nothing into another product, so there is nothing to repeat.'];
        }

        $cannot = array_values(array_filter(array_keys($writes), static fn (string $a): bool => AiActions::GATED[$a]['executor'] === null));
        if ($cannot !== []) {
            return ['status' => 'failed', 'detail' => implode(', ', array_map(
                static fn (string $a): string => '"' . (AiClient::TOOLS[$a]['label'] ?? $a) . '"',
                $cannot,
            )) . ' cannot run from a call in this deployment: nothing carries it out, so a caller reaching that step is handed to a person. Remove the step.'];
        }

        // Booking (Appointments) is the one write with an executor.
        $outcome = AppointmentsBooking::rehearse($ctx, $scenario);
        $off = AiActions::unavailableReason('create_booking');

        return $off === null ? $outcome : [
            'status' => $outcome['status'],
            'detail' => $outcome['detail'] . ' Appointments is not connected in this deployment, so this flow cannot be published yet.',
        ];
    }

    /**
     * Validate an agent version.
     *
     * @param array<string, string> $permissions
     * @param list<string>          $languages
     * @param array<string, mixed>  $guardrails
     * @return array<string, mixed>
     */
    public static function validate(Context $ctx, ?int $flowId, array $permissions, array $languages, array $guardrails): array
    {
        $errors = [];
        $warnings = [];

        $capabilities = ProviderRegistry::forCompany($ctx)->capabilities();

        if ($flowId === null) {
            $errors[] = ['code' => 'no_flow', 'node' => '', 'message' => 'This agent has no call flow.'];
        } else {
            $flow = self::flowDefinition($ctx, $flowId);
            if ($flow === null) {
                $errors[] = ['code' => 'flow_missing', 'node' => '', 'message' => 'The attached call flow no longer exists.'];
            } else {
                $result = FlowValidator::validate($flow, $capabilities, array_map('strval', $permissions));
                $errors = array_merge($errors, $result['errors']);
                $warnings = array_merge($warnings, $result['warnings']);
            }
        }

        // A language is only supported if the speech services actually serve it.
        // Claiming Punjabi because somebody typed it into a box is how a caller
        // is answered in a language the agent cannot hear.
        $supported = self::supportedLanguages();
        foreach ($languages as $language) {
            if (!in_array((string) $language, $supported, true)) {
                $warnings[] = [
                    'code' => 'language_unverified',
                    'node' => '',
                    'message' => '"' . $language . '" is not in the configured speech services’ language list, so it is not claimed as supported.',
                ];
            }
        }

        if ((int) ($guardrails['silence_timeout_seconds'] ?? 0) <= 0) {
            $errors[] = ['code' => 'no_silence_timeout', 'node' => '', 'message' => 'Set what happens when the caller says nothing.'];
        }
        if ((int) ($guardrails['max_clarifications'] ?? 0) <= 0) {
            $errors[] = ['code' => 'no_clarification_limit', 'node' => '', 'message' => 'Set how many times the agent may ask again before handing over.'];
        }

        return [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
            'at'       => Clock::iso(Clock::now()),
        ];
    }

    /**
     * Make every stored permission mean what this server will enforce.
     *
     * @param array<string, mixed> $permissions
     * @return array<string, string>
     */
    private static function normalisePermissions(array $permissions): array
    {
        $out = [];
        foreach (AiClient::TOOLS as $action => $tool) {
            $requested = (string) ($permissions[$action] ?? 'denied');
            $out[$action] = AiClient::resolveActionMode($action, [$action => $requested]);
        }

        return $out;
    }

    /** @return list<string> */
    private static function supportedLanguages(): array
    {
        $raw = \Aicountly\Api\Env::get('VOICE_SUPPORTED_LANGUAGES');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * The flow a published agent version follows, as it is now.
     *
     * @return array<string, mixed>|null
     */
    public static function publishedFlow(Context $ctx, int $versionId): ?array
    {
        $flowId = Db::scalar(
            'SELECT flow_id FROM voice_ai_agent_versions WHERE version_id = :id AND cmp_id = :cmp',
            ['id' => $versionId, 'cmp' => $ctx->cmpId],
        );

        return $flowId === null ? null : self::flowDefinition($ctx, (int) $flowId);
    }

    /** @return array<string, mixed>|null */
    private static function flowDefinition(Context $ctx, int $flowId): ?array
    {
        $row = Db::first(
            'SELECT v.definition FROM voice_call_flows f
               LEFT JOIN voice_call_flow_versions v
                      ON v.version_id = COALESCE(f.published_version_id, f.draft_version_id)
              WHERE f.flow_id = :id AND f.cmp_id = :cmp',
            ['id' => $flowId, 'cmp' => $ctx->cmpId],
        );

        if ($row === null || $row['definition'] === null) {
            return null;
        }

        return Db::jsonColumn($row['definition']);
    }

    /** @param array<string, mixed> $flow */
    private static function flowHasNodeType(array $flow, string $type): bool
    {
        foreach ($flow['nodes'] ?? [] as $node) {
            if (is_array($node) && ($node['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $flow */
    private static function flowHasFailureBranch(array $flow): bool
    {
        foreach ($flow['nodes'] ?? [] as $node) {
            if (is_array($node) && !empty($node['on_failure'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $flow */
    private static function flowHandoverDestination(array $flow): string
    {
        foreach ($flow['nodes'] ?? [] as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'handover') {
                return trim((string) ($node['destination'] ?? ''));
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, int $agentId): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $agentId;

        return Db::first('SELECT * FROM voice_ai_agents WHERE ' . $scope . ' AND ai_agent_id = :id', $params);
    }

    /** @return array<string, mixed>|null */
    public static function version(Context $ctx, int $versionId): ?array
    {
        return Db::first(
            'SELECT * FROM voice_ai_agent_versions WHERE version_id = :id AND cmp_id = :cmp',
            ['id' => $versionId, 'cmp' => $ctx->cmpId],
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function presentVersion(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'version_id'  => (int) $row['version_id'],
            'ai_agent_id' => (int) $row['ai_agent_id'],
            'version_no'  => (int) $row['version_no'],
            'status'      => (string) $row['status'],
            'persona'     => Db::jsonColumn($row['persona'] ?? null),
            'languages'   => Db::jsonColumn($row['languages'] ?? null),
            'voice_config' => Db::jsonColumn($row['voice_config'] ?? null),
            'knowledge'   => Db::jsonColumn($row['knowledge'] ?? null),
            'action_permissions' => Db::jsonColumn($row['action_permissions'] ?? null),
            'guardrails'  => Db::jsonColumn($row['guardrails'] ?? null),
            'flow_id'     => $row['flow_id'] === null ? null : (int) $row['flow_id'],
            'validation'  => Db::jsonColumn($row['validation'] ?? null),
            'published_at' => $row['published_at'],
            'created_at'  => $row['created_at'],
            // Says plainly that a published version cannot be edited.
            'immutable'   => (string) $row['status'] === 'published',
        ];
    }
}
