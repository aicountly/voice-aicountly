<?php

declare(strict_types=1);

/**
 * Voice's reviewed company-lifecycle purge manifest (lib/company-lifecycle, kit README §2 step 4).
 *
 * Drafted by lib/company-lifecycle/bin/discover-manifest.php from a freshly migrated database
 * (migrations 001–015) and reviewed line by line; decisions in docs/COMPANY_LIFECYCLE.md.
 *
 *   company_tables  EVERYTHING company-scoped. Call recordings and transcripts are personal data that no
 *                   statute requires Voice to keep, and nothing kept refers to them; calls, campaigns,
 *                   flows, agents, numbers, usage estimates (the billed usage is metered in Manage) and
 *                   Voice's own audit trail go too. Archived first, children before parents; the stored
 *                   recordings of the purged rows are deleted after the commit (src/LifecycleFiles.php).
 *   company_retain  none.
 *   user_tables     the person's own saved searches and live-stream tickets, in every company.
 *   ignore          company records naming a person (agents, access grants), and the bookkeeping.
 */

// The tables live in DB_SCHEMA when it is set (src/Db.php puts it first on the search_path).
$schema = class_exists(\Aicountly\Api\Env::class) ? \Aicountly\Api\Env::get('DB_SCHEMA') : (string) getenv('DB_SCHEMA');

return [
    'app_key' => 'voice',
    'schema'  => $schema !== '' ? $schema : 'public',
    'strict'  => true,

    'company_tables' => [
        // Transcripts: personal data, no statute — deleted with the calls.
        ['table' => 'voice_transcript_segments', 'key' => 'cmp_id'],
        ['table' => 'voice_call_legs', 'key' => 'cmp_id'],
        ['table' => 'voice_call_participants', 'key' => 'cmp_id'],
        ['table' => 'voice_external_operations', 'key' => 'cmp_id'],
        ['table' => 'voice_voicemails', 'key' => 'cmp_id'],
        ['table' => 'voice_callbacks', 'key' => 'cmp_id'],
        ['table' => 'voice_call_events', 'key' => 'cmp_id'],
        ['table' => 'voice_call_summaries', 'key' => 'cmp_id'],
        ['table' => 'voice_campaign_attempts', 'key' => 'cmp_id'],
        ['table' => 'voice_commitments', 'key' => 'cmp_id'],
        ['table' => 'voice_quality_reviews', 'key' => 'cmp_id'],
        // Call and voicemail recordings: personal data with no statute keeping them, and nothing retained here
        // refers to them — deleted, and their stored files after the purge commits (src/LifecycleFiles.php).
        // A legal hold set inside Voice does not survive the company's own purge (docs/COMPANY_LIFECYCLE.md).
        ['table' => 'voice_recordings', 'key' => 'cmp_id'],
        // Voice's own cost estimates for budgets; the billed usage is metered in Manage (AicountlyUsageMeter), not here.
        ['table' => 'voice_usage_entries', 'key' => 'cmp_id'],
        ['table' => 'voice_calls', 'key' => 'cmp_id'],
        ['table' => 'voice_queue_members', 'key' => 'cmp_id'],
        ['table' => 'voice_team_members', 'key' => 'cmp_id'],
        // user_uuid links a company staff record (agent) to a portal account — the company's record.
        ['table' => 'voice_agents', 'key' => 'cmp_id'],
        ['table' => 'voice_ai_test_runs', 'key' => 'cmp_id'],
        ['table' => 'voice_ai_agent_versions', 'key' => 'cmp_id'],
        ['table' => 'voice_campaign_audience_refs', 'key' => 'cmp_id'],
        ['table' => 'voice_directory_grants', 'key' => 'cmp_id'],
        ['table' => 'voice_campaigns', 'key' => 'cmp_id'],
        ['table' => 'voice_ai_agents', 'key' => 'cmp_id'],
        ['table' => 'voice_budget_policies', 'key' => 'cmp_id'],
        ['table' => 'voice_call_flow_versions', 'key' => 'cmp_id'],
        ['table' => 'voice_call_flows', 'key' => 'cmp_id'],
        ['table' => 'voice_dispositions', 'key' => 'cmp_id'],
        ['table' => 'voice_idempotency_keys', 'key' => 'cmp_id'],
        ['table' => 'voice_integrations', 'key' => 'cmp_id'],
        ['table' => 'voice_numbers', 'key' => 'cmp_id'],
        // user_uuid: the member a company profile is given to — the company's record.
        ['table' => 'voice_permission_assignments', 'key' => 'cmp_id'],
        ['table' => 'voice_permission_profiles', 'key' => 'cmp_id'],
        ['table' => 'voice_provider_connections', 'key' => 'cmp_id'],
        ['table' => 'voice_quality_rubrics', 'key' => 'cmp_id'],
        ['table' => 'voice_queues', 'key' => 'cmp_id'],
        // A person's saved searches inside the company: also their own data (user_tables).
        ['table' => 'voice_saved_searches', 'key' => 'cmp_id'],
        ['table' => 'voice_settings', 'key' => 'cmp_id'],
        // Short-lived live-stream tickets: also the person's own (user_tables).
        ['table' => 'voice_stream_tickets', 'key' => 'cmp_id'],
        ['table' => 'voice_suppressions', 'key' => 'cmp_id'],
        ['table' => 'voice_teams', 'key' => 'cmp_id'],
        // Voice's own audit trail (who played or downloaded a recording, who changed a flow): no statute keeps it.
        ['table' => 'voice_audit_events', 'key' => 'cmp_id'],
    ],

    'company_retain' => [],

    'user_tables' => [
        ['table' => 'voice_saved_searches', 'key' => 'user_uuid'],
        ['table' => 'voice_stream_tickets', 'key' => 'user_uuid'],
    ],

    'user_retain' => [],

    'ignore' => [
        ['table' => 'voice_agents', 'reason' => 'user scope only: the company\'s agent record for the person — the company\'s, purged on the company purge'],
        ['table' => 'voice_permission_assignments', 'reason' => 'user scope only: the company\'s grant to the person — purged on the company purge'],
        ['table' => 'voice_sql_migrations', 'reason' => 'migration bookkeeping (bin/migrate.php)'],
        ['table' => 'voice_lifecycle_file_deletions', 'reason' => 'the audit / retry queue of recordings deleted after purges (target_cmp_id, not company data)'],
    ],
];
