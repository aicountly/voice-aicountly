<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations for the Voice database.
 *
 *   php server-php/bin/migrate.php            apply everything not yet applied
 *   php server-php/bin/migrate.php --status   list what would run, change nothing
 *   php server-php/bin/migrate.php --dry-run  run every pending file, then roll all of it back
 *   php server-php/bin/migrate.php --baseline=NNN [--dry-run]
 *                                             a database whose schema was built without this
 *                                             runner: record every file numbered up to and
 *                                             including NNN as applied WITHOUT running it, then
 *                                             apply anything newer
 *
 * Each file runs inside its own transaction and is recorded by name, so a
 * half-applied migration cannot exist: either the file is in and recorded, or
 * neither. Files are applied in filename order, which is why they are numbered.
 *
 * --dry-run runs the pending files in that same order inside ONE transaction
 * and rolls it back at the end, so a file sees what the pending files before it
 * create — exactly as it will when they are applied. (Rolling back after each
 * file instead would fail 002 on a fresh database because 001's tables are
 * gone again.) Nothing is applied and nothing is recorded.
 *
 * --baseline is for a database that already has the schema (built by hand, or
 * by an older process) while voice_sql_migrations records nothing. It is
 * refused unless voice_sql_migrations is empty, and it is never run by a
 * deploy: only an operator knows how far the existing schema goes. Each file
 * it records gets the checksum of the file on disk, exactly as an apply would;
 * nothing checks that the file's objects exist. With --dry-run it says what it
 * would record and dry-runs the newer files on top, then rolls everything back.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Db.php';

Env::load(__DIR__ . '/../.env');

$usage    = 'Usage: php bin/migrate.php [--status | --dry-run] [--baseline=NNN]';
$status   = false;
$dryRun   = false;
$baseline = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--status') {
        $status = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--baseline=(\d{1,6})$/', $arg, $m) === 1) {
        $baseline = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo $usage . "\n";
        exit(0);
    } else {
        // An option that is not understood is refused, never ignored: a mistyped
        // "--baseline 013" must not turn into a plain apply.
        fwrite(STDERR, "Unknown option: {$arg}\n{$usage}\n");
        exit(1);
    }
}
if ($baseline !== null && $status) {
    fwrite(STDERR, "--baseline records migrations; --status changes nothing. Use --baseline=NNN --dry-run to see what it would record.\n");
    exit(1);
}

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migrations found in {$dir}\n");
    exit(1);
}

/** The number a migration file starts with (010 for 010_voice_phone_region.sql), or null. */
$numberOf = static function (string $path): ?int {
    return preg_match('/^(\d+)_/', basename($path), $m) === 1 ? (int) $m[1] : null;
};

if ($baseline !== null) {
    $numbers = array_filter(array_map($numberOf, $files), static fn (?int $n): bool => $n !== null);
    if (!in_array($baseline, $numbers, true)) {
        fwrite(STDERR, "--baseline={$baseline}: no migration file is numbered {$baseline}. Name the number of the last migration this database already has.\n");
        exit(1);
    }
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    $diagnosis = DatabaseDiagnosis::classify($e);
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Reason: {$diagnosis['reason']}. {$diagnosis['hint']}\n");
    fwrite(STDERR, "Run php bin/db-check.php to see where each connection setting comes from.\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS voice_sql_migrations (
        migration_id BIGSERIAL PRIMARY KEY,
        filename     TEXT        NOT NULL UNIQUE,
        checksum     TEXT        NOT NULL,
        applied_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
     )',
);

$applied = [];
foreach (Db::all('SELECT filename, checksum FROM voice_sql_migrations') as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

$pending = 0;
$drift   = 0;

if ($baseline !== null && $applied !== []) {
    fwrite(STDERR, 'Refusing --baseline: voice_sql_migrations already records ' . count($applied) . " migration(s).\n");
    fwrite(STDERR, "--baseline is only for a database whose migrations were never recorded. Run without it: php bin/migrate.php --status\n");
    exit(1);
}

// One transaction for the whole dry run, rolled back below: see the header.
if ($dryRun && !$status) {
    $pdo->beginTransaction();
}

if ($baseline !== null) {
    $recorded = [];
    try {
        if (!$dryRun) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare('INSERT INTO voice_sql_migrations (filename, checksum) VALUES (:f, :c)');
        foreach ($files as $path) {
            $number = $numberOf($path);
            if ($number === null || $number > $baseline) {
                continue;
            }
            $name = basename($path);
            $checksum = hash('sha256', (string) file_get_contents($path));
            $stmt->execute(['f' => $name, 'c' => $checksum]);
            $applied[$name] = $checksum;
            $recorded[] = $name;
        }
        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED   recording the baseline; nothing was recorded.\n         {$e->getMessage()}\n");
        exit(1);
    }
    foreach ($recorded as $name) {
        echo ($dryRun ? 'would record (baseline, not run)  ' : 'recorded (baseline, not run)  ') . $name . "\n";
    }
    echo ($dryRun ? 'Baseline: would record ' : 'Baseline: recorded ') . count($recorded)
        . " migration(s) as applied without running them.\n";
}

foreach ($files as $path) {
    $name = basename($path);
    $sql = (string) file_get_contents($path);
    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            // An applied migration that has since been edited. Reapplying it
            // would not undo what the old version did; say so and let a human
            // decide, rather than guessing.
            fwrite(STDERR, "CHANGED  {$name} — already applied, but the file has been edited since.\n");
            $drift++;
        } elseif ($status) {
            // "already", not "applied": in apply mode that same word means
            // "just applied it now". A deploy log is read by someone who
            // needs to know which of those two happened.
            echo "already  {$name}\n";
        }
        continue;
    }

    $pending++;
    if ($status) {
        echo "PENDING  {$name}\n";
        continue;
    }

    try {
        if ($dryRun) {
            $pdo->exec($sql);
            echo "ok (rolled back)  {$name}\n";
            continue;
        }
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO voice_sql_migrations (filename, checksum) VALUES (:f, :c)');
        $stmt->execute(['f' => $name, 'c' => $checksum]);
        $pdo->commit();
        echo "applied  {$name}\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED   {$name}\n         {$e->getMessage()}\n");
        exit(1);
    }
}

if ($dryRun && $pdo->inTransaction()) {
    $pdo->rollBack();
    if ($pending > 0) {
        echo "\nDry run: {$pending} pending migration(s) ran together and were rolled back. Nothing was applied.\n";
    }
}

if ($drift > 0) {
    exit(2);
}

if ($status) {
    echo $pending === 0 ? "\nUp to date.\n" : "\n{$pending} migration(s) pending.\n";
} elseif ($pending === 0) {
    echo "Up to date.\n";
}
