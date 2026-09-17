<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Features;

/**
 * Live reads and writes against the authoritative party directory.
 *
 * A customer's identity — name, mobile, email — belongs to Contacts. Voice
 * keeps a `contact_ref` on a call and nothing else. There is no contacts table
 * here and there will not be one.
 *
 * ## Two things this product must never do
 *
 * 1. Read a name out of a TRANSCRIPT and treat it as a contact record. A
 *    transcript is evidence of what somebody said. "My name is Priya Sharma"
 *    inside one is a sentence, not a directory entry, and nothing may promote
 *    it to one.
 *
 * 2. Create a contact just because an unknown number rang. A wrong number is
 *    not a new customer. Contacts are created deliberately, by a person or by
 *    an explicitly confirmed action — and they are created THERE, with an
 *    idempotency key, so a double-tap does not produce two people.
 *
 * When Contacts cannot be reached, a call shows its number and says the
 * directory is unavailable. It does not fall back to a local copy, because
 * there is none to fall back to.
 */
final class ContactsClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    public function configured(): bool
    {
        return Features::enabled('CONTACTS');
    }

    /** @param array<string, mixed> $filters */
    public function search(string $term, array $filters = []): array
    {
        return $this->request(
            'GET',
            'contacts' . self::query(['q' => $term, 'limit' => 20] + $filters),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    public function contact(string $contactRef): array
    {
        return $this->request(
            'GET',
            'contacts/' . rawurlencode($contactRef),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /**
     * Who is this number?
     *
     * The caller-identification lookup every inbound call does. It is a READ.
     * A number that matches nobody stays a number — see the class comment.
     */
    public function lookupByPhone(string $e164, array $filters = []): array
    {
        return $this->request(
            'GET',
            'contacts' . self::query(['phone' => $e164, 'limit' => 5] + $filters),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /**
     * Create a contact, when a person has explicitly asked for one.
     *
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', 'contacts', $payload, [
            'Authorization'   => $this->authorization,
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }
}
