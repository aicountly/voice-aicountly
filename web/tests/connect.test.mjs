/**
 * AICOUNTLY Connect in Voice, without a browser.
 *
 * The shipped modules are loaded through Vite's SSR module graph (as in
 * auth.test.mjs), under a minimal fake browser whose document head collects
 * the <script> the loader adds instead of fetching it. Mirrors the reference
 * consumers' unit tests (sales-aicountly and notes-aicountly web/src/connect/):
 *
 *   - loader.js comes from the right origin, once, and a failure, a hang or a
 *     loader that did not register all resolve null with one warning at most;
 *   - the widget is started with the product code and the company Voice
 *     works in, a change made while loader.js loads goes into init(), a
 *     sign-out before it arrived never mounts it, and an init() slower than
 *     15 s is given up on (and taken down if it lands later);
 *   - signing out destroys the widget before the portal logout runs;
 *   - the ses_key handed to the widget is the one Voice holds, a forced
 *     refresh keeps it until the new one arrives, and every failure is null —
 *     never a throw, never a redirect.
 *
 * Mounting in the shell, following a company switch and the launcher on
 * screen need a real page; they were checked with Playwright against the
 * built app and a stand-in loader.js (the change's commit message says how).
 *
 * Run with: npm run test:ui
 */

import { after, beforeEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

// ---------------------------------------------------------------------------
// A fake browser
// ---------------------------------------------------------------------------

const location = {}
function setLocation(href) {
  const url = new URL(href)
  Object.assign(location, {
    href: url.href,
    origin: url.origin,
    protocol: url.protocol,
    host: url.host,
    hostname: url.hostname,
    pathname: url.pathname,
    search: url.search,
    hash: url.hash,
  })
}

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => data.clear(),
  }
}

/** Every page the app tried to leave for, and every init/destroy, in order. */
let journal = []
/** Scripts the loader appended — collected, so nothing is fetched. */
let appended = []
let warnings = []

location.replace = (url) => void journal.push(`navigate ${url}`)
location.assign = (url) => void journal.push(`navigate ${url}`)
setLocation('https://voice.aicountly.com/live')

globalThis.window = {
  location,
  history: { replaceState: (_state, _title, url) => setLocation(new URL(url, location.href).href) },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
}
globalThis.sessionStorage = globalThis.window.sessionStorage
globalThis.localStorage = globalThis.window.localStorage

const head = { appendChild: (node) => (appended.push(node), node) }
globalThis.document = {
  head,
  documentElement: head,
  cookie: '',
  createElement(tag) {
    const attributes = {}
    return {
      tagName: String(tag).toUpperCase(),
      src: '',
      async: false,
      onload: null,
      onerror: null,
      setAttribute: (name, value) => void (attributes[name] = String(value)),
      getAttribute: (name) => attributes[name] ?? null,
    }
  },
}

const realWarn = console.warn
console.warn = (...args) => void warnings.push(args.join(' '))

const realFetch = globalThis.fetch
let fetchCalls = []
let answerFetch = async () => new Response('{}', { status: 200 })
globalThis.fetch = (url, init) => {
  fetchCalls.push({ url: String(url), init })
  return answerFetch(url, init)
}

