<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which capabilities this deployment actually has.
 *
 * THE POINT OF THIS CLASS is to make it impossible to ship a placeholder that
 * looks connected. Voice has a real client and a real contract for Calendar,
 * CRM, Pay, Lobby and Messaging — and a flag for each that is OFF until the
 * other side is configured and answering, so the Integrations screen says
 * "Aicountly Pay is not connected" rather than drawing a payments panel full of
 * money nobody collected.
 *
 * A flag is on only when it has been turned on AND the thing it gates is
 * configured. `VOICE_PAY_ENABLED=1` with no `PAY_SERVICE_KEY` is an
 * administrator who meant to finish and did not, and reading it as "on" would
 * put that failure in front of a customer mid-call instead of in front of the
 * administrator in Integrations.
 *
 * TELEPHONY IS NOT A FLAG. Whether this company can place a call depends on
 * whether it has an active, working provider connection — a per-company fact in
 * the database, not a deployment-wide env var. See Telephony\ProviderRegistry.
 * A flag here could only ever lie about it.
 */
final class Features
{
    /**
     * Flag name => the env keys that must be present for it to count as configured.
     *
     * An empty requirement list means the flag alone decides — the capability is
     * ours and needs nothing from another product.
     *
     * @var array<string, list<string>>
     */
    private const REQUIREMENTS = [
        // Voice's own AI, with keys governed centrally by Console.
        'AI'              => ['CONSOLE_API_URL', 'CONSOLE_SERVICE_KEY'],
        // The media/telephony service. Browser calling and streaming speech are
        // its job, not PHP's; with no gateway there is no browser calling and
        // the UI must say so rather than showing a dead Call button.
        'GATEWAY'         => ['VOICE_GATEWAY_URL', 'VOICE_GATEWAY_KEY'],
        'BROWSER_CALLING' => ['VOICE_GATEWAY_URL', 'VOICE_GATEWAY_KEY'],
        'TRANSCRIPTION'   => ['VOICE_GATEWAY_URL', 'VOICE_GATEWAY_KEY'],
        // Voice-owned surfaces.
        'CAMPAIGNS'       => [],
        'INTELLIGENCE'    => [],
        'REALTIME'        => [],
        // Other products.
        'CONTACTS'        => [],
        'CALENDAR'        => ['CALENDAR_SERVICE_KEY'],
        'CRM'             => ['CRM_SERVICE_KEY'],
        'PAY'             => ['PAY_SERVICE_KEY'],
        'LOBBY'           => ['LOBBY_SERVICE_KEY'],
        'MESSAGING'       => ['MESSAGING_SERVICE_KEY'],
        'BILLING'         => ['BILLING_SERVICE_KEY'],
    ];

    /**
     * Flags that are on unless a deployment turns them off.
     *
     * These gate capabilities Voice owns outright, so the only reason to
     * disable one is that a particular business does not want it.
     *
     * CONTACTS is here because it needs no service key — it is read with the
     * signed-in user's own session, which is what makes their Contacts
     * permissions apply rather than Voice re-implementing them.
     *
     * @var list<string>
     */
    private const ON_BY_DEFAULT = ['CAMPAIGNS', 'INTELLIGENCE', 'REALTIME', 'CONTACTS'];

    /** @var array<string, bool>|null */
    private static ?array $memo = null;

    public static function enabled(string $flag): bool
    {
        return self::all()[strtoupper($flag)] ?? false;
    }

    /**
     * Every flag and its state, for the Integrations screen and /api/health.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = [];
        foreach (self::REQUIREMENTS as $flag => $required) {
            $out[$flag] = self::resolve($flag, $required);
        }

        return self::$memo = $out;
    }

    /**
     * Why a flag is off, in words an administrator can act on.
     *
     * Names the env key that is missing, because that is the one thing the
     * person reading the Integrations screen needs and cannot guess. It never
     * names a value.
     */
    public static function explain(string $flag): ?string
    {
        $flag = strtoupper($flag);
        if (self::enabled($flag)) {
            return null;
        }

        $required = self::REQUIREMENTS[$flag] ?? null;
        if ($required === null) {
            return 'Unknown capability.';
        }

        $switch = 'VOICE_' . $flag . '_ENABLED';
        if (!self::switchedOn($flag)) {
            return 'Turned off for this deployment. Set ' . $switch . '=1 in the server environment to enable it.';
        }

        $missing = array_values(array_filter($required, static fn (string $key) => Env::get($key) === ''));
        if ($missing !== []) {
            return $switch . ' is set, but ' . implode(' and ', $missing)
                . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' missing from the server environment.';
        }

        return 'Not available.';
    }

    /** Test seam. CLI only, and it resets rather than accumulating. */
    public static function overrideForTesting(?array $flags): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        if ($flags === null) {
            self::$memo = null;

            return;
        }
        $out = [];
        foreach (array_keys(self::REQUIREMENTS) as $flag) {
            $out[$flag] = (bool) ($flags[$flag] ?? false);
        }
        self::$memo = $out;
    }

    /** @param list<string> $required */
    private static function resolve(string $flag, array $required): bool
    {
        if (!self::switchedOn($flag)) {
            return false;
        }

        foreach ($required as $key) {
            if (Env::get($key) === '') {
                return false;
            }
        }

        return true;
    }

    private static function switchedOn(string $flag): bool
    {
        $raw = strtolower(trim(Env::get('VOICE_' . $flag . '_ENABLED')));

        if ($raw === '') {
            return in_array($flag, self::ON_BY_DEFAULT, true);
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
