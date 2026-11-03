/**
 * The account menu's profile photo, and the Company block beside it.
 *
 * The photo comes from the portal through this product's own same-origin relay
 * (`/api/global/user/logo`), and every way that can fail — nobody uploaded one, the relay is
 * down, the portal answered a JSON error with a 200 — has to end as "no photo" so the menu draws
 * initials instead. Nothing may throw out of it and nothing may be reported to the person
 * (AICOUNTLY_ACCOUNT_MENU.md §6).
 *
 * The shipped module is loaded through Vite's SSR module graph under a minimal fake browser, the
 * same harness as same-origin-relays.test.mjs. The menu itself is TSX whose panel only exists
 * once it is opened, which no renderer here can do, so the Company block is read as source.
 *
 * Run with: npm run test:ui
 */
import { after, afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
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

const g = globalThis
const fakeWindow = {
  location: {
    href: `${ORIGIN}/`,
    origin: ORIGIN,
    protocol: 'https:',
    host: 'voice.aicountly.com',
    hostname: 'voice.aicountly.com',
    pathname: '/',
    search: '',
    hash: '',
    replace: () => {},
  },
  history: { replaceState: () => {} },
  localStorage: memoryStorage(),
  sessionStorage: memoryStorage(),
}
g.window = fakeWindow
g.localStorage = fakeWindow.localStorage
g.sessionStorage = fakeWindow.sessionStorage
g.document = { cookie: '' }

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const realFetch = globalThis.fetch
const server = await createServer({
  root,
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const { fetchUserLogoObjectUrl } = await server.ssrLoadModule('/src/services/userLogo.ts')
const launcher = await server.ssrLoadModule('/src/services/appLauncher.ts')
const tokens = await server.ssrLoadModule('/src/auth/tokens.ts')

after(async () => {
  await server.close()
  globalThis.fetch = realFetch
})

let calls = []
let created = 0
URL.createObjectURL = () => `blob:voice/${++created}`

/** Answer the next relay call with this, and record what was asked for. */
function respondWith(answer) {
  globalThis.fetch = async (input, init = {}) => {
    calls.push({ url: String(input), init })
    if (answer instanceof Error) throw answer
    return answer()
  }
}

const image = (type = 'image/png', bytes = Uint8Array.from([137, 80, 78, 71])) =>
  () => new Response(bytes, { status: 200, headers: { 'Content-Type': type } })

afterEach(() => {
  calls = []
  tokens.clearAllTokens()
})

describe('the account menu photo', () => {
  it("asks this product's own API, same-origin, with the person's ses_key", async () => {
    tokens.saveSession('ses-logo', 900)
    respondWith(image())

    const url = await fetchUserLogoObjectUrl()

    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, `${ORIGIN}/api/global/user/logo`)
    assert.equal(new Headers(calls[0].init.headers).get('Authorization'), 'Bearer ses-logo')
    // Never my.aicountly.com: its CORS allow-list does not name product domains, and production
    // hosting can answer a cross-site call with a challenge page carrying no CORS headers at all.
    assert.ok(!calls[0].url.includes('my.aicountly.com'))
    assert.match(url, /^blob:voice\//)
  })

  for (const [name, answer] of [
    ['nobody has uploaded a photo (204)', () => new Response(null, { status: 204 })],
    ['the portal has none for this person (404)', () => new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } })],
    ['the relay is down (503)', () => new Response('', { status: 503 })],
    ['the answer is a 200 that is not an image', () => new Response('{"error":"nope"}', { status: 200, headers: { 'Content-Type': 'application/json' } })],
    ['the answer is an empty image body', image('image/png', new Uint8Array(0))],
  ]) {
    it(`falls back to initials when ${name}`, async () => {
      tokens.saveSession('ses-logo', 900)
      respondWith(answer)

      assert.equal(await fetchUserLogoObjectUrl(), null)
    })
  }

  it('falls back to initials when the network throws, rather than letting it out', async () => {
    tokens.saveSession('ses-logo', 900)
    respondWith(new TypeError('Failed to fetch'))

    assert.equal(await fetchUserLogoObjectUrl(), null)
  })

  it('falls back to initials when there is no session to ask with, and asks nothing', async () => {
    respondWith(image())

    assert.equal(await fetchUserLogoObjectUrl(), null)
    assert.equal(calls.length, 0)
  })
})

// ---------------------------------------------------------------------------
// The menu, read as source
// ---------------------------------------------------------------------------

