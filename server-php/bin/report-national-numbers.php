<?php

declare(strict_types=1);

/**
 * READ-ONLY report: stored numbers that were probably national numbers given
 * a bare '+' by the old normaliser (G18#6).
 *
 *   php server-php/bin/report-national-numbers.php [--cmp=<cmp_id>]
 *
 * The old rule turned the Indian mobile 9876543210 into +9876543210. This
 * reads every stored E.164 in voice_suppressions, voice_callbacks, voice_calls
 * (remote_e164) and voice_campaign_attempts (dialled_e164), and flags a value
 * when its digits, read as a NATIONAL number in the company's region, give a
 * different valid E.164 — i.e. the stored value is what the old rule would
 * have produced from that national number.
 *
 * It changes nothing and has no --apply. A flagged row is a candidate for a
 * person to review: "+9876543210" may really be an Iranian number somebody
 * typed with a '+'. Numbers are printed masked; counts are per company.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\CallingPolicy;
use Aicountly\Api\Support\PhoneNumber;

require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$onlyCmp = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--cmp=')) {
        $onlyCmp = (int) substr($arg, strlen('--cmp='));
    } elseif ($arg === '--apply') {
        fwrite(STDERR, "This report has no --apply: it only lists candidates for a person to review.\n");
        exit(2);
    }
}

$sources = [
    'voice_suppressions'      => 'e164',
    'voice_callbacks'         => 'e164',
    'voice_calls'             => 'remote_e164',
    'voice_campaign_attempts' => 'dialled_e164',
];

$flagged = [];
$scanned = 0;

foreach ($sources as $table => $column) {
    $rows = Db::all(
        "SELECT cmp_id, {$column} AS value FROM {$table}
          WHERE {$column} IS NOT NULL" . ($onlyCmp > 0 ? ' AND cmp_id = :cmp' : '') . '
          ORDER BY cmp_id',
        $onlyCmp > 0 ? ['cmp' => $onlyCmp] : [],
    );

    foreach ($rows as $row) {
        $scanned++;
        $value = (string) $row['value'];
        $cmpId = (int) $row['cmp_id'];
        $region = CallingPolicy::regionFor(Context::forCompany($cmpId));

        $digits = ltrim($value, '+');
        $asNational = PhoneNumber::toE164($digits, $region);

        if ($asNational !== null && $asNational !== $value) {
            $key = $cmpId . ' ' . $table . '.' . $column;
            $flagged[$key] ??= ['count' => 0, 'samples' => []];
            $flagged[$key]['count']++;
            if (count($flagged[$key]['samples']) < 3) {
                $flagged[$key]['samples'][] = CallingPolicy::mask($value) . ' -> ' . CallingPolicy::mask($asNational);
            }
        }
    }
}

echo "scanned {$scanned} stored number(s); dry run, nothing changed\n";
if ($flagged === []) {
    echo "no candidates\n";
    exit(0);
}
foreach ($flagged as $key => $info) {
    printf("cmp=%s  candidates=%d  e.g. %s\n", $key, $info['count'], implode(', ', $info['samples']));
}
