<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company and the branch. Voice stores `cmp_id` and `bo_id` as
 * bare references and nothing else — no company name, no branch master, no
 * addresses. A screen that needs the company's name asks here, on that request.
 *
 * This client is also the tenant check: Context::assertAllowed calls
 * companyInfo() to confirm the signed-in session may open the company it
 * claims. When that call fails, the answer is 503 and never "allowed" — the
 * rows behind a Voice company scope are recordings of private conversations.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    /**
     * Company and its branches, in one call.
     *
     * Deliberately one call and memoised for the request: the context check runs
     * on every scoped endpoint, and asking per endpoint would put Manage in the
     * hot path of every screen this product draws.
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request(
            'GET',
            'companyinfo' . self::query(['comp_id' => $cmpId]),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /** Companies this session may open — the company switcher. */
    public function companies(array $filters = []): array
    {
        return $this->request(
            'GET',
            'companies' . self::query($filters),
            null,
            ['Authorization' => $this->authorization],
        );
    }
}
