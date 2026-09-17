<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\ExternalOperations;
use Aicountly\Api\Features;
use Aicountly\Api\Support\Clock;

/**
 * The commitment ledger.
 *
 * ## An inferred promise is not a task
 *
 * "I'll send the revised quotation by Friday" appearing in a transcript is a
 * SUGGESTION with a transcript segment attached. It is not a task, it does not
 * appear in anybody's task list, and nothing has been created anywhere. It
 * becomes a CRM task when a person confirms it — and then CRM creates the task
 * and Voice stores the id.
 *
 * ## Two directions, kept apart
 *
 * What the CALLER asked for and what the BUSINESS agreed to do are different
 * things. "Can you send me a quote?" is a request; "I'll send it Friday" is a
 * commitment. A ledger that merges them generates follow-ups for things nobody
 * ever agreed to, and the person chasing them looks foolish.
 *
 * ## An unsettled date stays unsettled
 *
 * If the call did not establish which Friday, `due_at` is null and the ledger
 * says "needs clarification". It does not guess at the next one. A guessed
 * deadline is worse than none: somebody acts on it.
 */
final class CommitmentService
{
    /**
     * Confirm a suggestion, and create the task in the product that owns tasks.
     *
     * @return array{ok: bool, code: ?string, message: ?string, commitment: ?array<string, mixed>, operation: ?array<string, mixed>}
     */
    public static function confirm(Context $ctx, Auth $auth, int $commitmentId, array $input = []): array
    {
        $row = self::row($ctx, $commitmentId);
        if ($row === null) {
            return self::fail('not_found', 'That commitment is not in this company.');
        }
        if ((string) $row['status'] === 'confirmed' && $row['external_task_ref'] !== null) {
            return [
                'ok' => true, 'code' => null, 'message' => 'Already confirmed.',
                'commitment' => self::present($row), 'operation' => null,
            ];
        }

        // Accept the person's corrections to what the model suggested. They were
        // on the call; the model was reading a transcript.
        $description = trim((string) ($input['description'] ?? $row['description']));
        $dueAt = array_key_exists('due_at', $input)
            ? Clock::parse((string) $input['due_at'])
            : ($row['due_at'] === null ? null : Clock::parse((string) $row['due_at']));

        Db::update('voice_commitments', [
            'description'  => $description,
            'due_at'       => $dueAt === null ? null : Clock::sql($dueAt),
            'status'       => 'confirmed',
            'confirmed_at' => Clock::sql(Clock::now()),
            'confirmed_by' => $auth->uuid,
            'updated_at'   => Clock::sql(Clock::now()),
        ], ['commitment_id' => $commitmentId, 'cmp_id' => $ctx->cmpId]);

        if (!Features::enabled('CRM')) {
            // Confirmed here, not sent anywhere. Said plainly rather than
            // showing a task reference that does not exist.
            return [
                'ok'      => true,
                'code'    => 'crm_not_configured',
                'message' => 'Confirmed in Voice. Aicountly CRM is not connected, so no task was created there.',
                'commitment' => self::present(self::row($ctx, $commitmentId) ?? $row),
                'operation'  => null,
            ];
        }

        $opened = ExternalOperations::begin(
            $ctx,
            'crm',
            'create_task',
            ['description' => mb_substr($description, 0, 200), 'has_due_date' => $dueAt !== null],
            ['call_id' => (int) $row['call_id'], 'commitment_id' => $commitmentId],
            $auth->uuid,
        );

        $result = (new CrmClient())
            ->forActor($auth->uuid)
            ->withSession($auth->sesKey())
            ->createTask([
                'cmp_id'      => $ctx->cmpId,
                'bo_id'       => $ctx->boId,
                'title'       => $description,
                'due_at'      => $dueAt === null ? null : Clock::iso($dueAt),
                'source'      => 'voice',
                'source_ref'  => (string) $row['call_id'],
                'contact_ref' => self::contactRef($ctx, (int) $row['call_id']),
            ], $opened['correlation_id']);

        $externalRef = self::extractRef($result['body'] ?? null);
        $status = ExternalOperations::settle($opened['operation_id'], $result, $externalRef);

        if ($status === ExternalOperations::SUCCEEDED) {
            Db::update('voice_commitments', [
                'external_system'   => 'crm',
                'external_task_ref' => $externalRef,
                'updated_at'        => Clock::sql(Clock::now()),
            ], ['commitment_id' => $commitmentId, 'cmp_id' => $ctx->cmpId]);
        }

        Audit::record($ctx, $auth, Audit::EXTERNAL_WRITE, 'commitment', (string) $commitmentId, [
            'target' => 'crm', 'operation' => 'create_task', 'status' => $status,
        ]);

        $operation = ExternalOperations::find($ctx, $opened['operation_id']);

        return [
            'ok'      => $status === ExternalOperations::SUCCEEDED,
            'code'    => $status === ExternalOperations::SUCCEEDED ? null : $status,
            'message' => $operation === null ? null : ExternalOperations::present($operation)['message'],
            'commitment' => self::present(self::row($ctx, $commitmentId) ?? $row),
            'operation'  => $operation === null ? null : ExternalOperations::present($operation),
        ];
    }

    /** @return array{ok: bool, code: ?string, message: ?string} */
    public static function reject(Context $ctx, Auth $auth, int $commitmentId): array
    {
        $row = self::row($ctx, $commitmentId);
        if ($row === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That commitment is not in this company.'];
        }

        Db::update('voice_commitments', [
            'status'     => 'rejected',
            'updated_at' => Clock::sql(Clock::now()),
        ], ['commitment_id' => $commitmentId, 'cmp_id' => $ctx->cmpId]);

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, int $commitmentId): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $commitmentId;

        return Db::first('SELECT * FROM voice_commitments WHERE ' . $scope . ' AND commitment_id = :id', $params);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        $dueAt = $row['due_at'] ?? null;

        return [
            'commitment_id' => (int) $row['commitment_id'],
            'call_id'       => (int) $row['call_id'],
            'party'         => (string) $row['party'],
            'description'   => (string) $row['description'],
            'owner_agent_id' => $row['owner_agent_id'] === null ? null : (int) $row['owner_agent_id'],
            'owner_hint'    => $row['owner_hint'],
            'due_at'        => $dueAt,
            'due_text'      => $row['due_text'],
            // The honest label when the call never settled a date.
            'due_state'     => $dueAt === null ? 'needs_clarification' : 'set',
            'evidence'      => Db::jsonColumn($row['evidence'] ?? null),
            'confidence'    => $row['confidence'] === null ? null : (float) $row['confidence'],
            'status'        => (string) $row['status'],
            'external_system' => $row['external_system'],
            'external_task_ref' => $row['external_task_ref'],
            'created_at'    => $row['created_at'],
        ];
    }

    private static function contactRef(Context $ctx, int $callId): ?string
    {
        $ref = Db::scalar(
            'SELECT contact_ref FROM voice_calls WHERE call_id = :id AND cmp_id = :cmp',
            ['id' => $callId, 'cmp' => $ctx->cmpId],
        );

        return $ref === null ? null : (string) $ref;
    }

    /** @param array<string, mixed>|null $body */
    private static function extractRef(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        foreach ([$body, $body['data'] ?? []] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach (['task_uuid', 'task_id', 'uuid', 'id'] as $key) {
                if (isset($candidate[$key]) && is_scalar($candidate[$key]) && (string) $candidate[$key] !== '') {
                    return (string) $candidate[$key];
                }
            }
        }

        return null;
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'commitment' => null, 'operation' => null];
    }
}
