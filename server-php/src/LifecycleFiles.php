<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDO;

/**
 * The stored files of rows a company-lifecycle purge removed (docs/COMPANY_LIFECYCLE.md § Object storage).
 *
 * The kit (lib/company-lifecycle) archives and deletes ROWS in one transaction; it never touches files.
 * This is the kit's company / account hook (config/lifecycle-kit.php → CompanyLifecycle::build()): it
 * runs only AFTER the purge committed, reads the purged rows back out of that purge's archive schema
 * (exactly the rows that left — retained rows are never there, so their files stay), records every
 * file in voice_lifecycle_file_deletions and deletes it. A deletion that fails stays pending and is retried with back-off
 * (1, 2, 4 … minutes, at most 6 hours apart) by sweep(), which the cron worker's bootstrap runs on
 * every run, until MAX_ATTEMPTS, then marked failed and logged for ops. The table is the audit:
 * which purge, which file, when it went, how many attempts.
 *
 * The hook never throws: the rows are already gone, and a throw would make the kit re-run a purge that
 * now finds nothing. A file left behind is a pending row here, not a failed purge.
 */
final class LifecycleFiles
{
    public const TABLE = 'voice_lifecycle_file_deletions';

    public const MAX_ATTEMPTS = 12;

    /**
     * What the purged rows store: per archived `table`, `key` (the column naming the file), `storage`
     * (a column naming where it is when `column` is true, else a fixed value) and an optional `where`.
     *
     * @var list<array{table:string, key:string, storage:string, column?:bool, where?:string}>
     */
    public const SOURCES = [
        // Call and voicemail recordings (voice_voicemails points at these rows). A recording already removed by
        // the retention sweep has no storage_key and is not listed.
        ['table' => 'voice_recordings', 'key' => 'storage_key', 'storage' => 'recording'],
    ];

    /** Stores this product deletes from; anything else is recorded as `kept` (another product owns it). */
    public const OWNED = ['recording'];

    /** @var (callable(string, string): bool)|null test seam: fn(storage, key) => deleted-or-gone */
    private static $deleter = null;

    /** @return array{company: list<callable>, account: list<callable>} the kit's hooks */
    public static function hooks(): array
    {
        return [
            // Kit 1.1 passes a HookContext third: these change no row outside the engine (stored objects only,
            // recorded in their own table), so there is nothing to archiveRows().
            'company' => [static fn (int|string $cmpId, array $result, ?object $ctx = null): ?string => self::afterPurge('company', (string) $cmpId, $result)],
            'account' => [static fn (int|string $uuid, array $result, ?object $ctx = null): ?string => self::afterPurge('account', (string) $uuid, $result)],
        ];
    }

    /** One line for the ack detail, or null when the purge removed no row that stored a file. */
    public static function afterPurge(string $kind, string $target, array $result): ?string
    {
        $archive = is_string($result['archive'] ?? null) ? $result['archive'] : '';
        if ($archive === '') {
            return null;
        }
        try {
            $queued = self::collect($archive, $kind, $target);
            if ($queued['files'] === 0 && $queued['kept'] === 0) {
                return null;
            }
            $swept = self::sweep(500, $archive);
            $line = sprintf('%d stored file(s) of the purged rows deleted', $swept['deleted'] + $swept['missing']);
            if ($swept['pending'] > 0) {
                $line .= sprintf(', %d pending retry (%s)', $swept['pending'], self::TABLE);
            }
            if ($queued['kept'] > 0) {
                $line .= sprintf('; %d file(s) held by another AICOUNTLY product left to it', $queued['kept']);
            }

            return $line . '.';
        } catch (\Throwable $e) {
            self::log('error', "files of {$kind} {$target} (archive {$archive}) not recorded: " . $e->getMessage());

            return 'Stored files of the purged rows: not yet recorded (' . substr($e->getMessage(), 0, 160) . '); see ' . self::TABLE . ' and the worker log.';
        }
    }

    /**
     * Record the files the purged rows stored. Idempotent (one row per archive, table and key).
     *
     * @return array{files:int, kept:int}
     */
    public static function collect(string $archive, string $kind, string $target): array
    {
        if (preg_match('/^aic_lifecycle_archive_[cu][0-9]{1,19}_[0-9]{14}$/', $archive) !== 1) {
            throw new \InvalidArgumentException('not a lifecycle archive schema: ' . $archive);
        }
        $pdo = Db::connect();
        $files = 0;
        $kept = 0;
        foreach (self::SOURCES as $src) {
            $table = $src['table'];
            $exists = $pdo->query("SELECT to_regclass('\"{$archive}\".\"{$table}\"') IS NOT NULL")->fetchColumn();
            if (!$exists) {
                continue;
            }
            $storage = !empty($src['column']) ? '"' . $src['storage'] . '"::text' : $pdo->quote($src['storage']);
            $rows = $pdo->query('SELECT DISTINCT ' . $storage . ' AS storage, "' . $src['key'] . '"::text AS storage_key FROM "' . $archive . '"."' . $table . '" WHERE "' . $src['key'] . '" IS NOT NULL AND "' . $src['key'] . "\"::text <> ''" . (isset($src['where']) ? ' AND (' . $src['where'] . ')' : ''))->fetchAll(PDO::FETCH_ASSOC);
            $insert = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (target_cmp_id, target_uuid, archive_schema, source_table, storage, storage_key, status, finished_at)
                VALUES (:cmp, :uuid, :archive, :table, :storage, :key, :status, :finished) ON CONFLICT (archive_schema, source_table, storage_key) DO NOTHING');
            foreach ($rows as $row) {
                $owned = in_array((string) $row['storage'], self::OWNED, true);
                $insert->execute([
                    'cmp'      => $kind === 'company' ? (int) $target : null,
                    'uuid'     => $kind === 'company' ? null : $target,
                    'archive'  => $archive,
                    'table'    => $table,
                    'storage'  => (string) $row['storage'],
                    'key'      => (string) $row['storage_key'],
                    'status'   => $owned ? 'pending' : 'kept',
                    'finished' => $owned ? null : gmdate('Y-m-d H:i:s'),
                ]);
                $owned ? $files++ : $kept++;
            }
        }

        return ['files' => $files, 'kept' => $kept];
    }

