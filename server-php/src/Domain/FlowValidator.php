<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Telephony\Capability;

/**
 * Checks a call flow is executable before anybody's call runs through it.
 *
 * A call flow is not a diagram. It is a graph that a live caller is walked
 * through while they wait on the line, and every defect in it costs somebody
 * real time on a real phone:
 *
 *   - A node with no destination for an outcome drops the call mid-sentence.
 *   - An unreachable node is work somebody did that no caller will ever see.
 *   - A loop with no bound leaves a caller in an IVR that never ends.
 *   - A missing timeout means a caller who says nothing waits forever.
 *   - An action the provider cannot perform fails at the moment it is needed.
 *   - An action the AI agent is not permitted to take is a refusal mid-call.
 *
 * Publishing is refused while any ERROR stands. Warnings are surfaced and do
 * not block, because "no voicemail fallback" is a choice a business may make
 * deliberately.
 */
final class FlowValidator
{
    /** Node types this product can actually execute. */
    public const NODE_TYPES = [
        'greeting', 'disclosure', 'intent', 'knowledge', 'api_action',
        'confirm', 'dtmf_input', 'handover', 'retry', 'voicemail', 'end_call',
    ];

    /** Which capability each executable node needs from the provider. */
    private const NODE_CAPABILITY = [
        'greeting'   => Capability::TTS_PLAYBACK,
        'disclosure' => Capability::TTS_PLAYBACK,
        'knowledge'  => Capability::TTS_PLAYBACK,
        'dtmf_input' => Capability::DTMF,
        'handover'   => Capability::BLIND_TRANSFER,
        'voicemail'  => Capability::RECORDING,
    ];

    /** Nodes that must say what happens when nothing is heard. */
    private const NEEDS_TIMEOUT = ['intent', 'dtmf_input', 'confirm'];

    /** Nodes that end the call, so they need no destination. */
    private const TERMINAL_NODES = ['end_call', 'voicemail', 'handover'];

