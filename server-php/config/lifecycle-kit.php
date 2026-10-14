<?php

declare(strict_types=1);

/**
 * The company-lifecycle worker's kit (lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php).
 *
 * The same kit the API builds (src/CompanyLifecycle.php): this product's PDO — whose database name and
 * user come from Console when CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY are set, which the kit's own
 * --env reading would not know — its .env, the reviewed manifest beside this file, and the hook that
 * deletes the stored files of purged rows (src/LifecycleFiles.php).
 *
 * On a plain cron run (no operator command) it first retries the file deletions that are due, so a
 * file a purge could not delete is retried every few minutes with back-off until it is gone.
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$operatorCommand = array_filter(array_slice($GLOBALS['argv'] ?? [], 1), static fn (string $a): bool => preg_match('/^--(bootstrap|jobs|acks)(=|$)/', $a) !== 1);
if ($operatorCommand === []) {
    try {
        LifecycleFiles::sweep();
    } catch (\Throwable $e) {
        error_log('[voice-lifecycle] warning: file deletion retries skipped: ' . $e->getMessage());
    }
}

return CompanyLifecycle::build();
