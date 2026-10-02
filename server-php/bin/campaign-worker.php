<?php

declare(strict_types=1);

/**
 * Campaign dispatcher.
 *
 *   php server-php/bin/campaign-worker.php            one bounded pass
 *   php server-php/bin/campaign-worker.php --once     the same, explicitly
 *   php server-php/bin/campaign-worker.php --campaign=12
 *
 * Run it from cron every minute. It is safe to run several copies at once:
 * every attempt is CLAIMED with a conditional UPDATE before anything is dialled,
 * so two workers cannot dial the same person, and the unique index on
 * (campaign_id, audience_ref_id, attempt_no) is the second line of defence.
 *
 * ## Why this is not one long request
 *
 * A campaign of two thousand people is not a web request. It is a sequence of
 * short passes that each claim a handful of attempts, dial them, record what
 * happened, and exit. If the process dies, the claimed rows are visible as
 * 'dispatching' and are recovered; nothing is lost and nobody is dialled twice.
 *
 * ## Everything is re-checked at dispatch
 *
 * Suppression, the calling window, budget and capacity are checked HERE, for
 * each attempt, not when the campaign was built. Somebody who opted out this
 * morning is not called this afternoon.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\BudgetService;
use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Domain\CallService;
use Aicountly\Api\Domain\CampaignService;
use Aicountly\Api\Domain\ContactsDelegation;
use Aicountly\Api\Support\Clock;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$args = array_slice($argv, 1);
$onlyCampaign = 0;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--campaign=')) {
        $onlyCampaign = (int) substr($arg, strlen('--campaign='));
    }
}

/**
 * The identity campaign calls are placed under.
 *
 * A service identity, not a borrowed human session: the person who built the
 * campaign is not on the phone, and their session may have expired hours ago.
 */
$auth = Auth::forTesting('service:campaign-worker', 'service', 'voice');
Auth::adopt($auth);

$campaigns = Db::all(
    'SELECT campaign_id, cmp_id, bo_id, max_concurrent, calls_per_minute, timezone,
            window_start_min, window_end_min, window_days, connection_id, number_id,
            ai_agent_id, ai_version_id
       FROM voice_campaigns
      WHERE status = \'running\'' . ($onlyCampaign > 0 ? ' AND campaign_id = :id' : '') . '
      ORDER BY updated_at
      LIMIT 25',
    $onlyCampaign > 0 ? ['id' => $onlyCampaign] : [],
);

$dispatched = 0;
$skipped = 0;