    /**
     * @param array<string, mixed> $definition
     * @param array<string, bool>  $capabilities from the company's provider adapter
     * @param array<string, string> $actionPermissions AI action => allowed|confirm_with_caller|handoff|denied
     * @return array{valid: bool, errors: list<array<string, string>>, warnings: list<array<string, string>>, checked: int}
     */
    public static function validate(array $definition, array $capabilities = [], array $actionPermissions = []): array
    {
        $errors = [];
        $warnings = [];

        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $entry = (string) ($definition['entry'] ?? '');

        if ($nodes === []) {
            return [
                'valid'    => false,
                'errors'   => [self::issue('empty', '', 'This flow has no steps yet.')],
                'warnings' => [],
                'checked'  => 0,
            ];
        }

        if ($entry === '' || !isset($nodes[$entry])) {
            $errors[] = self::issue('no_entry', $entry, 'The flow does not say which step starts the call.');
        }

        // ---- per-node checks ------------------------------------------------
        foreach ($nodes as $id => $node) {
            $id = (string) $id;
            if (!is_array($node)) {
                $errors[] = self::issue('malformed_node', $id, 'This step is not configured.');
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            if (!in_array($type, self::NODE_TYPES, true)) {
                $errors[] = self::issue('unknown_type', $id, 'Unknown step type "' . $type . '".');
                continue;
            }

            // Destinations must exist and must point somewhere real.
            $next = self::destinations($node);
            if ($next === [] && !in_array($type, self::TERMINAL_NODES, true)) {
                $errors[] = self::issue(
                    'missing_destination',
                    $id,
                    'This step has nowhere to go next, so the call would end here without warning.',
                );
            }
            foreach ($next as $branch => $target) {
                if (!isset($nodes[$target])) {
                    $errors[] = self::issue(
                        'invalid_branch',
                        $id,
                        'The "' . $branch . '" branch points at "' . $target . '", which does not exist.',
                    );
                }
            }

            // A caller who says nothing must not wait forever.
            if (in_array($type, self::NEEDS_TIMEOUT, true)) {
                $timeout = (int) ($node['timeout_seconds'] ?? 0);
                if ($timeout <= 0) {
                    $errors[] = self::issue(
                        'missing_timeout',
                        $id,
                        'This step waits for the caller but never times out.',
                    );
                } elseif (!isset($next['timeout'])) {
                    $errors[] = self::issue(
                        'missing_timeout_branch',
                        $id,
                        'This step times out but does not say what happens then.',
                    );
                }
            }

            // Retry loops must be bounded.
            if ($type === 'retry') {
                $max = (int) ($node['max_attempts'] ?? 0);
                if ($max <= 0 || $max > 5) {
                    $errors[] = self::issue(
                        'unbounded_retry',
                        $id,
                        'Retries must be limited to between 1 and 5 attempts.',
                    );
                }
            }

            // Handover needs somewhere to hand over TO.
            if ($type === 'handover' && trim((string) ($node['destination'] ?? '')) === '') {
                $errors[] = self::issue(
                    'handover_unassigned',
                    $id,
                    'This handover has no destination, so a caller asking for a person would reach nobody.',
                );
            }

            // The provider has to be able to do it.
            $needed = self::NODE_CAPABILITY[$type] ?? null;
            if ($needed !== null && $capabilities !== [] && !($capabilities[$needed] ?? false)) {
                $errors[] = self::issue(
                    'capability_unsupported',
                    $id,
                    'This step needs ' . Capability::describe($needed) . ', which this connection does not support.',
                );
            }

            // The AI agent has to be permitted to do it.
            if ($type === 'api_action') {
                $action = (string) ($node['action'] ?? '');
                if ($action === '') {
                    $errors[] = self::issue('action_unset', $id, 'This step does not say which action to take.');
                } else {
                    $permission = $actionPermissions[$action] ?? 'denied';
                    if ($permission === 'denied') {
                        $errors[] = self::issue(
                            'action_not_permitted',
                            $id,
                            'The agent is not permitted to "' . $action . '". Grant it in Action permissions, or remove this step.',
                        );
                    }
                    // A consequential action with no confirmation step in front
                    // of it is how an AI books or charges without being asked.
                    if ($permission === 'confirm_with_caller' && !self::hasConfirmBefore($nodes, $entry, $id)) {
                        $errors[] = self::issue(
                            'confirmation_missing',
                            $id,
                            '"' . $action . '" needs the caller to confirm first, and no confirmation step comes before it.',
                        );
                    }
                }
            }
        }

        // ---- graph-wide checks ---------------------------------------------
        if ($entry !== '' && isset($nodes[$entry])) {
            $reachable = self::reachable($nodes, $entry);
            foreach (array_keys($nodes) as $id) {
                if (!isset($reachable[(string) $id])) {
                    $warnings[] = self::issue(
                        'unreachable',
                        (string) $id,
                        'No caller can reach this step.',
                    );
                }
            }

            $cycle = self::unboundedCycle($nodes, $entry);
            if ($cycle !== null) {
                $errors[] = self::issue(
                    'unbounded_loop',
                    $cycle,
                    'These steps loop back with nothing to stop them, so a caller could be stuck.',
                );
            }
        }

        // ---- advisory -------------------------------------------------------
        $types = array_map(static fn ($n) => is_array($n) ? (string) ($n['type'] ?? '') : '', $nodes);
        if (!in_array('voicemail', $types, true) && !in_array('handover', $types, true)) {
            $warnings[] = self::issue(
                'no_fallback',
                '',
                'There is no handover or voicemail, so a caller who needs a person has nowhere to go.',
            );
        }
        if (!in_array('disclosure', $types, true)) {
            $warnings[] = self::issue(
                'no_disclosure',
                '',
                'This flow plays no recording or AI disclosure. Check whether your policy requires one.',
            );
        }

        return [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
            'checked'  => count($nodes),
        ];
    }

    /**
     * Every destination a node can go to, keyed by branch name.
     *
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    private static function destinations(array $node): array
    {
        $out = [];
        if (isset($node['next']) && is_string($node['next']) && $node['next'] !== '') {
            $out['next'] = $node['next'];
        }
        if (isset($node['branches']) && is_array($node['branches'])) {
            foreach ($node['branches'] as $branch => $target) {
                if (is_string($target) && $target !== '') {
                    $out[(string) $branch] = $target;
                }
            }
        }
        foreach (['timeout', 'on_failure', 'on_no_match'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                $out[$key] = $node[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $nodes
     * @return array<string, bool>
     */
    private static function reachable(array $nodes, string $entry): array
    {
        $seen = [];
        $stack = [$entry];

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id]) || !isset($nodes[$id]) || !is_array($nodes[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach (self::destinations($nodes[$id]) as $target) {
                if (!isset($seen[$target])) {
                    $stack[] = $target;
                }
            }
        }

        return $seen;
    }

