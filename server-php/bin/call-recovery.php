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
 *     owning product what it holds against our correlation id. That is the
 *     alternative to a blind retry, and the reason one caller does not end up
 *     with two appointments.
 */

namespace Aicountly\Api;

use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Domain\CallStateMachine;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$stale = CallStateMachine::markStale();

$reconciled = 0;
$stillUnknown = 0;
$abandoned = 0;

foreach (ExternalOperations::dueForReconcile(50) as $operation) {
    $correlationId = (string) $operation['correlation_id'];
    $targetApp = (string) $operation['target_app'];

    $result = match ($targetApp) {
        'calendar' => (new CalendarClient())->findByCorrelation($correlationId),
        'crm'      => (new CrmClient())->findTaskByCorrelation($correlationId),
        'pay'      => (new PayClient())->findByCorrelation($correlationId),
        default    => null,
    };

    if ($result === null) {
        // No reconciliation read exists for this product yet. Saying so is
        // better than guessing at an outcome.
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
    "calls_marked_stale=%d operations_reconciled=%d still_unknown=%d abandoned=%d\n",
    $stale,
    $reconciled,
    $stillUnknown,
    $abandoned,
);
