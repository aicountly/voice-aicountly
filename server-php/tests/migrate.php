<?php

declare(strict_types=1);

/**
 * bin/migrate.php against a database of its own: --dry-run on a fresh database, replaying every
 * migration on a database that already has the schema, and --baseline.
 *
 * Run with tests/run.sh. It creates a throwaway database next to the test database the .env names
 * (DB_NAME must end in _test), runs bin/migrate.php against it in a clean environment, as the deploy
 * does over SSH, and drops it again. Creating a database needs CREATEDB (the CI service's user is a
 * superuser); without it the cases are skipped and say so.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Env;
use PDO;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

$name = (string) Env::get('DB_NAME');
if (!str_ends_with($name, '_test') || Env::get('DB_USER') === '') {
    fwrite(STDERR, "tests/migrate.php needs the .env tests/run.sh writes (a DB_NAME ending in _test).\n");
    exit(1);
}
$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '5432');
$user = Env::get('DB_USER');
$pass = Env::get('DB_PASS');
$fresh = substr($name, 0, -strlen('_test')) . '_migrate_test';

$connect = static fn (string $db): PDO => new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "bin/migrate.php\n";

$admin = $connect($name);
try {
    $admin->exec('DROP DATABASE IF EXISTS ' . $fresh . ' WITH (FORCE)');
    $admin->exec('CREATE DATABASE ' . $fresh);
} catch (\PDOException $e) {
    echo "  skip  bin/migrate.php against a fresh database: cannot create {$fresh} ({$e->getMessage()})\n";
    exit(0);
}
$db = null;
register_shutdown_function(static function () use ($admin, $fresh, &$db): void {
    $db = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . $fresh . ' WITH (FORCE)');
});
$db = $connect($fresh);

/** @return array{0: int, 1: string} exit code, stdout + stderr */
$migrate = static function (string ...$args) use ($host, $port, $user, $pass, $fresh): array {
    $proc = proc_open(
        array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/migrate.php'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        // A clean environment, as over SSH on the server. Real environment variables beat the .env file's, so
        // these point the runner at the throwaway database and never at the test database the .env names.
        ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'local', 'DB_HOST' => $host, 'DB_PORT' => $port,
            'DB_NAME' => $fresh, 'DB_USER' => $user, 'DB_PASS' => $pass],
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($proc), $out . $err];
};
$recorded = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM voice_sql_migrations')->fetchColumn();
$hasTable = static fn (string $table): bool => (bool) $db->query("SELECT to_regclass('{$table}') IS NOT NULL")->fetchColumn();

$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
$names = array_map('basename', $files);
$upTo013 = array_values(array_filter($names, static fn (string $n): bool => (int) $n <= 13));
$after013 = array_values(array_diff($names, $upTo013));
T::ok(count($upTo013) > 0 && count($after013) > 0, 'there are migrations on both sides of 013 (' . count($upTo013) . ' + ' . count($after013) . ')');

T::group('--dry-run on a fresh database');
[$code, $out] = $migrate('--dry-run');
T::same(0, $code, 'exit 0: every pending file runs on top of the ones before it, as an apply would run them');
T::ok(str_contains($out, 'ok (rolled back)  002_voice_calls.sql') && str_contains($out, 'ok (rolled back)  ' . end($names)), '…002 sees 001\'s tables, and the last file is reached');
T::ok(str_contains($out, 'Nothing was applied.'), '…and says nothing was applied');
T::same([0, false], [$recorded(), $hasTable('voice_calls')], '…nothing recorded, no table left behind');

T::group('Options that are not understood are refused');
[$code, $out] = $migrate('--baseline', '013');
T::same(1, $code, '"--baseline 013" (no =) is refused, not run as a plain apply');
T::ok(str_contains($out, 'Unknown option: --baseline'), '…naming the option');
[$code, $out] = $migrate('--baseline=013', '--status');
T::same(1, $code, '--baseline with --status is refused');
[$code, $out] = $migrate('--baseline=999');
T::same(1, $code, '--baseline=999 (no such migration) is refused');
T::ok(str_contains($out, 'no migration file is numbered 999'), '…saying so');
T::same([0, false], [$recorded(), $hasTable('voice_calls')], 'none of them changed anything');

T::group('Apply, then replay on a database that already has the schema');
[$code, $out] = $migrate();
T::same(0, $code, 'apply: exit 0');
T::same(count($names), $recorded(), '…every file recorded');
[$code, $out] = $migrate('--status');
T::ok($code === 0 && str_contains($out, 'Up to date.'), '--status: up to date');

// A schema built without the runner: the tables are there, voice_sql_migrations is empty.
$db->exec('DELETE FROM voice_sql_migrations');
[$code, $out] = $migrate('--dry-run');
T::same(0, $code, 'every migration replays cleanly on a database that already has its schema (dry run)');
T::same(0, $recorded(), '…and records nothing');

T::group('--baseline');
[$code, $out] = $migrate('--baseline=013', '--dry-run');
T::same(0, $code, '--baseline=013 --dry-run: exit 0');
T::ok(str_contains($out, 'would record (baseline, not run)  013_voice_stream_tickets.sql')
    && !str_contains($out, 'would record (baseline, not run)  ' . $after013[0]), '…would record 001–013 and nothing newer');
T::ok(str_contains($out, 'ok (rolled back)  ' . $after013[0]) && !str_contains($out, 'ok (rolled back)  001_'), '…dry-runs only what is newer than 013');
T::same(0, $recorded(), '…and records nothing');

[$code, $out] = $migrate('--baseline=013');
T::same(0, $code, '--baseline=013: exit 0');
T::ok(str_contains($out, 'recorded (baseline, not run)  001_voice_foundation.sql') && str_contains($out, 'Baseline: recorded ' . count($upTo013) . ' migration(s)'), '…prints what it recorded');
T::ok(str_contains($out, 'applied  ' . end($after013)) && !str_contains($out, 'applied  001_'), '…then applies what is newer, and only that');
$rows = $db->query('SELECT filename, checksum FROM voice_sql_migrations ORDER BY filename')->fetchAll(PDO::FETCH_KEY_PAIR);
$expected = [];
foreach ($files as $path) {
    $expected[basename($path)] = hash('sha256', (string) file_get_contents($path));
}
T::same($expected, $rows, '…every file recorded with the checksum of the file on disk');
[$code, $out] = $migrate('--status');
T::ok($code === 0 && str_contains($out, 'Up to date.') && !str_contains($out, 'CHANGED'), '--status afterwards: up to date, no checksum drift');

[$code, $out] = $migrate('--baseline=013');
T::same(1, $code, '--baseline once anything is recorded: refused');
T::ok(str_contains($out, 'Refusing --baseline: voice_sql_migrations already records ' . count($names)), '…saying why');
T::same(count($names), $recorded(), '…and nothing changed');

exit(T::summary());
