<?php

declare(strict_types=1);

/**
 * Retention sweep and housekeeping.
 *
 *   php server-php/bin/retention.php            delete what is due
 *   php server-php/bin/retention.php --dry-run  report what would go
 *
 * Run daily, off-peak. Bounded per pass, so it cannot run for an hour.
 * A legal hold outranks the clock; see Domain/RetentionService.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\RetentionService;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

if (in_array('--dry-run', array_slice($argv, 1), true)) {
    $due = Db::first(
        'SELECT
            COUNT(*) FILTER (WHERE deleted_at IS NULL AND legal_hold = FALSE
                             AND delete_after IS NOT NULL AND delete_after <= NOW()) AS recordings_due,
            COUNT(*) FILTER (WHERE legal_hold)                                       AS on_hold
           FROM voice_recordings',
    ) ?? [];

    echo sprintf(
        "recordings_due=%d on_hold=%d (nothing deleted)\n",
        (int) ($due['recordings_due'] ?? 0),
        (int) ($due['on_hold'] ?? 0),
    );
    exit(0);
}

$result = RetentionService::sweep();

echo sprintf(
    "recordings_deleted=%d transcripts_deleted=%d idempotency_pruned=%d events_pruned=%d\n",
    $result['recordings_deleted'],
    $result['transcripts_deleted'],
    $result['idempotency_pruned'],
    $result['events_pruned'],
);
