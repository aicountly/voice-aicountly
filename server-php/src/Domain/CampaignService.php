<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\CrmClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Settings;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Telephony\Capability;
use Aicountly\Api\Telephony\ProviderRegistry;

/**
 * Campaigns: building them, checking them, and dispatching them safely.
 *
 * ## The audience is never copied
 *
 * voice_campaign_audience_refs holds an external id or a saved filter. At
 * dispatch, the number is read from Contacts or CRM through their live API —
 * so a campaign built last week calls the number somebody has TODAY, and a
 * contact deleted since is skipped rather than dialled from a stale copy.
 *
 * ## Dispatch is bounded, claimed and resumable
 *
 * One giant request that dials two thousand people is a request that dies
 * halfway and leaves nobody able to say who was called. Instead the worker
 * (bin/campaign-worker.php) asks for a small batch, and each attempt row is
 * CLAIMED with a conditional UPDATE before anything is dialled. Two workers
 * racing for the same person: one claims it, the other gets zero rows and
 * moves on. The unique index on (campaign_id, audience_ref_id, attempt_no) is
 * the second line of defence behind that.
 *
 * ## Pause means pause
 *
 * A paused campaign dispatches NOTHING NEW from the next claim onwards. Calls
 * already connected are not cut off — hanging up on somebody mid-sentence
 * because a manager clicked Pause is worse than letting the call finish — and
 * the UI says exactly that.
 */
final class CampaignService
{
    /** Never dispatch more than this in one worker pass, whatever the rate allows. */
    private const MAX_BATCH = 25;

    public const ACTIVE_STATES = ['scheduled', 'running'];

