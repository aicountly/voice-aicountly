/**
 * company-access 1.3.1+ ships its styles as a static stylesheet. The entry imports it once, after Voice's own
 * CSS, so the build serves it as a same-origin file; while it is applied, the shared screens (Users & Access,
 * the company directory, the restricted-company dialog) inject no <style> element, which a
 * Content-Security-Policy without 'unsafe-inline' refuses. styles.ts knows it is applied by the stylesheet's
 * :root marker.
 *
 *   npm run test:ui
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const SRC = fileURLToPath(new URL('../src/', import.meta.url))
const read = (path) => readFileSync(SRC + path, 'utf8')

describe('company-access styles', () => {
  it('are imported by the entry, once, after the product stylesheet', () => {
    const entry = read('main.tsx')
    const own = entry.indexOf("import './index.css'")
    assert.ok(own >= 0, 'the entry imports index.css')
    assert.ok(entry.indexOf("import './company-access/company-access.css'") > own, 'and company-access.css after it')
    assert.equal(entry.match(/company-access\/company-access\.css/g)?.length, 1, 'exactly once')
  })

  it('carry the marker that tells the package not to inject its <style>', () => {
    assert.ok(read('company-access/company-access.css').includes(':root{--aic-ca-stylesheet:1}'))
    assert.ok(read('company-access/styles.ts').includes("const STYLESHEET_MARKER = '--aic-ca-stylesheet'"))
  })
})
