/**
 * Nothing on a Voice screen, and nothing an AI agent says, claims a booking or
 * a connection that nothing proved.
 *
 * An agent may say "booked" only with Appointments' own answer in hand; when it
 * cannot book it says so and passes the request to the team (a callback that
 * exists first). The Command Centre says "Connected" only after an
 * authenticated probe, never from a switched-on flag. A rehearsal is simulated
 * and says so, and no rehearsal check passes on a constant. These sentences
 * and rules are pinned the way the callback copy is.
 *
 * Run with: npm run test:ui
 */

import { strict as assert } from 'node:assert'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const web = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(web, path), 'utf8')

const actions = read('../server-php/src/Domain/AiActions.php')
const booking = read('../server-php/src/Domain/AppointmentsBooking.php')
const rehearsal = read('../server-php/src/Domain/AiAgentService.php')
const commandCentre = read('../server-php/src/Dashboards/CommandCentreDashboard.php')
const ui = read('src/ui/index.tsx')
const studio = read('src/dashboards/Studio.tsx')
const centre = read('src/dashboards/CommandCentre.tsx')

test('an agent that cannot book says so, and promises the team only when a callback exists', () => {
  assert.ok(actions.includes(`"I can't book that from this call"`))
  assert.ok(actions.includes(`"; I'll pass your request to the team, and someone will call you back."`))
  assert.ok(actions.includes(`", and I couldn't arrange a call back just now. Please ask for a person, or call us again."`))
})

test('"booked" is said only from a settled Appointments answer', () => {
  // The one sentence that says it, built from Appointments' own reference.
  const said = booking.match(/"You're booked for "/g) ?? []
  assert.equal(said.length, 1)
  assert.ok(/'say' => "You're booked for " \. \$when \. '\. Your booking reference is ' \. \$reference/.test(booking))
  assert.ok(booking.includes(`"I've sent your booking request, but I can't confirm it yet, so please don't book again"`))
})

test('no rehearsal check passes on a constant', () => {
  assert.ok(!/\$check\(\s*'(idempotent_external_writes|no_local_fallback|refusal_recorded)'[^;]*?,\s*true\s*,/s.test(rehearsal))
  assert.ok(rehearsal.includes("self::externalWriteCheck($ctx, $flow, 'duplicate_tool_call')"))
  assert.ok(rehearsal.includes("self::externalWriteCheck($ctx, $flow, 'api_unavailable')"))
})

test('the rehearsal result says it is simulated and not evidence of booking behaviour', () => {
  assert.ok(studio.includes('Simulated — not evidence of booking behaviour on a live call.'))
  assert.ok(studio.includes("run.status === 'not_verified' ? 'Not verified'"))
})

test('Command Centre never says "connected" from a flag alone', () => {
  assert.ok(!commandCentre.includes("$enabled ? 'connected'"))
  assert.ok(commandCentre.includes("'enabled_unverified'"))
  assert.ok(commandCentre.includes("private const VERIFIED_BY_PROBE = ['calendar', 'appointments'];"))
  assert.ok(ui.includes("enabled_unverified: 'Enabled, not verified'"))
  assert.ok(centre.includes('“Enabled, not verified”'))
})
