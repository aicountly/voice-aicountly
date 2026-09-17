<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Live reads and writes against Aicountly CRM.
 *
 * CRM owns leads, deals and CRM tasks. Voice holds `crm_lead_ref` on a call and
 * `external_task_ref` on a confirmed commitment. Nothing else.
 *
 * The commitment ledger is where this rule earns its keep. Conversation
 * Intelligence finds "I'll send the revised quotation by Friday" in a
 * transcript. That is a SUGGESTION with a transcript segment attached — not a
 * task, and not a task that exists anywhere. It becomes a CRM task only when a
 * person confirms it, at which point CRM creates the task, returns its id, and
 * Voice stores the id. The task's text, owner and due date live in CRM from
 * then on; if somebody reassigns it there, Voice shows the change because it
 * reads it, not because something copied it across.
 */
final class CrmClient extends ApiClient
{
    private string $actorUuid = '';
    private string $sesKey = '';

    public function service(): string
    {
        return 'crm';
    }

    protected function productionBase(): string
    {
        return 'https://crm.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://crm.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CRM_API_BASE';
    }

    public function forActor(string $actorUuid): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($actorUuid);

        return $clone;
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('CRM');
    }

    public function lead(string $leadRef): array
    {
        return $this->request('GET', 'leads/' . rawurlencode($leadRef), null, $this->headers());
    }

    /** @param array<string, mixed> $filters */
    public function leadsForContact(string $contactRef, array $filters = []): array
    {
        return $this->request(
            'GET',
            'leads' . self::query(['contact_ref' => $contactRef, 'limit' => 10] + $filters),
            null,
            $this->headers(),
        );
    }

    /**
     * Create a follow-up task from a confirmed commitment.
     *
     * @param array<string, mixed> $payload
     */
    public function createTask(array $payload, string $correlationId): array
    {
        return $this->request('POST', 'tasks', $payload, $this->headers([
            'Idempotency-Key' => $correlationId,
        ]), true);
    }

    public function task(string $taskRef): array
    {
        return $this->request('GET', 'tasks/' . rawurlencode($taskRef), null, $this->headers());
    }

    /** The reconciliation read, for a createTask whose outcome we did not learn. */
    public function findTaskByCorrelation(string $correlationId): array
    {
        return $this->request(
            'GET',
            'tasks' . self::query(['correlation_id' => $correlationId, 'limit' => 1]),
            null,
            $this->headers(),
        );
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        $key = Env::get('CRM_SERVICE_KEY');
        if ($key !== '' && $this->actorUuid !== '') {
            return $extra + ['X-Service-Key' => $key, 'X-Actor-Uuid' => $this->actorUuid];
        }
        if ($this->sesKey !== '') {
            return $extra + ['Authorization' => 'Bearer ' . $this->sesKey];
        }

        return $extra;
    }
}
