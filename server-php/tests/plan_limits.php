<?php

declare(strict_types=1);

/**
 * Plan limits (CONTRACTS §3.5/§3.6): the company's plan for Voice, read from Manage's companyinfo, and
 * the two limits Voice enforces from it.
 *
 *   voice.max_numbers   POST /v1/numbers adding an active number, or switching one back on
 *   voice.max_agents    POST /v1/agents adding an active agent, or switching one back on
 *
 * Pins: both plan shapes (map and Engage's [{code,value}] list), Manage's real companyinfo envelope,
 * "no plan / absent code / unreadable value = unlimited", the 402 PLAN_LIMIT_REACHED envelope with
 * {code, limit, used}, nothing written on a refusal, switched-off rows not counted, editing and
 * switching off never limited, an inbound call to a company over its limit still answered, a
 * product's service key never limited.
 *
 *   php server-php/tests/plan_limits.php   (run.sh runs it against the migrated test database)
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\PlanEntitlements;
use Aicountly\Api\ResponseSent;

require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support.php';

Env::load(__DIR__ . '/../.env');

const PL_CMP = 4171;

echo "Voice plan limits\n" . str_repeat('=', 62) . "\n";

// ===========================================================================
T::group('1. The plan, as Manage states it');
// ===========================================================================
{
    T::ok(PlanEntitlements::fromCompanyInfo(['data' => ['comp_id' => 5]]) === null, 'no plan in companyinfo: none (an older Manage = no limits)');
    $info = json_decode('{"status":1,"data":{"comp_id":501,"app":"voice","app_access_status":"ACCESS_GRANTED","plan":{"code":"voice-basic","label":"Basic","is_default":true,"status":"active","ends_at":null,"entitlements":{"voice.max_numbers":1,"voice.max_agents":3}},"lifecycle":"active","restricted_until":null}}', true);
    $plan = PlanEntitlements::fromCompanyInfo($info);
    T::ok(($plan['label'] ?? null) === 'Basic' && PlanEntitlements::limit($plan, PlanEntitlements::MAX_NUMBERS) === 1
        && PlanEntitlements::limit($plan, PlanEntitlements::MAX_AGENTS) === 3, "Manage's companyinfo plan is read (both limits)");
    T::same(1, PlanEntitlements::limit(PlanEntitlements::fromCompanyInfo($info['data']), PlanEntitlements::MAX_NUMBERS), 'an unwrapped company row reads too');
    $list = PlanEntitlements::normalize(['entitlements' => [['code' => 'voice.max_agents', 'value' => '7'], ['code' => 'feature.voice.ai', 'value' => false]]]);
    T::same(7, PlanEntitlements::limit($list, PlanEntitlements::MAX_AGENTS), "Engage's list shape [{code,value}] reads too");
    T::ok(PlanEntitlements::allows($list, 'feature.voice.ai') === false && PlanEntitlements::allows($list, 'feature.voice.other'), 'features: off when stated off, on when absent');
    foreach ([null, -1, 2.5, 'ten', true] as $v) {
        T::ok(PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => ['voice.max_numbers' => $v]]), 'voice.max_numbers') === null, 'no limit for ' . json_encode($v));
    }
    T::same('Your plan allows 1 business number — ask the account owner to upgrade in Manage.', PlanEntitlements::message(PlanEntitlements::MAX_NUMBERS, 1), 'the sentence');
}

// ===========================================================================
T::group('2. Over HTTP, through the real router');
// ===========================================================================
{
    Db::run('DELETE FROM voice_agents WHERE cmp_id = :c', ['c' => PL_CMP]);
    Db::run('DELETE FROM voice_numbers WHERE cmp_id = :c', ['c' => PL_CMP]);
    Db::run('DELETE FROM voice_provider_connections WHERE cmp_id = :c', ['c' => PL_CMP]);

    $owner = markOwner(Auth::forTesting('user-plan-owner'));
    Auth::adopt($owner);
    $withPlan = static function (?array $entitlements) use ($owner): void {
        Context::resetForTesting();
        Context::trustForTesting(PL_CMP, $owner, true, $entitlements === null ? null : ['code' => 'voice-test', 'entitlements' => $entitlements]);
    };
    $q = ['cmp_id' => (string) PL_CMP];

    $connectionId = seedConnection(PL_CMP);
    $ringing = seedNumber(PL_CMP, $connectionId, '+918066017101');
    $off = (int) Db::insert('voice_numbers', ['cmp_id' => PL_CMP, 'connection_id' => $connectionId, 'e164' => '+918066017102',
        'label' => 'Switched off', 'is_active' => false, 'routing_status' => 'paused'], 'number_id');

    $withPlan(['voice.max_numbers' => 1]);
    $r = request('POST', '/v1/numbers', $q, ['e164' => '+918066017103', 'label' => 'One more']);
    T::same(402, $r['status'], 'a number beyond voice.max_numbers is 402');
    T::same('PLAN_LIMIT_REACHED', $r['body']['error']['code'] ?? null, '…PLAN_LIMIT_REACHED');
    T::same(['code' => 'voice.max_numbers', 'limit' => 1, 'used' => 1], $r['body']['error']['details'] ?? null, '…with {code, limit, used} (the switched-off number is not counted)');
    T::ok(str_starts_with((string) ($r['body']['message'] ?? ''), 'Your plan allows 1 business number'), '…and the usual top-level message');
    T::same(0, (int) Db::scalar("SELECT COUNT(*) FROM voice_numbers WHERE e164 = '+918066017103'"), '…nothing was created');

    $r = request('POST', '/v1/numbers', $q, ['number_id' => $off, 'e164' => '+918066017102', 'is_active' => true]);
    T::same(402, $r['status'], 'switching a number back on at the limit is 402 too');
    $r = request('POST', '/v1/numbers', $q, ['number_id' => $ringing, 'e164' => '+918066017101', 'label' => 'Renamed at the limit']);
    T::same(200, $r['status'], 'editing a number that rings today is never limited');
    $r = request('POST', '/v1/numbers', $q, ['number_id' => $off, 'e164' => '+918066017102', 'label' => 'Still off', 'is_active' => false]);
    T::same(200, $r['status'], 'editing a switched-off number (kept off) is never limited');

    $withPlan(['voice.max_numbers' => 2]);
    $r = request('POST', '/v1/numbers', $q, ['e164' => '+918066017104', 'label' => 'Second']);
    T::same(200, $r['status'], 'under the limit a number is added');
    $withPlan(null);
    $r = request('POST', '/v1/numbers', $q, ['e164' => '+918066017105', 'label' => 'No plan']);
    T::same(200, $r['status'], 'no plan (an older Manage): no limit');

    // Agents.
    seedAgent(PL_CMP, '7171', '1171');
    $offAgent = (int) Db::insert('voice_agents', ['cmp_id' => PL_CMP, 'user_uuid' => '7172', 'extension' => '1172', 'is_active' => false], 'agent_id');
    $withPlan(['voice.max_agents' => 1]);
    $r = request('POST', '/v1/agents', $q, ['user_uuid' => '7173', 'extension' => '1173']);
    T::same(402, $r['status'], 'an agent beyond voice.max_agents is 402');
    T::same(['code' => 'voice.max_agents', 'limit' => 1, 'used' => 1], $r['body']['error']['details'] ?? null, '…with {code, limit, used}');
    T::same(0, (int) Db::scalar("SELECT COUNT(*) FROM voice_agents WHERE cmp_id = :c AND user_uuid = '7173'", ['c' => PL_CMP]), '…nothing was created');
    $r = request('POST', '/v1/agents', $q, ['agent_id' => $offAgent, 'user_uuid' => '7172', 'is_active' => true]);
    T::same(402, $r['status'], 'switching an agent back on at the limit is 402 too');
    $r = request('POST', '/v1/agents', $q, ['agent_id' => $offAgent, 'user_uuid' => '7172', 'skills' => ['billing'], 'is_active' => false]);
    T::same(200, $r['status'], 'editing a switched-off agent (kept off) is never limited');
    $withPlan(['voice.max_agents' => 2]);
    $r = request('POST', '/v1/agents', $q, ['user_uuid' => '7173', 'extension' => '1173']);
    T::same(200, $r['status'], 'under the limit an agent is added');

    // Reads stay open at the limit.
    $withPlan(['voice.max_numbers' => 0, 'voice.max_agents' => 0]);
    T::same(200, request('GET', '/v1/numbers', $q)['status'], 'reading numbers at (and over) the limit is never limited');
    T::same(200, request('GET', '/v1/agents', $q)['status'], 'reading agents at (and over) the limit is never limited');

    // A product's service key carries no plan and is never limited.
    $service = Auth::forTesting('lobby', 'service', 'lobby');
    $refused = false;
    try {
        PlanEntitlements::assertWithin(Context::forCompany(PL_CMP), $service, PlanEntitlements::MAX_AGENTS, static fn (): int => 999);
    } catch (ResponseSent) {
        $refused = true;
    }
    T::ok(!$refused && Context::forCompany(PL_CMP)->plan($service) === null, "a product's service key is never limited");

    // The limits are configuration only: nothing on the call path reads them.
    $callPath = '';
    foreach (['Domain/InboundCalls.php', 'Domain/CallService.php', 'Controllers/WebhooksController.php', 'Controllers/CallsController.php'] as $file) {
        $callPath .= (string) file_get_contents(__DIR__ . '/../src/' . $file);
    }
    T::ok(!str_contains($callPath, 'PlanEntitlements'), 'no call path (inbound, webhooks, placing, answering) consults the plan');

    Auth::adopt(null);
    Context::resetForTesting();
    Db::run('DELETE FROM voice_agents WHERE cmp_id = :c', ['c' => PL_CMP]);
    Db::run('DELETE FROM voice_numbers WHERE cmp_id = :c', ['c' => PL_CMP]);
    Db::run('DELETE FROM voice_provider_connections WHERE cmp_id = :c', ['c' => PL_CMP]);
}

exit(T::summary());
