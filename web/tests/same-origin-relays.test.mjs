/**
 * The browser calls only this product's own API — never manage.aicountly.com or my.aicountly.com.
 *
 * Production hosting's bot shield answers distrusted IPs (common Indian CGNAT broadband) with a
 * challenge page that has no CORS headers, so a cross-site call fails with no response at all;
 * a same-origin call to /api passes. The server relays: Manage's company registry through
 * /api/manage/v1/* (server-php/src/ManageRelay.php) and the portal's logout through
 * /api/global/logout.
 *
 * The shipped modules are loaded through Vite's SSR module graph (extensionless imports,
 * import.meta.env, the .tsx panels behind the registry client), under a minimal fake browser.
 *
 * Run with: npm run test:ui
 */
import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const ORIGIN = 'https://voice.aicountly.com'

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => data.clear(),
  }
}

const calls = []
const replaced = []
const url = new URL(`${ORIGIN}/`)
const g = globalThis
const fakeWindow = {
  location: {
    href: url.href,
    origin: url.origin,
    protocol: url.protocol,
    host: url.host,
    hostname: url.hostname,
    pathname: '/',
    search: '',
    hash: '',
    replace: (to) => void replaced.push(String(to)),
  },
  history: { replaceState: () => {} },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
}
g.window = fakeWindow
g.localStorage = fakeWindow.localStorage
g.sessionStorage = fakeWindow.sessionStorage
g.document = { cookie: '' }
const realFetch = globalThis.fetch
globalThis.fetch = async (input, init = {}) => {
  calls.push({ url: String(input), init })
  return new Response('{"data":[]}', { status: 200, headers: { 'Content-Type': 'application/json' } })
}

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const registry = await server.ssrLoadModule('/src/services/companyRegistry.ts')
const portal = await server.ssrLoadModule('/src/auth/portal.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
})

beforeEach(() => {
  calls.length = 0
  replaced.length = 0
  tokens.clearAllTokens()
  fakeWindow.sessionStorage.clear()
  fakeWindow.localStorage.clear()
})

describe('same-origin relays', () => {
  it("builds the company registry client on this product's own API", () => {
    assert.equal(registry.companyRegistry.baseUrl, `${ORIGIN}/api/manage`)
  })

  it('sends a registry call to the own API with the person ses_key', async () => {
    tokens.saveSession('ses-key-1', 900)
    await registry.companyRegistry.workspaces()
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, `${ORIGIN}/api/manage/v1/workspaces`)
    assert.equal(new Headers(calls[0].init.headers).get('Authorization'), 'Bearer ses-key-1')
  })

  it('tells the portal about a sign-out through the /global/logout relay, fire-and-forget', () => {
    tokens.setAuthToken('auth-token-1')
    portal.performLogout()
    const logout = calls.filter((c) => c.url.includes('logout'))
    assert.equal(logout.length, 1)
    assert.equal(logout[0].url, `${ORIGIN}/api/global/logout`)
    assert.equal(logout[0].init.method, 'POST')
    assert.equal(logout[0].init.keepalive, true)
    assert.equal(new Headers(logout[0].init.headers).get('Authorization'), 'Bearer auth-token-1')
    assert.ok(!calls.some((c) => c.url.startsWith('https://my.aicountly.com')), 'no cross-site call to the portal')
    assert.ok(replaced.some((to) => to.includes('/login/logout')), 'the portal logout page still opens')
  })
})
