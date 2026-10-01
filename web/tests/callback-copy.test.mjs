/**
 * The callback screens say exactly what happens to a diary entry.
 *
 * A callback's time is Voice's. A diary entry is a busy block Voice writes in
 * the assigned agent's Aicountly Calendar and then moves or cancels with the
 * callback; it carries no customer detail, cannot be moved from Calendar, and
 * is not a reminder. Copy that says otherwise — that the time is read from
 * Calendar, that moving it there moves it here, that somebody will be
 * reminded — is a promise nothing in Voice keeps, so these sentences are
 * pinned the way the AI-gateway guard pins forbidden hosts.
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

const page = read('src/pages/Callbacks.tsx')
const types = read('src/services/types.ts')
const api = read('../server-php/src/Controllers/CallbacksController.php')

test('no screen or API note claims the time comes from Calendar or follows a move made there', () => {
  for (const [name, text] of [['Callbacks.tsx', page], ['types.ts', types], ['CallbacksController.php', api]]) {
    const lower = text.toLowerCase()
    for (const claim of ['moves it everywhere', 'read from calendar', 'times are read from', 'synced to']) {
      assert.ok(!lower.includes(claim), `${name} still says "${claim}"`)
    }
  }
})

test('the diary option says what it writes, where, and that it is not a reminder', () => {
  assert.ok(page.includes('It is not a reminder: Voice sends none.'))
  assert.ok(page.includes('assigned agent’s'))
  assert.ok(page.includes('cannot be moved from Calendar'))
  assert.ok(page.includes('The phone number, the caller and the reason stay in Voice.'))
  assert.ok(api.includes('Voice sends no reminder.'))
  assert.ok(!/remind (you|them|the agent)|will be reminded|reminder (is|will be) sent/i.test(page))
})

test('the empty queue does not promise callbacks Voice never creates on its own', () => {
  // Nothing in Voice turns an unanswered call into a callback.
  assert.ok(!page.includes('a call goes unanswered'))
})

test('a switched-off Calendar disables the option and says why', () => {
  assert.ok(page.includes('is not connected to Voice in this deployment, so no diary entry can be made'))
  assert.ok(/disabled=\{calendarOff\}/.test(page))
})
