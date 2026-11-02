/**
 * The roster of AICOUNTLY accounts signed in on this browser.
 *
 * The readable half of the portal's roster (`AIC_ACCOUNTS_VIEW`) is the only
 * channel the account menu has — the token half is httpOnly and the portal's
 * API is cross-origin with credentials refused. So what matters here is that a
 * cookie this app did not write is read defensively: a missing one, a corrupt
 * one and a hostile one all have to read as "no accounts" rather than throw,
 * because a render error in the shell takes the whole topbar with it.
 *
 * `src/auth/accounts.ts` is a twin of the Notes and Books modules; these checks
 * are the twins of their tests, plus the one thing only this product can get
 * wrong — dropping the remembered company on a switch while keeping the tokens.
 *
 * The shipped modules are loaded through Vite's SSR module graph (extensionless
 * imports, import.meta.env, the .tsx context the clearer lives in), under a
 * minimal fake browser — the same harness as same-origin-relays.test.mjs.
 *
 * Run with: npm run test:ui
 */

import { after, beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

// ---------------------------------------------------------------------------
// A fake browser
// ---------------------------------------------------------------------------

function memoryStorage() {
  const data = new Map()
  return {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => void data.set(key, String(value)),
    removeItem: (key) => void data.delete(key),
    clear: () => data.clear(),
  }
}

const assigned = []
let jar = ''

const g = globalThis
const fakeWindow = {
  location: {},
  history: { replaceState: () => {} },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
  open: () => null,
  addEventListener: () => {},
  removeEventListener: () => {},
}

function at(href) {
  const url = new URL(href)
  Object.assign(fakeWindow.location, {
    href: url.href,
    origin: url.origin,
    protocol: url.protocol,
    host: url.host,
    hostname: url.hostname,
    pathname: url.pathname,
    search: '',
    hash: '',
    assign: (to) => void assigned.push(String(to)),
    replace: () => {},
  })
}

at('https://voice.aicountly.com/')
g.window = fakeWindow
g.localStorage = fakeWindow.localStorage
g.sessionStorage = fakeWindow.sessionStorage
g.document = {
  get cookie() {
    return jar
  },
  // The retired-cookie purge in auth/sharedAuthCookie.ts writes here on a
  // sign-out; the roster cookie itself is the portal's to write, never this
  // app's, so nothing read back comes from a write.
  set cookie(value) {
    void value
  },
}

const realFetch = globalThis.fetch
globalThis.fetch = async () => new Response('{"data":[]}', { status: 200 })

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const accounts = await server.ssrLoadModule('/src/auth/accounts.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
})

const { MAX_ACCOUNTS } = accounts