    /**
     * Launch readiness.
     *
     * Every check is CONFIGURABLE and each one names what is missing. There is
     * deliberately no single "compliant" check: whether an audience may be
     * called for a given purpose is a question about this business's
     * obligations, not something this code can certify. What it can do is ask
     * the question and record the answer, which is what `audience_purpose`
     * below is.
     *
     * @return array{ready: bool, checks: list<array<string, mixed>>}
     */
    public static function validate(Context $ctx, int $campaignId): array
    {
        $campaign = self::row($ctx, $campaignId);
        if ($campaign === null) {
            return ['ready' => false, 'checks' => [self::check('exists', 'error', 'Campaign not found.')]];
        }

        $checks = [];

        // --- audience --------------------------------------------------------
        $audienceCount = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_campaign_audience_refs WHERE campaign_id = :id',
            ['id' => $campaignId],
        ) ?? 0);
        $checks[] = $audienceCount > 0
            ? self::check('audience', 'pass', $audienceCount . ' audience ' . ($audienceCount === 1 ? 'entry' : 'entries') . ' selected.')
            : self::check('audience', 'error', 'No audience has been selected.');

        // The purpose question, asked explicitly and never inferred. An existing
        // business relationship is not consent to marketing.
        $script = Db::jsonColumn($campaign['script'] ?? null);
        $purpose = trim((string) ($script['audience_purpose'] ?? ''));
        $checks[] = $purpose !== ''
            ? self::check('audience_purpose', 'pass', 'Purpose recorded: ' . $purpose, [
                'note' => 'Recorded as stated. This product does not certify that it is permitted.',
            ])
            : self::check(
                'audience_purpose',
                'error',
                'State why this audience may be called for this campaign.',
                ['note' => 'An existing customer relationship is not by itself permission for marketing calls.'],
            );

        // --- suppression -----------------------------------------------------
        $suppressed = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_suppressions WHERE cmp_id = :cmp
              AND (expires_at IS NULL OR expires_at > NOW())',
            ['cmp' => $ctx->cmpId],
        ) ?? 0);
        $checks[] = self::check(
            'suppression',
            'pass',
            $suppressed . ' suppressed ' . ($suppressed === 1 ? 'number' : 'numbers') . ' will be skipped.',
            ['note' => 'Rechecked for every number at the moment it is dialled, not now.'],
        );

        // --- calling window --------------------------------------------------
        $window = CallingPolicy::windowCheckFor(
            Clock::now(),
            (string) $campaign['timezone'],
            (int) $campaign['window_start_min'],
            (int) $campaign['window_end_min'],
            array_map('intval', Db::jsonColumn($campaign['window_days'] ?? null)),
        );
        $checks[] = self::check(
            'calling_window',
            'pass',
            sprintf(
                'Calls between %02d:%02d and %02d:%02d %s.',
                intdiv((int) $campaign['window_start_min'], 60),
                (int) $campaign['window_start_min'] % 60,
                intdiv((int) $campaign['window_end_min'], 60),
                (int) $campaign['window_end_min'] % 60,
                (string) $campaign['timezone'],
            ),
            ['in_window_now' => $window['allowed']],
        );

        // --- provider and number ---------------------------------------------
        $connection = ProviderRegistry::connectionRow($ctx, $campaign['connection_id'] === null ? null : (int) $campaign['connection_id']);
        if ($connection === null) {
            $checks[] = self::check('provider', 'error', 'No telephony provider is connected.');
        } else {
            $adapter = ProviderRegistry::build($connection);
            $canPlace = $adapter->capabilities()[Capability::PLACE_CALL] ?? false;
            $checks[] = $canPlace
                ? self::check('provider', 'pass', 'Calling through ' . $adapter->label() . '.')
                : self::check('provider', 'error', $adapter->label() . ' cannot place outbound calls.');

            // A mode the provider cannot serve must not be offered as ready.
            $modeCheck = self::modeSupported((string) $campaign['mode'], $adapter->capabilities());
            $checks[] = $modeCheck['ok']
                ? self::check('mode', 'pass', 'Campaign mode "' . $campaign['mode'] . '" is supported.')
                : self::check('mode', 'error', (string) $modeCheck['message']);
        }

        $hasNumber = $campaign['number_id'] !== null || (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_numbers WHERE cmp_id = :cmp AND is_active = TRUE AND routing_status = \'active\'',
            ['cmp' => $ctx->cmpId],
        ) ?? 0) > 0;
        $checks[] = $hasNumber
            ? self::check('number', 'pass', 'A business number is available to call from.')
            : self::check('number', 'error', 'No active business number to call from.');

        // --- script / agent ---------------------------------------------------
        $checks[] = self::scriptCheck($ctx, $campaign);

        // --- budget and capacity ----------------------------------------------
        $budget = BudgetService::check($ctx, $campaignId);
        $checks[] = $budget['allowed']
            ? self::check('budget', 'pass', 'Within the configured budget and capacity.')
            : self::check('budget', 'error', (string) $budget['message']);

        // --- approval ----------------------------------------------------------
        if ((bool) Settings::forCompany($ctx->cmpId)['campaign_approval_required']) {
            $checks[] = $campaign['approved_at'] !== null
                ? self::check('approval', 'pass', 'Approved for launch.')
                : self::check('approval', 'error', 'This company requires a campaign to be approved before launch.');
        }

        $ready = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $ready = false;
                break;
            }
        }

        Db::update('voice_campaigns', [
            'readiness'  => ['ready' => $ready, 'checks' => $checks, 'at' => Clock::iso(Clock::now())],
            'updated_at' => Clock::sql(Clock::now()),
        ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

        return ['ready' => $ready, 'checks' => $checks];
    }

    /**
     * Start, pause, resume, cancel.
     *
     * @return array{ok: bool, code: ?string, message: ?string, status: ?string}
     */
    public static function act(Context $ctx, Auth $auth, int $campaignId, string $action): array
    {
        $campaign = self::row($ctx, $campaignId);
        if ($campaign === null) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Campaign not found.', 'status' => null];
        }

        $status = (string) $campaign['status'];

        return match ($action) {
            'start'  => self::start($ctx, $auth, $campaignId, $status),
            'pause'  => self::pause($ctx, $auth, $campaignId, $status),
            'resume' => self::resume($ctx, $auth, $campaignId, $status),
            'cancel' => self::cancel($ctx, $auth, $campaignId, $status),
            default  => ['ok' => false, 'code' => 'unknown_action', 'message' => 'Not a campaign action.', 'status' => $status],
        };
    }

    /**
     * Claim a bounded batch of attempts to dispatch.
     *
     * The conditional UPDATE is the claim: `WHERE status = 'queued'` means only
     * one worker can move a given row to 'dispatching', whatever else is
     * running. RETURNING gives that worker exactly the rows it won.
     *
     * A campaign that is no longer running yields nothing — which is how pause
     * takes effect without anything having to tell the workers.
     *
     * @return list<array<string, mixed>>
     */
    public static function claimBatch(int $campaignId, int $limit): array
    {
        $limit = max(1, min(self::MAX_BATCH, $limit));

        return Db::all(
            'UPDATE voice_campaign_attempts SET status = \'dispatching\', dispatched_at = NOW()
              WHERE attempt_id IN (
                    SELECT a.attempt_id
                      FROM voice_campaign_attempts a
                      JOIN voice_campaigns c ON c.campaign_id = a.campaign_id
                     WHERE a.campaign_id = :id
                       AND a.status = \'queued\'
                       AND (a.scheduled_for IS NULL OR a.scheduled_for <= NOW())
                       AND c.status = \'running\'
                     ORDER BY a.scheduled_for NULLS FIRST, a.attempt_id
                     LIMIT ' . $limit . '
                     FOR UPDATE OF a SKIP LOCKED
              )
              RETURNING *',
            ['id' => $campaignId],
        );
    }

    /**
     * Resolve one audience member's number, live, at the moment of dialling.
     *
     * THE CALL THAT KEEPS THE RULE. Nothing about the contact is stored; the
     * number comes back, is used for this attempt, and is recorded on the
     * attempt row as evidence of what was dialled.
     *
     * @param array<string, mixed> $audienceRef
     * @return array{ok: bool, e164: ?string, reason: ?string}
     */
    public static function resolveNumber(Context $ctx, Auth $auth, array $audienceRef): array
    {
        $source = (string) $audienceRef['source'];
        $externalRef = (string) ($audienceRef['external_ref'] ?? '');

        if ($externalRef === '') {
            return ['ok' => false, 'e164' => null, 'reason' => 'no_reference'];
        }

        $result = match ($source) {
            'contacts' => (new ContactsClient())->withSession($auth->sesKey())->contact($externalRef),
            'crm'      => (new CrmClient())->withSession($auth->sesKey())->lead($externalRef),
            default    => ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'unsupported_source'],
        };

        if (!$result['ok']) {
            // Unreachable is not "no number" and not "ineligible". The attempt
            // is deferred, not skipped, because the person may be perfectly
            // callable once the directory answers again.
            return ['ok' => false, 'e164' => null, 'reason' => 'owner_unavailable'];
        }

        $body = $result['body'] ?? [];
        $record = $body['data'] ?? $body;
        $raw = '';
        foreach (['mobile', 'phone', 'phone_e164', 'primary_phone', 'contact_number'] as $field) {
            if (isset($record[$field]) && is_scalar($record[$field]) && (string) $record[$field] !== '') {
                $raw = (string) $record[$field];
                break;
            }
        }

        $e164 = $raw === '' ? null : CallingPolicy::normalise($raw);

        return $e164 === null
            ? ['ok' => false, 'e164' => null, 'reason' => 'no_number']
            : ['ok' => true, 'e164' => $e164, 'reason' => null];
    }

    /** Mark an attempt's outcome, and schedule a retry if the policy allows one. */
    public static function settleAttempt(
        Context $ctx,
        array $attempt,
        string $status,
        ?string $skipReason = null,
        ?int $callId = null,
        ?string $dialled = null,
    ): void {
        Db::update('voice_campaign_attempts', [
            'status'       => $status,
            'skip_reason'  => $skipReason,
            'call_id'      => $callId,
            'dialled_e164' => $dialled,
            'completed_at' => Clock::sql(Clock::now()),
        ], ['attempt_id' => (int) $attempt['attempt_id']]);

        if (!in_array($status, ['no_answer', 'busy', 'failed'], true)) {
            return;
        }

        $campaign = Db::first(
            'SELECT max_attempts, retry_after_minutes FROM voice_campaigns WHERE campaign_id = :id',
            ['id' => (int) $attempt['campaign_id']],
        );
        if ($campaign === null) {
            return;
        }

        $next = (int) $attempt['attempt_no'] + 1;
        if ($next > (int) $campaign['max_attempts']) {
            return;
        }

        // ON CONFLICT DO NOTHING: the unique index means a retry that was
        // already queued by another worker is not queued twice.
        Db::run(
            'INSERT INTO voice_campaign_attempts
                (campaign_id, audience_ref_id, cmp_id, attempt_no, status, scheduled_for)
             VALUES (:campaign, :audience, :cmp, :no, \'queued\', NOW() + (:mins || \' minutes\')::interval)
             ON CONFLICT (campaign_id, audience_ref_id, attempt_no) DO NOTHING',
            [
                'campaign' => (int) $attempt['campaign_id'],
                'audience' => (int) $attempt['audience_ref_id'],
                'cmp'      => $ctx->cmpId,
                'no'       => $next,
                'mins'     => (int) $campaign['retry_after_minutes'],
            ],
        );
    }

    /**
     * Conversion figures for one campaign.
     *
     * Every number has ONE denominator — attempts — and the categories do not
     * overlap, so nothing here can be added together into a figure larger than
     * the campaign. A booking counts only when an external operation to the
     * owning product SUCCEEDED, which is the only authoritative source for it.
     *
     * @return array<string, mixed>
     */
    public static function funnel(Context $ctx, int $campaignId): array
    {
        $counts = Db::first(
            'SELECT
                COUNT(*)                                                       AS attempted,
                COUNT(*) FILTER (WHERE status = \'connected\' OR status = \'completed\') AS connected,
                COUNT(*) FILTER (WHERE skip_reason IS NOT NULL)                AS skipped
               FROM voice_campaign_attempts WHERE campaign_id = :id AND cmp_id = :cmp',
            ['id' => $campaignId, 'cmp' => $ctx->cmpId],
        ) ?? [];

        $qualified = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_calls c
               JOIN voice_dispositions d ON d.disposition_id = c.disposition_id
              WHERE c.campaign_id = :id AND c.cmp_id = :cmp AND d.category = \'qualified\'',
            ['id' => $campaignId, 'cmp' => $ctx->cmpId],
        ) ?? 0);

        // Authoritative only. A booking Voice "thinks" happened is not counted.
        $confirmed = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_external_operations o
               JOIN voice_calls c ON c.call_id = o.call_id
              WHERE c.campaign_id = :id AND o.cmp_id = :cmp AND o.status = \'succeeded\'',
            ['id' => $campaignId, 'cmp' => $ctx->cmpId],
        ) ?? 0);

        $attempted = (int) ($counts['attempted'] ?? 0);

        return [
            'attempted'  => $attempted,
            'connected'  => (int) ($counts['connected'] ?? 0),
            'skipped'    => (int) ($counts['skipped'] ?? 0),
            'qualified'  => $qualified,
            'confirmed'  => $confirmed,
            'denominator' => 'attempts',
            'definitions' => [
                'connected' => 'Attempts where the call was answered.',
                'qualified' => 'Answered calls given a disposition in the "qualified" category.',
                'confirmed' => 'Outcomes acknowledged by the owning product’s API — never inferred here.',
            ],
            'rates' => $attempted === 0 ? [] : [
                'connected' => round(((int) ($counts['connected'] ?? 0) / $attempted) * 100, 1),
                'qualified' => round(($qualified / $attempted) * 100, 1),
                'confirmed' => round(($confirmed / $attempted) * 100, 1),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function row(Context $ctx, int $campaignId): ?array
    {
        [$scope, $params] = $ctx->scopeClause();
        $params['id'] = $campaignId;

        return Db::first('SELECT * FROM voice_campaigns WHERE ' . $scope . ' AND campaign_id = :id', $params);
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    private static function start(Context $ctx, Auth $auth, int $campaignId, string $status): array
    {
        if (!in_array($status, ['draft', 'ready', 'scheduled'], true)) {
            return ['ok' => false, 'code' => 'illegal_transition', 'message' => 'A ' . $status . ' campaign cannot be started.', 'status' => $status];
        }

        $validation = self::validate($ctx, $campaignId);
        if (!$validation['ready']) {
            return [
                'ok'      => false,
                'code'    => 'not_ready',
                'message' => 'This campaign is not ready to launch.',
                'status'  => $status,
            ];
        }

        // Queue the first attempt per audience member. ON CONFLICT DO NOTHING
        // makes pressing Start twice harmless.
        Db::run(
            'INSERT INTO voice_campaign_attempts (campaign_id, audience_ref_id, cmp_id, attempt_no, status)
             SELECT campaign_id, audience_ref_id, cmp_id, 1, \'queued\'
               FROM voice_campaign_audience_refs
              WHERE campaign_id = :id
             ON CONFLICT (campaign_id, audience_ref_id, attempt_no) DO NOTHING',
            ['id' => $campaignId],
        );

        Db::update('voice_campaigns', [
            'status'     => 'running',
            'started_at' => Clock::sql(Clock::now()),
            'paused_at'  => null,
            'status_reason' => null,
            'updated_at' => Clock::sql(Clock::now()),
        ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::CAMPAIGN_LAUNCHED, 'campaign', (string) $campaignId);

        return ['ok' => true, 'code' => null, 'message' => null, 'status' => 'running'];
    }

    private static function pause(Context $ctx, Auth $auth, int $campaignId, string $status): array
    {
        if ($status !== 'running') {
            return ['ok' => false, 'code' => 'illegal_transition', 'message' => 'Only a running campaign can be paused.', 'status' => $status];
        }

        Db::update('voice_campaigns', [
            'status'    => 'paused',
            'paused_at' => Clock::sql(Clock::now()),
            'updated_at' => Clock::sql(Clock::now()),
        ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

        $inFlight = (int) (Db::scalar(
            'SELECT COUNT(*) FROM voice_campaign_attempts
              WHERE campaign_id = :id AND status IN (\'dispatching\', \'dialling\', \'connected\')',
            ['id' => $campaignId],
        ) ?? 0);

        Audit::record($ctx, $auth, Audit::CAMPAIGN_PAUSED, 'campaign', (string) $campaignId, ['in_flight' => $inFlight]);

        return [
            'ok'      => true,
            'code'    => null,
            // Said plainly, because a manager who pauses a campaign wants to
            // know whether anybody is still on the phone.
            'message' => $inFlight === 0
                ? 'Paused. No new calls will be placed.'
                : 'Paused. No new calls will be placed; ' . $inFlight . ' already in progress will finish.',
            'status'  => 'paused',
        ];
    }

    private static function resume(Context $ctx, Auth $auth, int $campaignId, string $status): array
    {
        if ($status !== 'paused') {
            return ['ok' => false, 'code' => 'illegal_transition', 'message' => 'Only a paused campaign can be resumed.', 'status' => $status];
        }

        $validation = self::validate($ctx, $campaignId);
        if (!$validation['ready']) {
            return ['ok' => false, 'code' => 'not_ready', 'message' => 'The launch checks no longer pass.', 'status' => $status];
        }

        Db::update('voice_campaigns', [
            'status'    => 'running',
            'paused_at' => null,
            'updated_at' => Clock::sql(Clock::now()),
        ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

        return ['ok' => true, 'code' => null, 'message' => 'Resumed.', 'status' => 'running'];
    }

    private static function cancel(Context $ctx, Auth $auth, int $campaignId, string $status): array
    {
        if (in_array($status, ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'code' => 'illegal_transition', 'message' => 'That campaign has already finished.', 'status' => $status];
        }

        Db::transaction(static function () use ($ctx, $campaignId): void {
            Db::update('voice_campaigns', [
                'status'       => 'cancelled',
                'completed_at' => Clock::sql(Clock::now()),
                'updated_at'   => Clock::sql(Clock::now()),
            ], ['campaign_id' => $campaignId, 'cmp_id' => $ctx->cmpId]);

            // Queued attempts are cancelled; in-flight ones are left to finish.
            Db::run(
                'UPDATE voice_campaign_attempts SET status = \'cancelled\', completed_at = NOW()
                  WHERE campaign_id = :id AND status = \'queued\'',
                ['id' => $campaignId],
            );
        });

        Audit::record($ctx, $auth, Audit::CAMPAIGN_CANCELLED, 'campaign', (string) $campaignId);

        return ['ok' => true, 'code' => null, 'message' => 'Cancelled. Queued calls will not be placed.', 'status' => 'cancelled'];
    }

    /** @param array<string, bool> $capabilities @return array{ok: bool, message: ?string} */
    private static function modeSupported(string $mode, array $capabilities): array
    {
        $needs = match ($mode) {
            'announcement', 'tts', 'appointment_reminder' => Capability::TTS_PLAYBACK,
            'ivr'             => Capability::DTMF,
            'ai_conversation' => Capability::LIVE_TRANSCRIPT,
            default           => Capability::PLACE_CALL,
        };

        if ($capabilities[$needs] ?? false) {
            return ['ok' => true, 'message' => null];
        }

        return [
            'ok'      => false,
            'message' => 'This connection does not support ' . Capability::describe($needs)
                . ', which "' . $mode . '" campaigns need.',
        ];
    }

    /** @param array<string, mixed> $campaign @return array<string, mixed> */
    private static function scriptCheck(Context $ctx, array $campaign): array
    {
        $mode = (string) $campaign['mode'];

        if ($mode === 'ai_conversation') {
            if ($campaign['ai_agent_id'] === null) {
                return self::check('script', 'error', 'No AI agent is selected.');
            }
            $agent = Db::first(
                'SELECT status, published_version_id FROM voice_ai_agents
                  WHERE ai_agent_id = :id AND cmp_id = :cmp',
                ['id' => (int) $campaign['ai_agent_id'], 'cmp' => $ctx->cmpId],
            );
            if ($agent === null) {
                return self::check('script', 'error', 'The selected AI agent no longer exists.');
            }
            if ((string) $agent['status'] !== 'published' || $agent['published_version_id'] === null) {
                return self::check('script', 'error', 'The AI agent has no published version.');
            }

            return self::check('script', 'pass', 'Using the published AI agent version.');
        }

        $script = Db::jsonColumn($campaign['script'] ?? null);
        $body = trim((string) ($script['body'] ?? ''));
        if ($body === '') {
            return self::check('script', 'error', 'No script has been written.');
        }

        return (bool) ($script['reviewed'] ?? false)
            ? self::check('script', 'pass', 'Script written and marked reviewed.')
            : self::check('script', 'error', 'The script has not been reviewed.');
    }

    /** @param array<string, mixed> $detail @return array<string, mixed> */
    private static function check(string $key, string $status, string $message, array $detail = []): array
    {
        return ['key' => $key, 'status' => $status, 'message' => $message] + $detail;
    }
}
