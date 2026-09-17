<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Http;

/**
 * The company switcher.
 *
 * ## Why this is not part of the portal auth relay
 *
 * The portal authenticates people. MANAGE owns companies and branches. Asking
 * the portal for a company list would be asking the wrong product, and the auth
 * relay is an allowlist of three auth paths precisely so it does not grow into
 * a general proxy.
 *
 * So this reads Manage, live, with the signed-in user's own session — which is
 * what makes Manage's access rules apply rather than Voice re-deciding who may
 * see which company.
 *
 * ## Unscoped, necessarily
 *
 * This is the one Voice endpoint with no company scope: you cannot require a
 * company in order to ask which companies there are. Everything it returns is
 * still filtered by Manage against the caller's own session.
 *
 * ## Nothing is stored
 *
 * No company name, no branch, no address is written to the Voice database. The
 * switcher shows what Manage says right now, on this request.
 */
final class CompaniesController extends Controller
{
    public static function index(): never
    {
        $auth = self::enterUnscoped();

        $result = (new ManageClient())->withSession($auth->sesKey())->companies();

        if (!$result['ok']) {
            // Unreachable is not "you have no companies". One is a retry and
            // the other is an administrator's problem, and the app says which.
            self::fail(
                'owner_unavailable',
                'The company service could not be reached, so your companies cannot be listed.',
                ['status' => $result['status']],
            );
        }

        $body = $result['body'] ?? [];
        $rows = $body['data'] ?? $body['companies'] ?? $body;

        $companies = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cmpId = (int) ($row['cmp_id'] ?? $row['comp_id'] ?? $row['id'] ?? 0);
            if ($cmpId <= 0) {
                continue;
            }

            $branches = [];
            foreach ((array) ($row['branches'] ?? []) as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $boId = (int) ($branch['bo_id'] ?? $branch['id'] ?? 0);
                if ($boId > 0) {
                    $branches[] = ['bo_id' => $boId, 'name' => (string) ($branch['name'] ?? 'Branch')];
                }
            }

            $companies[] = [
                'cmp_id'   => $cmpId,
                'name'     => (string) ($row['name'] ?? $row['company_name'] ?? $row['cmp_name'] ?? 'Company'),
                'branches' => $branches,
            ];
        }

        Http::json(200, [
            'data' => $companies,
            'meta' => [
                'total'  => count($companies),
                'source' => 'manage',
                'note'   => 'Read live from Aicountly Manage. Voice stores no company or branch master.',
            ],
        ]);
    }
}
