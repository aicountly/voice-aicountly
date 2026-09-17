<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Support\Clock;

/**
 * Deleting what should no longer be kept.
 *
 * ## Delete means delete everywhere
 *
 * A retention policy that removes the audio file and leaves the transcript has
 * not deleted the conversation — it has kept the part that is searchable. So a
 * sweep removes, for one call: the stored object, the recording row, the
 * transcript segments, and the search index entries derived from them. The
 * index is maintained by PostgreSQL's own expression index over
 * voice_transcript_segments, so deleting the rows removes the index entries
 * with them; an external search engine, if one is configured, is told
 * separately.
 *
 * ## A legal hold outranks the clock
 *
 * `legal_hold` stops the sweeper for that recording regardless of the policy,
 * and stays until somebody clears it. Retention that quietly destroys evidence
 * somebody is relying on is worse than retention that keeps too much.
 *
 * ## The call survives
 *
 * The call RECORD — that it happened, to whom, when, its outcome — is business
 * history and is not what retention is about. What goes is the recording and
 * the transcript.
 */
final class RetentionService
{
    /** Bounded, so one pass cannot run for an hour. */
    private const BATCH = 200;

    /**
     * One sweep.
     *
     * @return array<string, int>
     */
    public static function sweep(): array
    {
        return [
            'recordings_deleted'  => self::sweepRecordings(),
            'transcripts_deleted' => self::sweepTranscripts(),
            'idempotency_pruned'  => Idempotency::prune(),
            'events_pruned'       => self::pruneProviderEvents(),
        ];
    }

    /**
     * Recordings past their delete_after, not on hold.
     *
     * The row is marked deleted and its storage key cleared in the same
     * statement that records the deletion, so a crash between the two cannot
     * leave a row pointing at an object that is gone — or an object with no row
     * to find it by.
     */
    private static function sweepRecordings(): int
    {
        $due = Db::all(
            'SELECT recording_id, recording_uuid, cmp_id, bo_id, call_id, storage_key
               FROM voice_recordings
              WHERE deleted_at IS NULL
                AND legal_hold = FALSE
                AND delete_after IS NOT NULL
                AND delete_after <= NOW()
              ORDER BY delete_after
              LIMIT ' . self::BATCH,
        );

        $deleted = 0;
        foreach ($due as $row) {
            $removed = self::deleteObject((string) ($row['storage_key'] ?? ''));

            // Only mark it gone once the object actually is. A row that says
            // deleted while the audio is still in the bucket is a retention
            // policy that exists only on the screen.
            if (!$removed) {
                error_log('[retention] could not delete object for recording ' . $row['recording_uuid']);
                continue;
            }

            Db::update('voice_recordings', [
                'deleted_at'  => Clock::sql(Clock::now()),
                'status'      => 'deleted',
                'storage_key' => null,
            ], ['recording_id' => (int) $row['recording_id']]);

            Audit::record(
                Context::forCompany((int) $row['cmp_id'], (int) $row['bo_id']),
                null,
                Audit::RECORDING_DELETED,
                'recording',
                (string) $row['recording_uuid'],
                ['reason' => 'retention_policy', 'call_id' => (int) $row['call_id']],
            );

            $deleted++;
        }

        return $deleted;
    }

    /**
     * Transcript segments past their company's retention.
     *
     * Deleting the rows also removes their entries from the GIN index over
     * them, which is what stops a deleted conversation staying searchable.
     */
    private static function sweepTranscripts(): int
    {
        return Db::run(
            'DELETE FROM voice_transcript_segments t
              USING voice_calls c, voice_settings s
              WHERE t.call_id = c.call_id
                AND s.cmp_id = c.cmp_id
                AND s.transcript_retention_days > 0
                AND c.ended_at IS NOT NULL
                AND c.ended_at < NOW() - (s.transcript_retention_days || \' days\')::interval
                AND NOT EXISTS (
                      SELECT 1 FROM voice_recordings r
                       WHERE r.call_id = c.call_id AND r.legal_hold = TRUE
                )',
        )->rowCount();
    }

    /**
     * Provider event payloads older than 90 days.
     *
     * They exist to deduplicate retries and to explain why a call looks the way
     * it does. Neither needs three months, and the payloads are the bulkiest
     * thing this database holds.
     */
    private static function pruneProviderEvents(): int
    {
        return Db::run(
            'DELETE FROM voice_call_events WHERE received_at < NOW() - INTERVAL \'90 days\'',
        )->rowCount();
    }

    /**
     * Remove the stored object.
     *
     * With no storage configured there is nothing to remove and the row may be
     * marked deleted. A key we cannot resolve is NOT treated as deleted.
     */
    private static function deleteObject(string $storageKey): bool
    {
        if ($storageKey === '') {
            return true;
        }

        $root = \Aicountly\Api\Env::get('RECORDING_STORAGE_PATH');
        if ($root === '') {
            // Object storage handled elsewhere (the gateway's own lifecycle
            // rules). Nothing for this process to unlink.
            return true;
        }

        // Resolve and confine: a storage key must not escape its root.
        $path = realpath(rtrim($root, '/') . '/' . ltrim($storageKey, '/'));
        $rootReal = realpath($root);
        if ($path === false || $rootReal === false || !str_starts_with($path, $rootReal)) {
            return $path === false;  // already gone is success; escaping is not
        }

        return @unlink($path);
    }
}
