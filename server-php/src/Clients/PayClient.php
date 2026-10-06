<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Features;

/**
 * Aicountly Pay, as far as Voice reaches it today: whether it is up.
 *
 * Pay owns payment links and payment status. Voice never holds a payment
 * object, never mirrors a ledger, and — above all — never decides that a
 * payment succeeded. Money is the one place where "we think it worked" is worth
 * nothing: a call may only tell a customer they have paid once Pay says so.
 *
 * ## Voice asks Pay for nothing else
 *
 * This client used to create, read and look up `payment-links`. Pay has never
 * served those routes: its contract is a payment REQUEST under
 * `v1/payment-requests`, with the link made on the request. Nothing in Voice
 * called the create or the read, and the lookup was reached only by the
 * recovery worker for an operation nothing ever opens (`create_payment_link`
 * has no executor — see Domain\AiActions). So the three calls are removed, not
 * re-pointed: a re-pointed call nothing reaches is still a call nobody has
 * tested against Pay's rules, and an agent must not be able to say "I've sent
 * you a link" on the strength of one.
 *
 * ## What a Pay call from Voice must be when one is added
 *
 * Pay admits a product's service key on a short route list only (Pay's
 * `ServiceAccess::ROUTES`), and only for a company that allowed Voice under
 * Pay's Settings → Connected apps. Every call
 * carries ALL of: `X-Service-Key`, `X-AIC-Environment` (the deployment it is
 * meant for), `X-Actor-Uuid` and the person's own session in
 * `X-Actor-Session`. A key proves which product is calling and nothing about
 * who is acting; contact access never authorises money movement, and a service
 * key can never request, approve or reject a refund or record money as
 * collected. Do not send a key with an actor id alone.
 */
final class PayClient extends ApiClient
{
    public function service(): string
    {
        return 'pay';
    }

    protected function productionBase(): string
    {
        return 'https://pay.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pay.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PAY_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('PAY');
    }

    public function health(): array
    {
        return $this->request('GET', 'health', null, []);
    }
}
