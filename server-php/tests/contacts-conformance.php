<?php

declare(strict_types=1);

/**
 * Voice ↔ Contacts conformance, against the REAL Contacts handlers.
 *
 * Not part of tests/run.sh: it needs the e2e harness (real Contacts + real
 * Manage + the real my.aicountly validatesession code, integer user ids):
 *
 *   /home/user/e2e/bin/up.sh --agent vm --port-base 20000
 *   /home/user/e2e/bin/with-stack.sh --agent vm -- php server-php/tests/contacts-conformance.php
 *
 * Fixture (harness README): A=101 owns X=501, B=102 member of X, C=103 owns
 * Y=502, D=104 removed from X; X company contact "Kiran Vendor" created by A;
 * A's personal contact "Bala Member". Everything here goes through Voice's own
 * ContactsClient (the vendored shared client underneath) — no stub.
 */

namespace Aicountly\Api\Tests;

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Env;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$need = ['E2E_CONTACTS_URL', 'E2E_SES_A', 'E2E_SES_B', 'E2E_SES_C', 'E2E_SES_D', 'E2E_SVC_KEY_VOICE'];
foreach ($need as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "missing {$var}: run through /home/user/e2e/bin/with-stack.sh --agent vm\n");
        exit(2);
    }
}

// Point Voice at the stack's Contacts API (with /api, as in production).
putenv('CONTACTS_API_BASE=' . getenv('E2E_CONTACTS_URL'));
putenv('CONTACTS_SERVICE_KEY=' . getenv('E2E_SVC_KEY_VOICE'));
putenv('VOICE_CONTACTS_ENABLED=1');

$fixture = json_decode((string) @file_get_contents((string) (getenv('E2E_FIXTURE_JSON') ?: '/home/user/e2e/run/vm/fixture.json')), true) ?: [];
$kiran = (string) ($fixture['X_company_kiran']['id'] ?? '');
$bala = (string) ($fixture['A_personal_bala']['id'] ?? '');

$passed = 0;
$failed = [];
$ok = static function (bool $condition, string $what) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  ok   {$what}\n";
    } else {
        $failed[] = $what;
        echo "  FAIL {$what}\n";
    }
};

$X = 501;
$client = static fn (string $ses): ContactsClient => (new ContactsClient())->withSession((string) getenv($ses));

echo "Voice -> Contacts conformance (real handlers at " . getenv('E2E_CONTACTS_URL') . ")\n";

// --- G18#2 / G18#3: canonical shape, company directory, a colleague's view ---
$b = $client('E2E_SES_B');
$search = $b->search($X, 'kiran', 1, 20, 'IN');
$ok($search['ok'], 'B (member, not the creator) searches company X');
$row = $search['data'][0] ?? [];
$ok(($row['display_name'] ?? '') === 'Kiran Vendor', 'and sees displayName "Kiran Vendor"');
$ok(($row['phones'][0]['e164'] ?? '') === '+919845098765', 'with the phone from phones[{value}] as E.164');
$ok(($row['scope'] ?? '') === 'company', 'as a company contact');
$ok(($search['meta']['total'] ?? null) === 1, 'meta.total is the real count');

$show = $b->contact($X, $kiran, 'IN');
$ok($show['ok'] && ($show['data']['id'] ?? '') === $kiran, 'B opens the contact A created by its id (a colleague can resolve the contact_ref)');

$personal = $client('E2E_SES_A')->contact($X, $bala, 'IN');
$ok(!$personal['ok'] && in_array($personal['kind'], ['gone', 'not_found'], true), "A's PERSONAL contact is not reachable through the company directory");

// --- G18#4 / §3.4: lookup + matchCount ---------------------------------------
$hit = $b->lookupPhone($X, '+919845098765', 'IN');
$ok($hit['ok'] && ($hit['meta']['matchCount'] ?? null) === 1, 'lookup by E.164 finds exactly one (meta.matchCount = 1)');
$ok(($hit['meta']['attributable'] ?? false) === true, 'and it is attributable (holds that number)');
$miss = $b->lookupPhone($X, '+919845000000', 'IN');
$ok($miss['ok'] && ($miss['meta']['matchCount'] ?? null) === 0 && $miss['data'] === [], 'an unknown number matches nobody — no first-contact fallback');

