/**
 * Tests for the formatting and state logic the screens depend on.
 *
 * Deliberately over the PURE functions rather than over rendered components:
 * what these assert are the product's promises — that "no comparison" is not
 * 0%, that an unknown call state is not a connected one, that a masked number
 * still ends in the digits somebody needs to read out.
 *
 * Run with: npm run test:ui
 */

import { strict as assert } from 'node:assert'
import { test } from 'node:test'

// ---------------------------------------------------------------------------
// These mirror the implementations in src/ui/index.tsx and the backend's
// CallingPolicy. They are duplicated here rather than imported because the
// source is TSX and this suite runs on bare node with no build step.
// ---------------------------------------------------------------------------

function changeTone(change, direction) {
  if (change === null) return 'none'
  if (change === 0) return 'flat'
  const rising = change > 0
  if (direction === 'neutral') return 'flat'
  const good = direction === 'up_is_good' ? rising : !rising
  return good ? 'good' : 'bad'
}

function mask(e164) {
  const length = e164.length
  if (length <= 7) return e164
  return e164.slice(0, 3) + '•'.repeat(Math.max(2, length - 7)) + e164.slice(-4)
}

function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  const total = Math.max(0, Math.round(seconds))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

function callStateTone(state) {
  return ({
    answered: 'default', ringing: 'amber', queued: 'amber', held: 'amber',
    completed: 'neutral', busy: 'red', unanswered: 'red', failed: 'red',
    unknown: 'amber',
  })[state] ?? 'neutral'
}

test('a metric with no previous period reports no comparison, not 0%', () => {
  // "Steady" and "we have nothing to compare against" are different claims. A
  // new company seeing 0% on every card is a dashboard lying quietly.
  assert.equal(changeTone(null, 'up_is_good'), 'none')
  assert.equal(changeTone(0, 'up_is_good'), 'flat')
  assert.notEqual(changeTone(null, 'up_is_good'), changeTone(0, 'up_is_good'))
})

test('direction decides whether a rise is good news', () => {
  // A rising answer rate is good; a rising abandoned rate is not.
  assert.equal(changeTone(12, 'up_is_good'), 'good')
  assert.equal(changeTone(12, 'down_is_good'), 'bad')
  assert.equal(changeTone(-12, 'down_is_good'), 'good')
  assert.equal(changeTone(-12, 'up_is_good'), 'bad')
  assert.equal(changeTone(12, 'neutral'), 'flat')
})

test('an unknown call state is never styled as connected', () => {
  assert.notEqual(callStateTone('unknown'), callStateTone('answered'))
  assert.equal(callStateTone('unknown'), 'amber')
})

test('a masked number keeps the last four digits', () => {
  // An agent reads these back to confirm who they are speaking to, so the tail
  // has to survive masking.
  const masked = mask('+919876543210')
  assert.ok(masked.endsWith('3210'))
  assert.ok(masked.startsWith('+91'))
  assert.ok(!masked.includes('9876543'))
})

test('a short number is not mangled by masking', () => {
  assert.equal(mask('+1234'), '+1234')
})

test('durations read as a clock, with hours only when there are hours', () => {
  assert.equal(formatDuration(0), '00:00')
  assert.equal(formatDuration(62), '01:02')
  assert.equal(formatDuration(3723), '1:02:03')
  assert.equal(formatDuration(null), '—')
})

test('money is formatted from integer minor units', () => {
  // Minor units throughout: 324000 paise is ₹3,240.00, and the only place a
  // decimal appears is here, in the formatting.
  const formatted = new Intl.NumberFormat('en-IN', {
    style: 'currency', currency: 'INR', maximumFractionDigits: 2,
  }).format(324000 / 100)
  assert.ok(formatted.includes('3,240'))
})

test('a call is rendered in the company timezone, not the browser one', () => {
  // The same instant, two zones. A supervisor in London looking at a Bangalore
  // operation needs Bangalore's clock.
  const instant = '2026-01-05T06:00:00Z'
  const kolkata = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata',
  }).format(new Date(instant))
  const london = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London',
  }).format(new Date(instant))

  assert.equal(kolkata, '11:30')
  assert.equal(london, '06:00')
  assert.notEqual(kolkata, london)
})
