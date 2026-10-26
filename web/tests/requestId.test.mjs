/**
 * X-Request-Id on every API call, and " (Ref: <id>)" on a 5xx — never a blank error (fleet audit, item 4).
 *
 * Node's own runner; the TypeScript module is loaded with Node's type stripping.
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(fileURLToPath(import.meta.url))
const rid = await import(join(root, '..', 'src', 'services', 'requestId.ts'))

test('a request id is 32 hex characters, fresh each time', () => {
  const a = rid.newRequestId()
  assert.match(a, /^[0-9a-f]{32}$/)
  assert.notEqual(a, rid.newRequestId())
})

test('the reference is the id sent, else the response header, else the body', () => {
  const headers = new Headers({ 'X-Request-Id': 'fromheader123' })
  assert.equal(rid.requestIdOf('sent1234', headers, { request_id: 'frombody123' }), 'sent1234')
  assert.equal(rid.requestIdOf('', headers, { request_id: 'frombody123' }), 'fromheader123')
  assert.equal(rid.requestIdOf('', new Headers(), { request_id: 'frombody123' }), 'frombody123')
  assert.equal(rid.requestIdOf('', null, { error: { details: { request_id: 'nested12345' } } }), 'nested12345')
  assert.equal(rid.requestIdOf('', new Headers({ 'X-Request-Id': 'bad id!' }), null), '')
})

test('a 5xx shows the reference; an empty 5xx body still gets a sentence', () => {
  assert.equal(rid.withRef(500, 'Something went wrong.', 'abc123def'), 'Something went wrong. (Ref: abc123def)')
  const empty = rid.withRef(500, '', 'abc123def')
  assert.match(empty, /unexpected error/)
  assert.match(empty, /\(Ref: abc123def\)$/)
  assert.doesNotMatch(empty, /^Request failed/)
  assert.equal(rid.withRef(503, 'Quote reference abc123def.', 'abc123def'), 'Quote reference abc123def.')
  assert.equal(rid.withRef(404, 'Not found.', 'abc123def'), 'Not found.')
  assert.equal(rid.withRef(422, '', 'abc123def'), 'Request failed (422).')
  assert.doesNotMatch(rid.withRef(500, null, ''), /reference/)
})