const read = (...parts) => readFileSync(join(root, 'src', ...parts), 'utf8')
const menu = read('shell', 'AccountMenu.tsx')
/** The same source with its comments removed — so "no toast" is about the code, not the prose. */
const code = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

describe('the photo in the menu', () => {
  it('is drawn in the identity block, not only on the trigger, with initials as the fallback', () => {
    const identity = menu.slice(menu.indexOf('vavatar vavatar--lg'), menu.indexOf('vaccount__caption">Accounts'))
    assert.match(identity, /photo !== null/)
    assert.match(identity, /vavatar__photo/)
    assert.match(identity, /initials \|\| <User size=\{18\} \/>/)
  })

  it('revokes the object URL on unmount, so a blob never outlives the menu', () => {
    const hook = menu.slice(menu.indexOf('function useUserLogo'), menu.indexOf('export function AccountMenu'))
    assert.match(hook, /return \(\) => \{[\s\S]*URL\.revokeObjectURL\(objectUrl\)/)
    // A photo that arrives after unmount is released too, not leaked.
    assert.match(hook, /if \(cancelled\) \{[\s\S]*URL\.revokeObjectURL\(url\)/)
  })

  it('reports nothing when there is no photo: no toast, no error state', () => {
    assert.doesNotMatch(code(read('services', 'userLogo.ts')), /toast|notify|console\.(error|warn)/)
    assert.doesNotMatch(code(menu.slice(menu.indexOf('function useUserLogo'), menu.indexOf('export function AccountMenu'))), /toast|notify|console\.(error|warn)/)
  })
})

describe('the Company block', () => {
  const block = menu.slice(menu.indexOf('vaccount__caption">Company'), menu.indexOf('manageProfilePath()'))

  it('is present: Voice is company-scoped, and the picker in the topbar is not a reason to omit it', () => {
    assert.notEqual(block, '')
  })

  it("lists the lines Voice can perform, in Books' order", () => {
    const entries = [...block.matchAll(/(Switch company|Edit company|Manage branches)/g)].map((m) => m[1])
    assert.deepEqual(entries, ['Switch company', 'Edit company', 'Manage branches'])
  })

  it("switches company through the topbar's own picker, the one action there is", () => {
    assert.match(block, /onClick=\{focusCompanyPicker\}/)
    assert.match(menu, /getElementById\('company-picker'\)/)
    // The id the picker is actually rendered with.
    assert.match(read('shell', 'AppShell.tsx'), /id="company-picker"/)
  })

  it('opens the company and its branches in Manage, in a new tab', () => {
    assert.match(block, /manageCompanyEditPath\(cmpId\)/)
    assert.match(block, /manageBranchListPath\(cmpId\)/)
    assert.match(menu, /window\.open|openManagePortal\(path\)/)
    assert.match(read('shell', 'AppShell.tsx'), /<AccountMenu cmpId=\{company\.cmp_id\} \/>/)
  })

  it('stands between the accounts block and the links, per the suite-wide order', () => {
    assert.ok(menu.indexOf('vaccount__caption">Accounts') < menu.indexOf('vaccount__caption">Company'))
    assert.ok(menu.indexOf('vaccount__caption">Company') < menu.indexOf('manageProfilePath()'))
  })
})

describe("the Manage paths the Company block uses", () => {
  it('are Manage\'s own company and branch routes, with the id encoded', () => {
    assert.equal(launcher.manageCompanyEditPath(74), '/company/74/edit')
    assert.equal(launcher.manageBranchListPath(74), '/branch/74')
    assert.equal(launcher.manageCompanyEditPath('7 4/x'), '/company/7%204%2Fx/edit')
  })

  it('and are opened in a new tab with no credential in the URL', () => {
    const opened = []
    const realOpen = fakeWindow.open
    fakeWindow.open = (url, target, features) => void opened.push([String(url), target, features])
    try {
      launcher.openManagePortal(launcher.manageCompanyEditPath(74))
    } finally {
      fakeWindow.open = realOpen
    }
    assert.equal(opened.length, 1)
    assert.equal(opened[0][1], '_blank')
    assert.equal(opened[0][2], 'noopener,noreferrer')
    // Manage is reached through the portal's authentication_jump, so the path rides inside
    // Manage's own callback URL inside returnUrl — encoded twice. No credential is ever put in the
    // URL: the jump is what signs the person in.
    let plain = opened[0][0]
    for (let i = 0; i < 3 && plain.includes('%'); i += 1) plain = decodeURIComponent(plain)
    assert.ok(plain.includes('/company/74/edit'), opened[0][0])
    assert.ok(!/auth_token|ses_key/i.test(opened[0][0]))
  })
})