    /**
     * Delete the pending files that are due (all of one archive's, when named). Never throws per file.
     *
     * @return array{deleted:int, missing:int, pending:int, failed:int}
     */
    public static function sweep(int $limit = 200, ?string $archive = null): array
    {
        $pdo = Db::connect();
        $out = ['deleted' => 0, 'missing' => 0, 'pending' => 0, 'failed' => 0];
        $sql = 'SELECT id, storage, storage_key, attempts FROM ' . self::TABLE . " WHERE status = 'pending'"
            . ($archive !== null ? ' AND archive_schema = :archive' : ' AND next_attempt_at <= now()') . ' ORDER BY id LIMIT ' . max(1, $limit);
        $due = $pdo->prepare($sql);
        $due->execute($archive !== null ? ['archive' => $archive] : []);
        $done = $pdo->prepare('UPDATE ' . self::TABLE . ' SET status = :status, attempts = attempts + 1, last_error = NULL, finished_at = now() WHERE id = :id');
        $retry = $pdo->prepare('UPDATE ' . self::TABLE . " SET status = :status, attempts = attempts + 1, last_error = :error,
            next_attempt_at = now() + make_interval(mins => :mins), finished_at = CASE WHEN :status2 = 'failed' THEN now() ELSE NULL END WHERE id = :id");
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $gone = self::delete((string) $row['storage'], (string) $row['storage_key']);
                $status = $gone === 'deleted' ? 'deleted' : 'missing';
                $done->execute(['status' => $status, 'id' => $row['id']]);
                $out[$status]++;
            } catch (\Throwable $e) {
                $attempt = (int) $row['attempts'] + 1;
                $status = $attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
                $retry->execute(['status' => $status, 'status2' => $status, 'error' => substr($e->getMessage(), 0, 500),
                    'mins' => min(360, 2 ** min(9, $attempt - 1)), 'id' => $row['id']]);
                self::log($status === 'failed' ? 'error' : 'warning', "file {$row['storage']}:{$row['storage_key']} not deleted (attempt {$attempt}): " . $e->getMessage());
                $out[$status]++;
            }
        }
        if ($archive !== null) {
            $left = $pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE status = 'pending' AND archive_schema = :archive");
            $left->execute(['archive' => $archive]);
            $out['pending'] = (int) $left->fetchColumn();
        }

        return $out;
    }

    /** Test seam: replace the deleter (null restores the real one). CLI only. */
    public static function useDeleterForTesting(?callable $deleter): void
    {
        if (PHP_SAPI === 'cli') {
            self::$deleter = $deleter;
        }
    }

    /** 'deleted' or 'missing' (already gone); throws when the file is still there. */
    private static function delete(string $storage, string $key): string
    {
        if (self::$deleter !== null) {
            return (self::$deleter)($storage, $key) ? 'deleted' : 'missing';
        }

        // RECORDING_STORAGE_PATH, confined to its root (as Domain\RetentionService::deleteObject()). With no local
        // path the recordings live behind RECORDING_STORAGE_URL, which this server cannot delete from: that is
        // an error to retry and, in the end, a failed row for ops — never a silent success.
        $root = Env::get('RECORDING_STORAGE_PATH');
        if ($root === '') {
            throw new \RuntimeException('recordings are held behind RECORDING_STORAGE_URL, not on this server: delete the object at the storage gateway');
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new \RuntimeException('RECORDING_STORAGE_PATH does not exist');
        }
        $path = realpath(rtrim($root, '/') . '/' . ltrim($key, '/'));
        if ($path === false) {
            return 'missing';
        }
        if (!str_starts_with($path, rtrim($rootReal, '/') . '/')) {
            throw new \RuntimeException('storage key escapes RECORDING_STORAGE_PATH: ' . $key);
        }
        if (!@unlink($path) && is_file($path)) {
            throw new \RuntimeException('could not delete ' . $key . ': ' . (error_get_last()['message'] ?? 'unlink failed'));
        }

        return 'deleted';
    }

    private static function log(string $level, string $message): void
    {
        error_log('[voice-lifecycle] ' . $level . ': ' . $message);
    }
}
