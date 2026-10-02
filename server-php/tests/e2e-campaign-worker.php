<?php

declare(strict_types=1);

/**
 * The campaign worker against the REAL portal, Manage and Contacts (G18#1).
 *
 * Needs the e2e harness and a migrated Voice test database (tests/run.sh once):
 *
 *   /home/user/e2e/bin/up.sh --agent vm --port-base 20000
 *   STUB_PORT=20012 php -S 127.0.0.1:20012 server-php/tests/stub/router.php &   # telephony gateway only
 *   /home/user/e2e/bin/with-stack.sh --agent vm -- php server-php/tests/e2e-campaign-worker.php 20012
 *
 * A (101) owns X (501) per the real Manage; "Kiran Vendor" is a company contact
 * in X; "Bala Member" is A's PERSONAL contact. Voice signs A in through the real
 * validatesession, asks the real Manage who owns X, builds a campaign, and the
 * real Contacts issues, honours and revokes the grant the worker uses.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\CampaignService;
use Aicountly\Api\Env;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

$gatewayPort = (int) ($argv[1] ?? 0);
foreach (['E2E_CONTACTS_URL', 'E2E_MANAGE_ORIGIN', 'E2E_PORTAL_ORIGIN', 'E2E_SES_A', 'E2E_SVC_KEY_VOICE'] as $var) {
    if ((string) getenv($var) === '' || $gatewayPort <= 0) {
        fwrite(STDERR, "usage: with-stack.sh --agent vm -- php tests/e2e-campaign-worker.php <stub gateway port>\n");
        exit(2);
    }
}

$env = [
    'PORTAL_AUTH_BASE'     => (string) getenv('E2E_PORTAL_ORIGIN'),
    'MANAGE_API_BASE'      => (string) getenv('E2E_MANAGE_ORIGIN'),
    'CONTACTS_API_BASE'    => (string) getenv('E2E_CONTACTS_URL'),
    'CONTACTS_SERVICE_KEY' => (string) getenv('E2E_SVC_KEY_VOICE'),
    'VOICE_GATEWAY_URL'    => 'http://127.0.0.1:' . $gatewayPort,
    'AIC_ENVIRONMENT'      => 'local',
];
foreach ($env as $k => $v) {
    putenv($k . '=' . $v);
}
Env::load(__DIR__ . '/../.env');

$fixture = json_decode((string) @file_get_contents('/home/user/e2e/run/vm/fixture.json'), true) ?: [];
$kiran = (string) ($fixture['X_company_kiran']['id'] ?? '');
$bala = (string) ($fixture['A_personal_bala']['id'] ?? '');
const X = 501;

echo "Voice campaign worker vs real portal/Manage/Contacts\n";

// A signs in: the REAL validatesession, an integer uuid_aictly, no acs_type.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . getenv('E2E_SES_A');
$_SERVER['REQUEST_URI'] = '/v1/campaigns';
$auth = Auth::resolve();
T::ok($auth !== null && $auth->uuid === '101', 'A is resolved by the real validatesession as user 101');
Auth::adopt($auth);

$ctx = Context::forCompany(X);
Context::resetForTesting();
$ctx->assertAllowed($auth);
T::ok($ctx->isOwner($auth), 'the real Manage companyinfo says A owns X (no acs_type anywhere)');

// Voice's own fixture for X.
foreach (['voice_campaign_attempts', 'voice_campaign_audience_refs', 'voice_directory_grants', 'voice_calls', 'voice_campaigns', 'voice_numbers', 'voice_provider_connections', 'voice_settings'] as $table) {
    Db::run("DELETE FROM {$table} WHERE cmp_id = :c", ['c' => X]);
}
$connectionId = seedConnection(X);
seedNumber(X, $connectionId, '+918066000501');
seedSettings(X, ['campaign_approval_required' => false]);
$campaignId = (int) Db::insert('voice_campaigns', [
    'cmp_id' => X, 'name' => 'E2E delegation', 'mode' => 'preview', 'status' => 'draft',
    'connection_id' => $connectionId, 'timezone' => 'UTC',
    'window_start_min' => 0, 'window_end_min' => 1440, 'window_days' => [0, 1, 2, 3, 4, 5, 6],
    'max_concurrent' => 10, 'calls_per_minute' => 10, 'max_attempts' => 1,
    'script' => ['body' => 'Hello', 'reviewed' => true, 'audience_purpose' => 'Requested callback'],
], 'campaign_id');

// Building the audience: the personal contact is refused, the company one accepted.
$refused = request('POST', '/v1/campaigns/' . $campaignId . '/audience', ['cmp_id' => (string) X], ['source' => 'contacts', 'refs' => [$kiran, $bala]]);
T::same(422, $refused['status'], "A's PERSONAL contact cannot be a company campaign's audience (real Contacts resolve)");
$accepted = request('POST', '/v1/campaigns/' . $campaignId . '/audience', ['cmp_id' => (string) X], ['source' => 'contacts', 'refs' => [$kiran]]);
T::same(200, $accepted['status'], 'the company contact is accepted');
T::same(true, $accepted['body']['data']['directory_access']['ok'] ?? null, 'and configuring it already obtained a grant from Contacts');

$start = CampaignService::act($ctx, $auth, $campaignId, 'start');
T::ok($start['ok'], 'A starts the campaign (grant issued by the real Contacts with A present)');

$runWorker = static function () use ($campaignId, $env): string {
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    return (string) shell_exec($prefix . 'php ' . escapeshellarg(__DIR__ . '/../bin/campaign-worker.php') . ' --campaign=' . $campaignId . ' 2>&1');
};
echo '    worker: ' . trim($runWorker()) . "\n";
$dialled = Db::first('SELECT a.status, a.dialled_e164 FROM voice_campaign_attempts a JOIN voice_campaign_audience_refs r ON r.audience_ref_id = a.audience_ref_id
                       WHERE a.campaign_id = :id AND r.external_ref = :ref', ['id' => $campaignId, 'ref' => $kiran]);
T::same('+919845098765', $dialled['dialled_e164'] ?? null, 'the worker (no session) read Kiran through the grant and dialled +919845098765');

// Revocation at Contacts (what offboarding or a Manage "no" does): the worker pauses.
$grantId = (string) Db::scalar("SELECT grant_id FROM voice_directory_grants WHERE campaign_id = :id AND status = 'active'", ['id' => $campaignId]);
$revoke = (new \Aicountly\Api\Clients\ContactsClient())->withSession((string) getenv('E2E_SES_A'))->revokeDelegation(X, $grantId);
T::ok($revoke['ok'], 'the grant is revoked at Contacts');
Db::run('DELETE FROM voice_campaign_audience_refs WHERE campaign_id = :id', ['id' => $campaignId]);
$ref = (int) Db::insert('voice_campaign_audience_refs', ['campaign_id' => $campaignId, 'cmp_id' => X, 'source' => 'contacts', 'external_ref' => $kiran], 'audience_ref_id');
Db::insert('voice_campaign_attempts', ['campaign_id' => $campaignId, 'audience_ref_id' => $ref, 'cmp_id' => X, 'attempt_no' => 1, 'status' => 'queued'], 'attempt_id');
Db::run("UPDATE voice_campaigns SET status = 'running' WHERE campaign_id = :id", ['id' => $campaignId]);
echo '    worker: ' . trim($runWorker()) . "\n";
$campaign = Db::first('SELECT status, status_reason FROM voice_campaigns WHERE campaign_id = :id', ['id' => $campaignId]);
T::same('paused', $campaign['status'] ?? null, 'Contacts answers 401 delegation_invalid and the worker PAUSES the campaign');
T::ok(str_starts_with((string) ($campaign['status_reason'] ?? ''), 'directory_access_invalid'), 'with the reason: ' . (string) ($campaign['status_reason'] ?? ''));
T::same('queued', Db::scalar('SELECT status FROM voice_campaign_attempts WHERE audience_ref_id = :r', ['r' => $ref]), 'the attempt is kept, not burned');

// Renewal while A is present.
$resume = CampaignService::act($ctx, $auth, $campaignId, 'resume');
T::ok($resume['ok'], 'A resumes; a fresh grant is issued');
echo '    worker: ' . trim($runWorker()) . "\n";
T::same('dialling', Db::scalar('SELECT status FROM voice_campaign_attempts WHERE audience_ref_id = :r', ['r' => $ref]), 'and the worker carries on');

// G18#4 against the real Contacts: identify an inbound caller in company X.
$known = InboundTest::call(X, '+919845098765');
$who = \Aicountly\Api\Domain\InboundCalls::identify($ctx, $auth, $known);
T::same('matched', $who['state'], 'an inbound call from Kiran\'s number is identified (real company lookup, matchCount 1)');
T::same($kiran, Db::scalar('SELECT contact_ref FROM voice_calls WHERE call_id = :id', ['id' => $known]), 'and linked to the company contact id');
$stranger = InboundTest::call(X, '+919845011111');
T::same('no_match', \Aicountly\Api\Domain\InboundCalls::identify($ctx, $auth, $stranger)['state'], 'an unknown caller is no_match, nothing linked');

exit(T::summary());