/** Encode a roster the way the portal's AccountRoster::summaryCookieValue does. */
function viewCookie(list) {
  const json = JSON.stringify({ v: 1, max: MAX_ACCOUNTS, a: list })
  const binary = Array.from(new TextEncoder().encode(json), (byte) => String.fromCharCode(byte)).join('')
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function withCookie(value) {
  jar = value === '' ? '' : `AIC_ACCOUNTS_VIEW=${value}`
}

beforeEach(() => {
  jar = ''
  assigned.length = 0
  fakeWindow.localStorage.clear()
  tokens.clearAllTokens()
  at('https://voice.aicountly.com/')
})

describe('reading the roster of accounts signed in on this browser', () => {
  it('reads the portal’s summary cookie', () => {
    withCookie(
      viewCookie([
        { u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 },
        { u: 77, n: 'Priya Sharma', e: 'pr•••@example.com', a: 0 },
      ]),
    )

    const roster = accounts.readAccountRoster()
    assert.equal(roster.length, 2)
    assert.deepEqual(roster[0], {
      uuid: 88,
      name: 'Rahul Gupta',
      maskedEmail: 'ra•••@cagupta.in',
      maskedMobile: '+91••••••••78',
      active: true,
    })
    assert.equal(roster[1].active, false)
  })

  it('puts the active account first however the cookie ordered them', () => {
    // The identity block is drawn from the active entry, so this is not a
    // cosmetic ordering — getting it wrong labels the session with the wrong
    // person's masked mobile and Cust ID.
    withCookie(viewCookie([{ u: 77, n: 'Priya', a: 0 }, { u: 88, n: 'Rahul', a: 1 }]))
    assert.deepEqual(accounts.readAccountRoster().map((account) => account.uuid), [88, 77])
  })

  it('keeps the portal’s masking — a full address never reaches the menu', () => {
    withCookie(viewCookie([{ u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 }]))
    const [account] = accounts.readAccountRoster()
    assert.doesNotMatch(account.maskedEmail, /rahul@/)
    assert.match(account.maskedEmail, /•/)
    assert.match(account.maskedMobile, /•/)
  })

  // A browser signed in before the roster shipped, local development, and a
  // cleared cookie jar all land here. None is an error: the menu falls back to
  // the portal chooser, which always knows the truth.
  it('is empty when there is no cookie', () => {
    withCookie('')
    assert.deepEqual(accounts.readAccountRoster(), [])
  })

  it('is empty rather than throwing when the cookie is corrupt', () => {
    for (const value of ['!!!not-base64!!!', 'YWJj', btoa('{"a":'), btoa('[]'), btoa('null')]) {
      withCookie(value)
      assert.deepEqual(accounts.readAccountRoster(), [], value)
    }
  })

  it('drops entries with no usable subscriber id', () => {
    withCookie(
      viewCookie([
        { u: 0, n: 'Nobody' },
        { n: 'Also nobody' },
        { u: 'not-a-number', n: 'Nor this' },
        { u: -3, n: 'Nor this either' },
        null,
        { u: 88, n: 'Rahul', a: 1 },
      ]),
    )
    assert.deepEqual(accounts.readAccountRoster().map((account) => account.uuid), [88])
  })

  it('never reports more accounts than the portal allows', () => {
    withCookie(viewCookie(Array.from({ length: 9 }, (_, index) => ({ u: index + 1, n: `User ${index + 1}` }))))
    assert.equal(accounts.readAccountRoster().length, MAX_ACCOUNTS)
  })
})

describe('whether another account may be added', () => {
  it('allows one while there is room, and refuses once the browser is full', () => {
    assert.equal(accounts.canAddAccount([]), true)
    assert.equal(accounts.canAddAccount(Array.from({ length: MAX_ACCOUNTS - 1 }, () => ({}))), true)
    assert.equal(accounts.canAddAccount(Array.from({ length: MAX_ACCOUNTS }, () => ({}))), false)
  })

  it('hides "Use another account" when the browser already holds five', () => {
    withCookie(
      viewCookie(Array.from({ length: MAX_ACCOUNTS }, (_, index) => ({ u: index + 1, a: index === 0 ? 1 : 0 }))),
    )
    assert.equal(accounts.canAddAccount(accounts.readAccountRoster()), false)
  })
})

describe('the portal URLs the menu navigates to', () => {
  it('names this product in production so the portal hands the user back here', () => {
    at('https://voice.aicountly.com/')
    assert.equal(
      accounts.buildSwitchAccountUrl(88),
      'https://my.aicountly.com/login/switch_account/voice?uuid=88',
    )
    assert.equal(accounts.buildAddAccountUrl(), 'https://my.aicountly.com/login/add_account/voice')
    assert.equal(accounts.buildChooseAccountUrl(), 'https://my.aicountly.com/login/select_account/voice')
  })

  it('uses the sandbox portal on a sandbox host, and still the `voice` product key', () => {
    // A sandbox build pointed at my.aicountly.com is the classic way to break
    // sandbox sign-in; the product key must not pick up the `gh` label either.
    at('https://voice.gh.aicountly.com/')
    assert.equal(
      accounts.buildSwitchAccountUrl(88),
      'https://sandbox.aicountly.com/login/switch_account/voice?uuid=88',
    )
    assert.equal(accounts.buildAddAccountUrl(), 'https://sandbox.aicountly.com/login/add_account/voice')
    assert.equal(accounts.buildChooseAccountUrl(), 'https://sandbox.aicountly.com/login/select_account/voice')
  })

  it('treats localhost as sandbox and falls back to the configured product key', () => {
    at('http://localhost:5173/')
    assert.equal(accounts.buildChooseAccountUrl(), 'https://sandbox.aicountly.com/login/select_account/voice')
  })

  it('never puts a token or a ses_key in a portal URL', () => {
    for (const url of [
      accounts.buildSwitchAccountUrl(88),
      accounts.buildAddAccountUrl(),
      accounts.buildChooseAccountUrl(),
    ]) {
      assert.doesNotMatch(url, /auth_token|ses_key|Bearer/i)
    }
  })
})

describe('leaving for another account', () => {
  it('navigates to the portal with the account asked for', () => {
    accounts.performAccountSwitch(77)
    assert.deepEqual(assigned, ['https://my.aicountly.com/login/switch_account/voice?uuid=77'])
  })

  it('goes to the add-account screen when no account is named', () => {
    accounts.performAccountSwitch(undefined)
    assert.deepEqual(assigned, ['https://my.aicountly.com/login/add_account/voice'])
  })

  it('drops the remembered company, which belongs to the account being left', () => {
    // VoiceProvider reads this before it has asked Manage anything, so carried
    // across it would open a company the next account has no access to.
    localStorage.setItem('voice.company', '60')

    accounts.performAccountSwitch(77)

    assert.equal(localStorage.getItem('voice.company'), null)
  })

  it('drops the ses_key of the account being left', () => {
    // It is the Bearer on every call to this product's API.
    tokens.saveSession('ses-key-of-the-old-account', 900)
    assert.equal(tokens.getSesKey(), 'ses-key-of-the-old-account')

    accounts.performAccountSwitch(77)

    assert.equal(tokens.getSesKey(), null)
  })

  it('leaves the auth_token alone, so backing out of the chooser is not a sign-out', () => {
    // The portal replaces the tokens on the way back through /auth/callback.
    // Clearing them here would strand anyone who cancels at a login form.
    tokens.setAuthToken('still-signed-in')

    accounts.performAccountSwitch(77)

    assert.equal(tokens.getAuthToken(), 'still-signed-in')
    assert.equal(localStorage.getItem('auth_token'), 'still-signed-in')
  })
})
