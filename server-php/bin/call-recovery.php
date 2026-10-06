<?php

declare(strict_types=1);

/**
 * Call-state recovery and external-operation reconciliation.
 *
 *   php server-php/bin/call-recovery.php
 *
 * Run every few minutes. Two jobs, both about NOT KNOWING something:
 *
 *  1. Calls whose provider has gone quiet are marked stale, so the console
 *     shows "state unknown" instead of a call that has looked answered for
 *     forty minutes. They are NOT ended: the call may well still be up, and
 *     what has failed is our knowledge of it, not the call.
 *
 *  2. External writes whose outcome we never learned are settled by ASKING the
 *     owning product what it holds. That is the alternative to a blind retry,
 *     and the reason one callback does not end up with two diary entries.
 *
 *     Calendar (callback diary entries) follows its v1 contract, §12: ask by
 *     source_ref as the diary owner the entry was written for; adopt what it
 *     holds; when it holds nothing, send the SAME attempt again under the same
 *     Idempotency-Key; when it cannot be asked, keep asking later — an unknown
 *     outcome is never turned into "failed". Attempts Calendar refused before
 *     acting (deferred: key, scope, schema) are sent again unchanged, or
 *     dropped unsent when the callback has moved on. See Domain\CallbackDiary.
 *
 *     Appointments (an AI agent's booking) the same way: read back what it
 *     holds at that time for that service and number, adopt it, or send the
 *     same attempt again under the same key. See Domain\AppointmentsBooking.
 */

namespace Aicountly\Api;

use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Domain\AppointmentsBooking;
use Aicountly\Api\Domain\CallbackDiary;
use Aicountly\Api\Domain\CallStateMachine;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$stale = CallStateMachine::markStale();

$reconciled = 0;
$stillUnknown = 0;
$abandoned = 0;
$resent = 0;

foreach (ExternalOperations::dueForReconcile(50) as $operation) {
    $correlationId = (string) $operation['correlation_id'];
    $targetApp = (string) $operation['target_app'];

    if ($targetApp === 'calendar' || $targetApp === AppointmentsBooking::TARGET) {
        try {
            $outcome = $targetApp === 'calendar'
                ? CallbackDiary::recover($operation)
                : AppointmentsBooking::recover($operation);
        } catch (\Throwable $e) {
            // One bad row must not stop the rest; it is asked again later.
            error_log('[call-recovery] ' . $targetApp . ' operation ' . (int) $operation['operation_id'] . ': ' . $e->getMessage());
            try {
                ExternalOperations::retryLater((int) $operation['operation_id'], (int) $operation['attempts'] + 1);
            } catch (\Throwable) {
                // The database is the problem; the next run starts over.
            }
            $outcome = 'waiting';
        }
        match ($outcome) {
            'settled', 'superseded' => $reconciled++,
            'resent'                => $resent++,
            'abandoned'             => $abandoned++,
            default                 => $stillUnknown++,
        };
        continue;
    }

    $result = match ($targetApp) {
        'crm'      => (new CrmClient())->findTaskByCorrelation($correlationId),
        default    => null,
    };

    if ($result === null) {
        // No reconciliation read exists for this product yet. Saying so is
        // better than guessing at an outcome. Pay is the case that matters:
        // it has no lookup by Voice's correlation id, and a payment is the one
        // outcome that must never be inferred, so the operation is only ever
        // deferred and then handed to a person.
        error_log('[call-recovery] ' . $targetApp . ' operation ' . (int) $operation['operation_id']
            . ': ' . $targetApp . ' has no read Voice can reconcile against; outcome left unknown');
        ExternalOperations::deferReconcile(
            (int) $operation['operation_id'],
            (int) $operation['attempts'] + 1,
        );
        $stillUnknown++;
        continue;
    }

    if (!$result['ok']) {
        // Still cannot reach them. Back off; give up eventually and say a
        // person needs to look.
        $attempts = (int) $operation['attempts'] + 1;
        ExternalOperations::deferReconcile((int) $operation['operation_id'], $attempts);
        if ($attempts >= 6) {
            $abandoned++;
        } else {
            $stillUnknown++;
        }
        continue;
    }

    $body = $result['body'] ?? [];
    $records = $body['data'] ?? $body;
    $record = is_array($records) && array_is_list($records) ? ($records[0] ?? null) : $records;

    $externalRef = null;
    if (is_array($record)) {
        foreach (['event_uuid', 'task_uuid', 'payment_link_uuid', 'uuid', 'id'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && (string) $record[$key] !== '') {
                $externalRef = (string) $record[$key];
                break;
            }
        }
    }

    // The owner has answered. Either it has the record or it never made one —
    // both are certainties, and either way the uncertainty is over.
    ExternalOperations::reconcile(
        (int) $operation['operation_id'],
        $externalRef !== null,
        $externalRef,
    );
    $reconciled++;
}

echo sprintf(
    "calls_marked_stale=%d operations_reconciled=%d operations_resent=%d still_unknown=%d abandoned=%d\n",
    $stale,
    $reconciled,
    $resent,
    $stillUnknown,
    $abandoned,
);