    /**
     * A cycle with no bounded retry and no way out.
     *
     * A loop is legitimate — "ask again" is normal — as long as something ends
     * it. A cycle containing a bounded `retry` node, or a node that can leave
     * the cycle, is fine. One with neither is not.
     *
     * @param array<string, mixed> $nodes
     * @return string|null the node the cycle was found at
     */
    private static function unboundedCycle(array $nodes, string $entry): ?string
    {
        $state = [];   // 0 = unvisited, 1 = on stack, 2 = done
        $found = null;

        $walk = static function (string $id, array $path) use (&$walk, &$state, &$found, $nodes): void {
            if ($found !== null || !isset($nodes[$id]) || !is_array($nodes[$id])) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                // Found a cycle: everything from $id onwards in $path.
                $start = array_search($id, $path, true);
                $cycle = $start === false ? [$id] : array_slice($path, (int) $start);

                foreach ($cycle as $member) {
                    $node = $nodes[$member] ?? null;
                    if (!is_array($node)) {
                        continue;
                    }
                    // A bounded retry inside the cycle ends it.
                    if (($node['type'] ?? '') === 'retry' && (int) ($node['max_attempts'] ?? 0) > 0) {
                        return;
                    }
                    // A branch leaving the cycle ends it.
                    foreach (self::destinations($node) as $target) {
                        if (!in_array($target, $cycle, true)) {
                            return;
                        }
                    }
                }

                $found = $id;

                return;
            }
            if (($state[$id] ?? 0) === 2) {
                return;
            }

            $state[$id] = 1;
            $path[] = $id;
            foreach (self::destinations($nodes[$id]) as $target) {
                $walk($target, $path);
            }
            $state[$id] = 2;
        };

        $walk($entry, []);

        return $found;
    }

    /**
     * Is there a confirmation step on every path from the entry to this node?
     *
     * Conservative: it answers false unless EVERY route to the action passes a
     * `confirm`. One unconfirmed route is one caller who gets booked without
     * being asked.
     *
     * @param array<string, mixed> $nodes
     */
    private static function hasConfirmBefore(array $nodes, string $entry, string $target): bool
    {
        if ($entry === '' || !isset($nodes[$entry])) {
            return false;
        }

        $routes = [];
        $walk = static function (string $id, array $path, bool $confirmed) use (&$walk, &$routes, $nodes, $target): void {
            if (in_array($id, $path, true) || !isset($nodes[$id]) || !is_array($nodes[$id])) {
                return;
            }
            if ($id === $target) {
                $routes[] = $confirmed;

                return;
            }
            $path[] = $id;
            $confirmed = $confirmed || (($nodes[$id]['type'] ?? '') === 'confirm');
            foreach (self::destinations($nodes[$id]) as $next) {
                $walk($next, $path, $confirmed);
            }
        };

        $walk($entry, [], false);

        // No route at all means the node is unreachable, which is reported
        // separately as a warning rather than as a missing confirmation.
        return $routes !== [] && !in_array(false, $routes, true);
    }

    /** @return array<string, string> */
    private static function issue(string $code, string $node, string $message): array
    {
        return ['code' => $code, 'node' => $node, 'message' => $message];
    }
}