function minted(key) {
  return new Response(JSON.stringify({ ses_key: key, expires_in: 900 }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** Lets pending promise callbacks run. */
async function settle(rounds = 5) {
  for (let i = 0; i < rounds; i += 1) await Promise.resolve()
}

/** A clock the test moves by hand, for the load and init() limits. */
function fakeClock() {
  const realSetTimeout = globalThis.setTimeout
  const realClearTimeout = globalThis.clearTimeout
  let now = 0
  let nextId = 1
  const timers = new Map()
  globalThis.setTimeout = (fn, ms = 0) => {
    const id = nextId++
    timers.set(id, { at: now + Number(ms), fn })
    return id
  }
  globalThis.clearTimeout = (id) => void timers.delete(id)
  return {
    async advance(ms) {
      now += ms
      for (const [id, timer] of [...timers].sort((a, b) => a[1].at - b[1].at)) {
        if (timer.at > now) continue
        timers.delete(id)
        timer.fn()
        await settle()
      }
      await settle()
    },
    restore() {
      globalThis.setTimeout = realSetTimeout
      globalThis.clearTimeout = realClearTimeout
    },
  }
}

function fakeApi({ mounts = true } = {}) {
  const calls = { init: [], update: [], open: [], close: 0, destroy: 0 }
  return {
    version: '1',
    calls,
    init: async (options) => {
      calls.init.push(options)
      journal.push('init')
      return mounts
    },
    update: (patch) => void calls.update.push(patch),
    open: (request) => void calls.open.push(request),
    close: () => void (calls.close += 1),
    destroy: () => {
      calls.destroy += 1
      journal.push('destroy')
    },
    isReady: () => mounts,
  }
}

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const loader = await server.ssrLoadModule('/src/connect/loader.ts')
const connect = await server.ssrLoadModule('/src/connect/connect.ts')
const portal = await server.ssrLoadModule('/src/auth/portal.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')
const { AuthProvider, useAuth } = await server.ssrLoadModule('/src/auth/AuthProvider.tsx')
const React = await import('react')
const { renderToString } = await import('react-dom/server')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
  console.warn = realWarn
})

beforeEach(() => {
  connect.stopConnect()
  loader.resetConnectLoader()
  delete globalThis.window.AICountlyChat
  delete globalThis.window.AICountlyConnect
  tokens.clearAllTokens()
  sessionStorage.clear()
  localStorage.clear()
  journal = []
  appended = []
  warnings = []
  fetchCalls = []
  answerFetch = async () => new Response('{}', { status: 200 })
  setLocation('https://voice.aicountly.com/live')
})

const ORIGIN = 'https://connect.example.test'

// ---------------------------------------------------------------------------
// Where Connect comes from
// ---------------------------------------------------------------------------

test('derives Connect from the host, like every sibling product', () => {
  assert.equal(loader.resolveConnectOrigin('voice.aicountly.com', undefined), 'https://connect.aicountly.com')
  assert.equal(loader.resolveConnectOrigin('voice.gh.aicountly.com', undefined), 'https://connect.gh.aicountly.com')
  assert.equal(loader.resolveConnectOrigin('localhost', ''), 'https://connect.gh.aicountly.com')
})

test('takes VITE_CONNECT_ORIGIN as an origin only, and "off" switches it off', () => {
  assert.equal(loader.resolveConnectOrigin('voice.aicountly.com', 'http://localhost:5174/some/path'), 'http://localhost:5174')
  assert.equal(loader.resolveConnectOrigin('voice.aicountly.com', 'off'), null)
  assert.equal(loader.resolveConnectOrigin('voice.aicountly.com', ' OFF '), null)
  assert.equal(loader.resolveConnectOrigin('voice.aicountly.com', 'javascript:alert(1)'), 'https://connect.aicountly.com')
  assert.equal(loader.originOf('data:text/javascript,1'), null)
})

// ---------------------------------------------------------------------------
// Loading loader.js
// ---------------------------------------------------------------------------

test('adds one async script for loader.js and resolves with the API it registers', async () => {
  const pending = loader.loadConnect(ORIGIN)
  assert.equal(appended.length, 1)
  assert.equal(appended[0].src, `${ORIGIN}${loader.LOADER_PATH}`)
  assert.equal(appended[0].async, true)
  assert.equal(appended[0].getAttribute('data-aicountly-connect'), 'loader')

  const api = fakeApi()
  window.AICountlyChat = api
  appended[0].onload()

  assert.equal(await pending, api)
  // Asked again (a second mount, a StrictMode re-run): the same answer, no second tag.
  assert.equal(await loader.loadConnect(ORIGIN), api)
  assert.equal(appended.length, 1)
  assert.deepEqual(warnings, [])
})

test('reads the AICountlyConnect alias too', () => {
  const api = fakeApi()
  window.AICountlyConnect = api
  assert.equal(loader.readConnectGlobal(), api)
})

test('resolves null when the script fails to load, and warns once however often it is asked', async () => {
  const pending = loader.loadConnect(ORIGIN)
  appended[0].onerror()

  assert.equal(await pending, null)
  assert.equal(await loader.loadConnect(ORIGIN), null)
  assert.equal(await loader.loadConnect(ORIGIN), null)
  assert.equal(appended.length, 1)
  assert.equal(warnings.length, 1)
  assert.match(warnings[0], /^\[Voice\] .*Voice carries on without it/)
})

test('resolves null when loader.js loads but registers nothing', async () => {
  const pending = loader.loadConnect(ORIGIN)
  appended[0].onload()
  assert.equal(await pending, null)
})

test('gives up after the timeout: a hanging Connect is a Connect that is not there', async () => {
  const clock = fakeClock()
  try {
    const pending = loader.loadConnect(ORIGIN, 5_000)
    await clock.advance(5_000)
    assert.equal(await pending, null)

    // A late arrival changes nothing: the answer was given.
    window.AICountlyChat = fakeApi()
    appended[0].onload()
    assert.equal(await loader.loadConnect(ORIGIN), null)
  } finally {
    clock.restore()
  }
})

test('loads nothing without an origin', async () => {
  assert.equal(await loader.loadConnect(null), null)
  assert.equal(appended.length, 0)
})

// ---------------------------------------------------------------------------
// The company scope
// ---------------------------------------------------------------------------

test('the scope is the company Voice works in and its branch, with no financial year', () => {
  assert.deepEqual(connect.connectScopeFrom({ cmp_id: 77, name: 'Acme', bo_id: 0 }, 2), { companyId: 77, fyId: null, boId: 2 })
  assert.deepEqual(connect.connectScopeFrom({ cmp_id: '77' }, '0'), { companyId: 77, fyId: null, boId: 0 })
  assert.deepEqual(connect.connectScopeFrom({ cmp_id: 77 }, undefined), { companyId: 77, fyId: null, boId: 0 })
  assert.deepEqual(connect.connectScopeFrom({ cmp_id: 77 }, -3), { companyId: 77, fyId: null, boId: 0 })
})

test('no company, or no real one, is no company — every key still present', () => {
  for (const company of [null, undefined, {}, { cmp_id: 0 }, { cmp_id: -4 }, { cmp_id: 'x' }, { cmp_id: 1.5 }]) {
    assert.deepEqual(connect.connectScopeFrom(company, 3), { companyId: null, fyId: null, boId: 0 }, JSON.stringify(company))
  }
})

// ---------------------------------------------------------------------------
// The widget's lifecycle
// ---------------------------------------------------------------------------

test("mounts with the person's session, product 'voice' and the company scope", async () => {
  const api = fakeApi()
  window.AICountlyChat = api
  const getSesKey = async () => 'ses-key'

  const started = await connect.startConnect(ORIGIN, {
    getSesKey,
    product: connect.CONNECT_PRODUCT,
    ...connect.connectScopeFrom({ cmp_id: 77 }, 2),
    position: 'bottom-right',
    zIndex: 50,
  })
  assert.equal(started, true)
  assert.equal(api.calls.init.length, 1)
  const options = api.calls.init[0]
  assert.equal(options.getSesKey, getSesKey)
  assert.equal(options.product, 'voice')
  assert.equal(options.companyId, 77)
  assert.equal(options.fyId, null)
  assert.equal(options.boId, 2)
  assert.equal(connect.getConnectStatus(), 'ready')
})

test('passes a company or branch change on with update()', async () => {
  const api = fakeApi()
  window.AICountlyChat = api
  await connect.startConnect(ORIGIN, { getSesKey: async () => null, ...connect.connectScopeFrom({ cmp_id: 77 }, 0) })

  connect.updateConnect(connect.connectScopeFrom({ cmp_id: 78 }, 4))
  connect.updateConnect(connect.connectScopeFrom(null, 0))
  assert.deepEqual(api.calls.update, [
    { companyId: 78, fyId: null, boId: 4 },
    { companyId: null, fyId: null, boId: 0 },
  ])
})

test('a company chosen while loader.js is still loading goes into init()', async () => {
  const start = connect.startConnect(ORIGIN, { getSesKey: async () => null, product: 'voice', companyId: null })
  connect.updateConnect(connect.connectScopeFrom({ cmp_id: 12 }, 3))
  const api = fakeApi()
  window.AICountlyChat = api
  appended[0].onload()

  assert.equal(await start, true)
  assert.equal(api.calls.init[0].companyId, 12)
  assert.equal(api.calls.init[0].boId, 3)
  assert.deepEqual(api.calls.update, [])
})

test('destroys the widget when stopped (sign-out, the shell unmounting)', async () => {
  const api = fakeApi()
  window.AICountlyChat = api
  await connect.startConnect(ORIGIN, { getSesKey: async () => null })

  connect.stopConnect()
  assert.equal(api.calls.destroy, 1)
  assert.equal(connect.getConnectStatus(), 'off')
  connect.stopConnect()
  assert.equal(api.calls.destroy, 1)
})

test('a sign-out before Connect arrived never mounts it', async () => {
  const start = connect.startConnect(ORIGIN, { getSesKey: async () => null })
  connect.stopConnect()
  const api = fakeApi()
  window.AICountlyChat = api
  appended[0].onload()

  assert.equal(await start, false)
  assert.equal(api.calls.init.length, 0)
  assert.equal(connect.getConnectStatus(), 'off')
})

test('a widget whose init() lands after 15 s is given up on, then taken down, not left orphaned', async () => {
  assert.equal(connect.INIT_TIMEOUT_MS, 15_000)
  const api = fakeApi()
  let finishInit = () => undefined
  api.init = () => new Promise((resolve) => (finishInit = resolve))
  window.AICountlyChat = api

  const clock = fakeClock()
  try {
    const start = connect.startConnect(ORIGIN, { getSesKey: async () => null })
    await settle()
    await clock.advance(connect.INIT_TIMEOUT_MS - 1)
    assert.equal(connect.getConnectStatus(), 'loading')
    await clock.advance(1)
    assert.equal(await start, false)
    assert.equal(connect.getConnectStatus(), 'unavailable')

    finishInit(true)
    await settle()
    assert.equal(api.calls.destroy, 1)
  } finally {
    clock.restore()
  }
})

test('Connect unreachable: unavailable, nothing thrown, every later call a no-op', async () => {
  const start = connect.startConnect(ORIGIN, { getSesKey: async () => null })
  appended[0].onerror()
  assert.equal(await start, false)
  assert.equal(connect.getConnectStatus(), 'unavailable')
  assert.doesNotThrow(() => connect.updateConnect(connect.connectScopeFrom({ cmp_id: 5 }, 0)))
  assert.doesNotThrow(() => connect.closeConnect())
  assert.doesNotThrow(() => connect.stopConnect())
  assert.equal(warnings.length, 1)
})

test('no origin (VITE_CONNECT_ORIGIN=off): unavailable without adding a script', async () => {
  assert.equal(await connect.startConnect(null, { getSesKey: async () => null }), false)
  assert.equal(connect.getConnectStatus(), 'unavailable')
  assert.equal(appended.length, 0)
})

test('an init() that resolves false, or throws, is unavailable — not an error in Voice', async () => {
  window.AICountlyChat = fakeApi({ mounts: false })
  assert.equal(await connect.startConnect(ORIGIN, { getSesKey: async () => null }), false)
  assert.equal(connect.getConnectStatus(), 'unavailable')

  connect.stopConnect()
  const broken = fakeApi()
  broken.init = () => {
    throw new Error('widget broke')
  }
  window.AICountlyChat = broken
  assert.equal(await connect.startConnect(ORIGIN, { getSesKey: async () => null }), false)
  assert.equal(connect.getConnectStatus(), 'unavailable')
})

test('a widget that throws from update(), close() or destroy() never throws into Voice', async () => {
  const api = fakeApi()
  api.update = api.close = api.destroy = () => {
    throw new Error('widget broke')
  }
  window.AICountlyChat = api
  await connect.startConnect(ORIGIN, { getSesKey: async () => null })
  assert.doesNotThrow(() => connect.updateConnect({ companyId: 5 }))
  assert.doesNotThrow(() => connect.closeConnect())
  assert.doesNotThrow(() => connect.stopConnect())
})

// ---------------------------------------------------------------------------
// Signing out
// ---------------------------------------------------------------------------

test('signing out destroys the widget before the portal logout runs', async () => {
  let auth = null
  function Probe() {
    auth = useAuth()
    return null
  }
  renderToString(React.createElement(AuthProvider, null, React.createElement(Probe)))
  assert.ok(auth, 'the provider rendered')

  tokens.setAuthToken('auth-token')
  const api = fakeApi()
  window.AICountlyChat = api
  await connect.startConnect(ORIGIN, { getSesKey: portal.sesKeyForEmbed })
  journal = []

  auth.signOut()

  assert.deepEqual(journal, ['destroy', 'navigate https://my.aicountly.com/login/logout'])
  assert.equal(connect.getConnectStatus(), 'off')
  assert.equal(await portal.sesKeyForEmbed(false), null)
})

// ---------------------------------------------------------------------------
// The ses_key handed to the widget
// ---------------------------------------------------------------------------

test('sesKeyForEmbed: null when nobody is signed in, without asking the portal', async () => {
  assert.equal(await portal.sesKeyForEmbed(false), null)
  assert.equal(await portal.sesKeyForEmbed(true), null)
  assert.equal(fetchCalls.length, 0)
})

test('sesKeyForEmbed: returns the key Voice already holds', async () => {
  tokens.setAuthToken('auth-token')
  tokens.saveSession('held-key', 900)
  assert.equal(await portal.sesKeyForEmbed(false), 'held-key')
  assert.equal(fetchCalls.length, 0)
})

test("sesKeyForEmbed: mints a new key through this product's relay when the widget asks for a refresh", async () => {
  tokens.setAuthToken('auth-token')
  tokens.saveSession('stale-key', 900)
  answerFetch = async () => minted('fresh-key')

  assert.equal(await portal.sesKeyForEmbed(true), 'fresh-key')
  assert.equal(fetchCalls.length, 1)
  assert.equal(fetchCalls[0].url, 'https://voice.aicountly.com/api/global/seskey')
  assert.equal(fetchCalls[0].init.headers.Authorization, 'Bearer auth-token')
  assert.equal(tokens.getSesKey(), 'fresh-key')
})

test('sesKeyForEmbed: keeps the held key until the new one arrives, with one mint for concurrent refreshes', async () => {
  tokens.setAuthToken('auth-token')
  tokens.saveSession('held-key', 900)
  let answer = () => undefined
  answerFetch = () => new Promise((resolve) => (answer = resolve))

  const first = portal.sesKeyForEmbed(true)
  const second = portal.sesKeyForEmbed(true)
  await settle()
  const whileMinting = tokens.getSesKey()
  // Voice's own request meanwhile: answered from the held key, not made to wait for the mint.
  const ownRequest = portal.ensureSesKey()
  answer(minted('fresh-key'))

  assert.equal(await first, 'fresh-key')
  assert.equal(await second, 'fresh-key')
  assert.equal(whileMinting, 'held-key')
  assert.equal(await ownRequest, 'held-key')
  assert.equal(fetchCalls.length, 1)
  assert.equal(tokens.getSesKey(), 'fresh-key')
})

test('sesKeyForEmbed: a refused or unreachable refresh is null and leaves the held key in place, never navigating', async () => {
  tokens.setAuthToken('auth-token')
  tokens.saveSession('held-key', 900)

  answerFetch = async () => new Response('nope', { status: 401 })
  assert.equal(await portal.sesKeyForEmbed(true), null)
  assert.equal(tokens.getSesKey(), 'held-key')

  answerFetch = async () => {
    throw new TypeError('Failed to fetch')
  }
  assert.equal(await portal.sesKeyForEmbed(true), null)
  assert.equal(tokens.getSesKey(), 'held-key')

  // The widget's routine ask is answered from it, without the portal.
  fetchCalls = []
  assert.equal(await portal.sesKeyForEmbed(false), 'held-key')
  assert.equal(fetchCalls.length, 0)
  assert.deepEqual(journal, [], 'never sent anybody anywhere')
})

test('sesKeyForEmbed: null while a sign-out is in progress', async () => {
  tokens.setAuthToken('auth-token')
  tokens.saveSession('held-key', 900)
  sessionStorage.setItem('aic_logout', '1') // portal.ts LOGOUT_FLAG
  assert.equal(await portal.sesKeyForEmbed(false), null)
  assert.equal(await portal.sesKeyForEmbed(true), null)
  assert.equal(fetchCalls.length, 0)
})
