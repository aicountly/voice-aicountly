<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * The distinctions that matter in a calling business, and why each is its own
 * permission rather than a role:
 *
 *  - Placing a call spends money and reaches a member of the public. Listening
 *    to a recording of one is a different act entirely: the call is over, the
 *    other party is not there, and what is being opened is a recording of a
 *    private conversation. An agent needs the first all day and most agents
 *    never need the second.
 *  - Downloading or exporting a recording takes it out of this product's
 *    retention and access controls altogether, so it is separate again from
 *    listening to one in the browser.
 *  - Launching a campaign dials hundreds of people at once. Building one does
 *    not dial anybody. Those are hours apart in consequence and are split.
 *  - Monitoring a live call is surveillance of a colleague. It is never implied
 *    by seniority here; it is granted explicitly or not at all.
 *
 * ENFORCED IN THE BACKEND. Hiding a menu item in React is a courtesy, not a
 * control — the API route is one curl away.
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'voice_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'voice_permission_assignments';

    /** @var array<string, array<string, string>> */
    public const CATALOG = [
        'Dashboards' => [
            'voice.dashboard.view' => 'Open the Voice dashboards',
            'voice.reports.view'   => 'View call reports',
            'voice.reports.export' => 'Export call reports and call history',
        ],
        'Calling' => [
            'voice.call.view'     => 'View calls and call history',
            'voice.call.place'    => 'Place outbound calls',
            'voice.call.handle'   => 'Answer and handle inbound calls',
            'voice.call.transfer' => 'Transfer a call to another agent or queue',
            'voice.call.disposition' => 'Set the outcome of a call',
        ],
        'Supervision' => [
            'voice.supervisor.monitor' => 'Listen to a colleague’s call in progress',
            'voice.presence.manage'    => 'Change another agent’s availability',
        ],
        'Conversations' => [
            'voice.transcripts.view'    => 'Read call transcripts',
            'voice.recordings.listen'   => 'Play call recordings',
            'voice.recordings.download' => 'Download call recordings',
            'voice.voicemail.view'      => 'Open the voicemail inbox',
            'voice.quality.review'      => 'Score calls against the quality rubric',
            'voice.commitments.confirm' => 'Confirm a commitment found in a call',
        ],
        'Callbacks' => [
            'voice.callbacks.view'   => 'View the callback queue',
            'voice.callbacks.manage' => 'Create, reassign and close callbacks',
        ],
        'Campaigns' => [
            'voice.campaigns.view'   => 'View campaigns and their results',
            'voice.campaigns.manage' => 'Build and edit campaigns',
            'voice.campaigns.launch' => 'Start, pause and cancel a campaign',
            'voice.campaigns.approve' => 'Approve a campaign for launch',
        ],
        'AI Voice' => [
            'voice.ai.view'    => 'View AI voice agents',
            'voice.ai.manage'  => 'Build and test AI voice agents',
            'voice.ai.publish' => 'Publish an AI voice agent version',
        ],
        'Configuration' => [
            'voice.numbers.manage' => 'Manage business numbers and their routing',
            'voice.queues.manage'  => 'Manage queues and ring groups',
            'voice.flows.manage'   => 'Build and edit call flows',
            'voice.team.manage'    => 'Manage agents, teams and extensions',
        ],
        'Administration' => [
            'voice.settings.manage'     => 'Change Voice settings and policies',
            'voice.providers.manage'    => 'Connect and configure telephony providers',
            'voice.integrations.manage' => 'Connect and configure integrations',
            'voice.retention.manage'    => 'Change recording and transcript retention',
            'voice.budget.manage'       => 'Set spending limits and capacity',
            'voice.access.manage'       => 'Manage Voice permission profiles',
            'voice.audit.view'          => 'Read the Voice audit trail',
        ],
    ];

    /**
     * What a company gets before anybody has configured anything.
     *
     * Without this, the first person into a brand-new company sees a working
     * sign-in and a wall of refusals, which reads as a broken product rather
     * than as an administrative step nobody has taken yet. The owner (portal
     * acs_type 1) holds everything regardless; this is for everybody else on
     * day one.
     *
     * It deliberately grants no recording access, no campaign launch and no
     * supervisor monitoring. Those three are the ones that are expensive or
     * intrusive to get wrong, and "nobody had configured permissions yet" is
     * not a defence for any of them.
     *
     * @var list<string>
     */
    public const DEFAULT_MEMBER_GRANTS = [
        'voice.dashboard.view',
        'voice.call.view',
        'voice.call.handle',
        'voice.call.disposition',
        'voice.callbacks.view',
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($auth->accessType() === 1) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->accessType() === 1) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            // Refuse, do not fall back to the day-one grants: a database that is
            // down must not quietly hand somebody the default set.
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        if ($rows === []) {
            return self::$cache[$key] = self::DEFAULT_MEMBER_GRANTS;
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /**
     * Drop the memoised grants for a user.
     *
     * The cache is per-request, which is right for reads: `granted()` is called
     * several times while rendering a screen. But an endpoint that CHANGES
     * somebody's profile and then reports the result would answer from the
     * grants it read before the change — including, when the caller edits their
     * own access, telling them the edit did nothing.
     */
    public static function forget(?Context $ctx = null, ?Auth $auth = null): void
    {
        if ($ctx === null || $auth === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$ctx->cmpId . ':' . $auth->uuid]);
    }

    /**
     * The permissions this caller may hand to somebody else.
     *
     * A company owner may grant anything. Anybody else may grant only what they
     * themselves hold — otherwise `access.manage` is not a permission, it is a
     * route to every other permission.
     *
     * @return list<string>
     */
    public static function grantable(Context $ctx, Auth $auth): array
    {
        if ($auth->isService() || $auth->accessType() === 1) {
            return self::all();
        }

        return self::granted($ctx, $auth);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