// --- access: Manage decides -------------------------------------------------
$c = $client('E2E_SES_C')->search($X, '', 1, 5, 'IN');
$ok(!$c['ok'] && $c['kind'] === 'forbidden', 'C (owns another company) is refused for X: forbidden');
$d = $client('E2E_SES_D')->search($X, '', 1, 5, 'IN');
$ok(!$d['ok'] && $d['kind'] === 'forbidden', 'D (removed from X in Manage) is refused: forbidden');

// --- create (G18#7): mapped body, idempotent, role-checked ---------------------
$key = 'voice-conf-' . bin2hex(random_bytes(6));
$name = 'Voice Conformance ' . substr($key, -6);
$made = $client('E2E_SES_A')->create($X, ['display_name' => $name, 'phone' => '+919812300' . random_int(100, 999)], $key, 'IN');
$ok($made['ok'] && ($made['data']['display_name'] ?? '') === $name, 'the owner creates a company contact with displayName/phones mapped');
$again = $client('E2E_SES_A')->create($X, ['display_name' => $name, 'phone' => $made['data']['phones'][0]['value'] ?? ''], $key, 'IN');
$ok($again['ok'] && ($again['data']['id'] ?? '') === ($made['data']['id'] ?? 'x'), 'a retry with the same Idempotency-Key returns the same contact');
$viewer = $b->create($X, ['display_name' => 'Should Not Exist'], 'voice-conf-' . bin2hex(random_bytes(6)), 'IN');
$ok(!$viewer['ok'] && $viewer['kind'] === 'forbidden', 'a viewer (default member role) cannot create: forbidden');

// --- G18#1: a delegation grant issued while the user is present --------------
$grant = $client('E2E_SES_A')->issueDelegation($X, ['contacts.read', 'contacts.lookup'], 600, 'voice.conformance');
$ok($grant['ok'] && str_starts_with((string) ($grant['data']['token'] ?? ''), 'dlg_'), 'A issues a grant for Voice (Bearer + X-AIC-Service headers)');
$token = (string) ($grant['data']['token'] ?? '');
$worker = (new ContactsClient())->withDelegation($token);
$read = $worker->contact($X, $kiran, 'IN');
$ok($read['ok'] && ($read['data']['phones'][0]['e164'] ?? '') === '+919845098765', 'the worker reads the contact through the grant, no session');
$look = $worker->lookupPhone($X, '+919845098765', 'IN');
$ok($look['ok'] && ($look['meta']['matchCount'] ?? null) === 1, 'and can look up a number (contacts.lookup)');
$wrongCmp = $worker->contact(502, $kiran, 'IN');
$ok(!$wrongCmp['ok'] && in_array($wrongCmp['kind'], ['delegation_invalid', 'forbidden'], true), 'the grant is bound to company X (Y refused)');
$write = $worker->create($X, ['display_name' => 'Through A Grant'], 'voice-conf-' . bin2hex(random_bytes(6)), 'IN');
$ok(!$write['ok'] && in_array($write['kind'], ['forbidden', 'delegation_invalid'], true), 'a read grant cannot write');
$revoked = $client('E2E_SES_A')->revokeDelegation($X, (string) ($grant['data']['grantId'] ?? ''));
$ok($revoked['ok'], 'A revokes the grant');
$after = $worker->contact($X, $kiran, 'IN');
$ok(!$after['ok'] && $after['kind'] === 'delegation_invalid', 'a revoked grant is delegation_invalid (401), so the worker must stop, not retry');
$noKey = (new ContactsClient())->withDelegation('dlg_not_a_real_token')->contact($X, $kiran, 'IN');
$ok(!$noKey['ok'] && $noKey['kind'] === 'delegation_invalid', 'a forged token is refused');

echo "\n{$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  - {$f}\n";
}
exit($failed === [] ? 0 : 1);
