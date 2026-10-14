/**
 * Manage's company lifecycle in the browser: the 423 COMPANY_RESTRICTED answer and companyinfo's
 * `lifecycle` both reach the read-only banner (docs/COMPANY_LIFECYCLE.md).
 *
 *   npm run test:ui
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it, beforeEach } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
  __resetCompanyRestrictionsForTests,
  getCompanyRestriction,
  noteCompanyLifecycle,
  noteRestrictedResponse,
  restrictionFromResponse,
  restrictionMessage,
} from '../src/services/companyRestriction.ts'

const SRC = fileURLToPath(new URL('../src/', import.meta.url))
const read = (path) => readFileSync(SRC + path, 'utf8')

/** What the API sends (lib/company-lifecycle ReadOnlyGuard::payload). */
const refused = {
  code: 'COMPANY_RESTRICTED',
  message: 'This company is read-only until 28 Nov 2026 because its subscription ended. Renew to make changes, or export your data.',
  restricted_until: '2026-11-28T00:00:00Z',
  lifecycle: 'restricted',
  error: { code: 'COMPANY_RESTRICTED', message: 'm', restricted_until: '2026-11-28T00:00:00Z' },
}

describe('company lifecycle', () => {
  beforeEach(() => __resetCompanyRestrictionsForTests())

  it('reads the 423 the API sends, with the server sentence and date', () => {
    const r = restrictionFromResponse(423, refused)
    assert.equal(r.message, refused.message)
    assert.equal(r.restrictedUntil, '2026-11-28T00:00:00Z')
  })

  it('ignores anything that is not a 423 COMPANY_RESTRICTED', () => {
    assert.equal(restrictionFromResponse(403, refused), null)
    assert.equal(restrictionFromResponse(423, { error: { code: 'locked' } }), null)
    assert.equal(noteRestrictedResponse(7, 409, { error: { code: 'conflict' } }), false)
    assert.equal(getCompanyRestriction(7), null)
  })

  it('records a refused write for the company it was made in', () => {
    assert.equal(noteRestrictedResponse(7, 423, refused), true)
    assert.equal(getCompanyRestriction(7).restrictedUntil, '2026-11-28T00:00:00Z')
    assert.equal(getCompanyRestriction(8), null)
  })

  it('reads companyinfo: restricted and purge_pending show, active clears', () => {
    noteCompanyLifecycle(7, { success: '1', data: { comp_id: 7, lifecycle: 'purge_pending', restricted_until: '2026-11-28T00:00:00Z' } })
    assert.equal(getCompanyRestriction(7).lifecycle, 'purge_pending')
    noteCompanyLifecycle(7, { data: { comp_id: 7, lifecycle: 'active' } })
    assert.equal(getCompanyRestriction(7), null)
    noteCompanyLifecycle(7, { data: { comp_id: 7 } })
    assert.equal(getCompanyRestriction(7), null)
  })

  it('rebuilds the sentence when only the date is known', () => {
    assert.match(restrictionMessage('2026-11-28T00:00:00Z'), /read-only until 28 Nov 2026/)
    assert.match(restrictionMessage(null, 'purge_pending'), /Export what you need now/)
  })

  it('every API failure passes through the recorder, and the banner sits above every screen', () => {
    assert.match(read('services/api.ts'), /noteRestrictedResponse\(/)
    assert.match(read('shell/AppShell.tsx'), /<CompanyRestrictedBanner cmpId=/)
    assert.match(read('context/VoiceContext.tsx'), /noteCompanyLifecycle\(/)
  })
})