foreach ($campaigns as $campaign) {
    $campaignId = (int) $campaign['campaign_id'];
    $ctx = Context::forCompany((int) $campaign['cmp_id'], (int) $campaign['bo_id']);

    // Outside the campaign's own window, nothing is dialled at all — not even
    // one attempt to "see how it goes".
    $window = CallingPolicy::windowCheckFor(
        Clock::now(),
        (string) $campaign['timezone'],
        (int) $campaign['window_start_min'],
        (int) $campaign['window_end_min'],
        array_map('intval', Db::jsonColumn($campaign['window_days'] ?? null)),
    );
    if (!$window['allowed']) {
        continue;
    }

    // Tenant concurrency, before claiming anything.
    $capacity = BudgetService::capacityCheck($ctx);
    if (!$capacity['allowed']) {
        continue;
    }

    $inFlight = (int) (Db::scalar(
        'SELECT COUNT(*) FROM voice_campaign_attempts
          WHERE campaign_id = :id AND status IN (\'dispatching\', \'dialling\', \'connected\')',
        ['id' => $campaignId],
    ) ?? 0);

    $headroom = min(
        max(0, (int) $campaign['max_concurrent'] - $inFlight),
        max(1, (int) $campaign['calls_per_minute']),
    );
    if ($headroom <= 0) {
        continue;
    }

    // Contacts is read through the grant a person issued for this campaign
    // (ContactsDelegation) — never with an empty session. Fetched once per pass.
    $contactsClient = null;
    $paused = false;

    foreach (CampaignService::claimBatch($campaignId, $headroom) as $attempt) {
        if ($paused) {
            // The campaign was paused mid-batch: give the claim back untouched.
            Db::update('voice_campaign_attempts', ['status' => 'queued'], ['attempt_id' => (int) $attempt['attempt_id']]);
            continue;
        }

        $audience = Db::first(
            'SELECT * FROM voice_campaign_audience_refs WHERE audience_ref_id = :id',
            ['id' => (int) $attempt['audience_ref_id']],
        );
        if ($audience === null) {
            CampaignService::settleAttempt($ctx, $attempt, 'skipped', 'no_reference');
            $skipped++;
            continue;
        }

        if ((string) $audience['source'] === 'contacts' && $contactsClient === null) {
            $access = ContactsDelegation::workerClient($ctx->cmpId, $campaignId);
            if (!$access['ok']) {
                // No usable grant: pause with the reason. Nothing is dialled
                // and nothing loops until a person resumes (and so renews).
                CampaignService::pauseFor($ctx, $campaignId, (string) $access['code'], (string) $access['message']);
                Db::update('voice_campaign_attempts', ['status' => 'queued'], ['attempt_id' => (int) $attempt['attempt_id']]);
                $paused = true;
                continue;
            }
            $contactsClient = $access['client'];
        }

        // LIVE. The number comes from the owning product now, not from a copy.
        $resolved = CampaignService::resolveNumber($ctx, $auth, $audience, $contactsClient);

        if ($resolved['action'] === 'pause') {
            if ((string) $audience['source'] === 'contacts') {
                ContactsDelegation::markRefused($campaignId, (string) $resolved['reason']);
            }
            CampaignService::pauseFor($ctx, $campaignId, (string) $resolved['reason'], (string) $resolved['message']);
            Db::update('voice_campaign_attempts', ['status' => 'queued'], ['attempt_id' => (int) $attempt['attempt_id']]);
            $paused = true;
            continue;
        }
        if ($resolved['action'] === 'defer') {
            // "The directory is down" is not "this person has no number": it is
            // retried, backed off and counted — and ends.
            CampaignService::deferAttempt($ctx, $attempt, (string) $resolved['reason']);
            $skipped++;
            continue;
        }
        if ($resolved['action'] === 'skip') {
            CampaignService::settleAttempt($ctx, $attempt, 'skipped', (string) $resolved['reason']);
            $skipped++;
            continue;
        }

        // Contacts merged this person into another record: the survivor is who
        // we call, and Voice's own audience row follows it (a live reference).
        if ((string) $audience['source'] === 'contacts' && $resolved['contact_ref'] !== null
            && $resolved['contact_ref'] !== (string) $audience['external_ref']) {
            Db::run(
                'UPDATE voice_campaign_audience_refs SET external_ref = :ref
                  WHERE audience_ref_id = :id
                    AND NOT EXISTS (SELECT 1 FROM voice_campaign_audience_refs o
                                     WHERE o.campaign_id = voice_campaign_audience_refs.campaign_id
                                       AND o.source = \'contacts\' AND o.external_ref = :ref)',
                ['ref' => $resolved['contact_ref'], 'id' => (int) $audience['audience_ref_id']],
            );
            $audience['external_ref'] = $resolved['contact_ref'];
        }

        $e164 = (string) $resolved['e164'];

        // Rechecked per number, per attempt.
        $policy = CallingPolicy::check($ctx, $e164);
        if (!$policy['allowed']) {
            CampaignService::settleAttempt($ctx, $attempt, 'skipped', (string) $policy['reason'], null, $e164);
            $skipped++;
            continue;
        }

        $budget = BudgetService::check($ctx, $campaignId);
        if (!$budget['allowed']) {
            // Put it back rather than losing it: the budget resets tomorrow.
            Db::update('voice_campaign_attempts', [
                'status'        => 'queued',
                'skip_reason'   => (string) $budget['reason'],
                'scheduled_for' => Clock::sql(Clock::now()->modify('+30 minutes')),
            ], ['attempt_id' => (int) $attempt['attempt_id']]);
            break;
        }

        $result = CallService::place($ctx, $auth, [
            'to'            => $e164,
            'campaign_id'   => $campaignId,
            'connection_id' => $campaign['connection_id'] === null ? null : (int) $campaign['connection_id'],
            'number_id'     => $campaign['number_id'] === null ? null : (int) $campaign['number_id'],
            'ai_agent_id'   => $campaign['ai_agent_id'] === null ? null : (int) $campaign['ai_agent_id'],
            'ai_version_id' => $campaign['ai_version_id'] === null ? null : (int) $campaign['ai_version_id'],
            'contact_ref'   => (string) $audience['source'] === 'contacts' ? $audience['external_ref'] : null,
        ]);

        if ($result['ok']) {
            Db::update('voice_campaign_attempts', [
                'status'       => 'dialling',
                'call_id'      => (int) ($result['call']['call_id'] ?? 0),
                'dialled_e164' => $e164,
            ], ['attempt_id' => (int) $attempt['attempt_id']]);
            $dispatched++;
            continue;
        }

        // An unconfirmed dial is left as dialling, NOT retried. It may be
        // ringing; the provider's callbacks settle it.
        if ($result['code'] === 'outcome_unknown') {
            Db::update('voice_campaign_attempts', [
                'status'       => 'dialling',
                'skip_reason'  => 'provider_unconfirmed',
                'call_id'      => isset($result['detail']['call_id']) ? (int) $result['detail']['call_id'] : null,
                'dialled_e164' => $e164,
            ], ['attempt_id' => (int) $attempt['attempt_id']]);
            $dispatched++;
            continue;
        }

        CampaignService::settleAttempt($ctx, $attempt, 'failed', (string) $result['code'], null, $e164);
    }

    // A campaign with nothing left to do is finished.
    $outstanding = (int) (Db::scalar(
        'SELECT COUNT(*) FROM voice_campaign_attempts
          WHERE campaign_id = :id AND status IN (\'queued\', \'dispatching\', \'dialling\', \'connected\')',
        ['id' => $campaignId],
    ) ?? 0);
    if ($outstanding === 0 && !$paused) {
        Db::update('voice_campaigns', [
            'status'       => 'completed',
            'completed_at' => Clock::sql(Clock::now()),
            'updated_at'   => Clock::sql(Clock::now()),
        ], ['campaign_id' => $campaignId]);
    }
}

echo sprintf(
    "campaigns=%d dispatched=%d skipped=%d\n",
    count($campaigns),
    $dispatched,
    $skipped,
);
