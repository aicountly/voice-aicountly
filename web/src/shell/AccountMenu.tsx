/**
 * The account menu, behind the avatar in the top right.
 *
 * The layout is the suite-wide one — the same blocks in the same order in every
 * AICOUNTLY product, so the menu does not rearrange itself as people move
 * between them (see `AICOUNTLY_ACCOUNT_MENU.md` in the Saas Common Handler):
 *
 *   1. who you are      photo, name, e-mail, masked mobile, Cust ID
 *   2. your accounts    the others signed in on this browser, and "Use another"
 *   3. company          Voice is company-scoped: switch / edit / branches
 *   4. appearance       Voice has no in-menu theme switch
 *   5. links            Manage profile, Manage Account (analytics preferences
 *                       keep their place in the sidebar footer, unduplicated)
 *   6. sign out
 *
 * The identity block's text is drawn entirely from the roster cookie, because
 * this app has no profile call: its API relays sign-in and sign-out and nothing
 * else (`server-php/index.php` RELAYED_PATHS), so the e-mail shown is the
 * portal's MASKED one rather than the full address. That becomes real the
 * moment the API relays `userprofile`.
 *
 * The photo no longer waits on that. It comes from the portal through this
 * product's own relay (`services/userLogo.ts` → `/api/global/user/logo`, the
 * read-only leg of the same relay), with initials as the fallback whenever
 * there is none to be had.
 *
 * The Company block is here even though the topbar has its own company and
 * branch pickers to the left. A picker in the bar and an entry in the menu are
 * not two sources of truth — they are two doors onto the same action, and the
 * menu is the one that sits in the same place in every AICOUNTLY product.
 * "Recent" is the one line left out: Voice remembers the company last opened,
 * not an ordered list of them, and the contract leaves out a line the product
 * cannot perform rather than inventing one.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { Building2, ChevronDown, GitBranch, LogOut, Pencil, User, UserCog, UserPlus, Users } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { buildChooseAccountUrl, canAddAccount, performAccountSwitch, readAccountRoster } from '../auth/accounts'
import type { RosterAccount } from '../auth/accounts'
import { stopConnect } from '../connect/connect'
import {
  manageAccountPath,
  manageBranchListPath,
  manageCompanyEditPath,
  manageProfilePath,
  openManagePortal,
} from '../services/appLauncher'
import { fetchUserLogoObjectUrl } from '../services/userLogo'

/**
 * First letters of up to two words — the avatar when there is no photo.
 *
 * Splits on `@`, `.`, `_` and `-` as well as whitespace, so an account with no
 * name on file still gets something out of its e-mail.
 */
function initialsOf(source: string | null | undefined): string {
  return String(source ?? '')
    .split(/[\s@._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

function accountLabel(account: RosterAccount): string {
  return account.name || account.maskedEmail || `Account ${account.uuid}`
}

/**
 * The profile photo as an object URL, or null while there is none.
 *
 * Fetched once per mount and revoked on unmount, so a blob never outlives the menu that showed
 * it. It never throws and never reports a failure: the caller draws initials when this is null.
 */
function useUserLogo(): string | null {
  const [src, setSrc] = useState<string | null>(null)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    void fetchUserLogoObjectUrl().then((url) => {
      if (url === null) return
      if (cancelled) {
        // Unmounted while the photo was in flight: nothing will show it, so let it go.
        URL.revokeObjectURL(url)
        return
      }
      objectUrl = url
      setSrc(url)
    })

    return () => {
      cancelled = true
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl)
      setSrc(null)
    }
  }, [])

  return src
}

export function AccountMenu({ cmpId = null }: { cmpId?: number | null }) {
  const { signOut } = useAuth()
  const [open, setOpen] = useState(false)
  // Null until the photo arrives, and null for good when there is none: the menu draws initials.
  const photo = useUserLogo()
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)

  // Read when the menu opens, not on every render: only a full page load can
  // change the cookie, because the portal is what writes it.
  const [roster, setRoster] = useState<RosterAccount[]>([])
  useEffect(() => {
    if (open) setRoster(readAccountRoster())
  }, [open])

  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (event: MouseEvent) => {
      if (rootRef.current && event.target instanceof Node && !rootRef.current.contains(event.target)) {
        setOpen(false)
      }
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const active = useMemo(() => roster.find((account) => account.active) ?? null, [roster])
  const others = useMemo(() => roster.filter((account) => !account.active), [roster])
  const name = active ? accountLabel(active) : ''
  const initials = initialsOf(name)

  const openManage = (path: string) => {
    setOpen(false)
    openManagePortal(path)
  }

  // The company picker in the topbar is the action; this is the menu's door onto it, in the place
  // every other AICOUNTLY product keeps it.
  const focusCompanyPicker = () => {
    setOpen(false)
    const picker = document.getElementById('company-picker')
    if (!(picker instanceof HTMLSelectElement)) return
    picker.focus()
    // Chromium can open the list itself; elsewhere focusing it is as far as the browser allows.
    const withPicker = picker as HTMLSelectElement & { showPicker?: () => void }
    if (typeof withPicker.showPicker === 'function') {
      try {
        withPicker.showPicker()
      } catch {
        // Not allowed without a user gesture on this browser: the select is focused regardless.
      }
    }
  }

  const switchTo = (uuid?: number) => {
    // Connect goes first: its widget and session callback belong to the account
    // being left, exactly as at sign-out.
    stopConnect()
    setOpen(false)
    performAccountSwitch(uuid)
  }

  return (
    <div className="vaccount" ref={rootRef}>
      <button
        ref={triggerRef}
        type="button"
        className="vaccount__trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        // Includes the name shown on the button (WCAG 2.5.3, label in name).
        aria-label={name ? `Account menu for ${name}` : 'Account menu'}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="vavatar" aria-hidden="true">
          {photo !== null
            ? <img src={photo} alt="" className="vavatar__photo" />
            : initials || <User size={16} />}
        </span>
        <ChevronDown size={13} aria-hidden="true" />
      </button>

      {open ? (
        <div className="vaccount__menu" role="menu" aria-label="Account menu">
          {active ? (
            <div className="vaccount__who">
              {/* 1. Who you are — with the photo, which this menu used to leave out. */}
              <span className="vavatar vavatar--lg" aria-hidden="true">
                {photo !== null
                  ? <img src={photo} alt="" className="vavatar__photo" />
                  : initials || <User size={18} />}
              </span>
              <div className="vaccount__who-text">
                <p className="vaccount__name">{active.name || 'Signed in'}</p>
                {active.maskedEmail ? <p className="vaccount__meta">{active.maskedEmail}</p> : null}
                {active.maskedMobile ? (
                  <p className="vaccount__meta vaccount__num" title="Your registered mobile number">
                    {active.maskedMobile}
                  </p>
                ) : null}
                <p className="vaccount__meta" title="Your AICOUNTLY customer ID">
                  Cust ID: <span className="vaccount__num">{active.uuid}</span>
                </p>
              </div>
            </div>
          ) : null}

          <div className="vaccount__section">
            <p className="vaccount__caption">Accounts</p>

            {others.map((account) => (
              <button
                key={account.uuid}
                type="button"
                role="menuitem"
                className="vaccount__item"
                onClick={() => switchTo(account.uuid)}
              >
                <span className="vavatar vavatar--sm" aria-hidden="true">
                  {initialsOf(account.name || account.maskedEmail) || <User size={13} />}
                </span>
                <span className="vaccount__item-text">
                  <span className="vaccount__item-name">{accountLabel(account)}</span>
                  {account.maskedEmail ? <span className="vaccount__item-meta">{account.maskedEmail}</span> : null}
                </span>
              </button>
            ))}

            {/* No readable roster — local development, or a browser signed in
                before the roster shipped. The portal chooser always knows. */}
            {roster.length === 0 ? (
              <a role="menuitem" className="vaccount__item" href={buildChooseAccountUrl()}>
                <Users size={16} aria-hidden="true" /> Switch account
              </a>
            ) : null}

            {/* Hidden once this browser holds five — the portal refuses a sixth. */}
            {canAddAccount(roster) ? (
              <button type="button" role="menuitem" className="vaccount__item" onClick={() => switchTo(undefined)}>
                <UserPlus size={16} aria-hidden="true" /> Use another account
              </button>
            ) : null}
          </div>

          {/* 3. Company — Voice is company-scoped: numbers, calls and campaigns
              all hang off cmp_id. Switching is the topbar's own picker; the
              company's details and its branches live in Aicountly Manage, which
              is where Voice sends people for both. */}
          <div className="vaccount__section">
            <p className="vaccount__caption">Company</p>
            <button
              type="button"
              role="menuitem"
              className="vaccount__item"
              title="Choose another company in the topbar"
              onClick={focusCompanyPicker}
            >
              <Building2 size={16} aria-hidden="true" /> Switch company
            </button>
            {cmpId !== null ? (
              <>
                <button
                  type="button"
                  role="menuitem"
                  className="vaccount__item"
                  title="Opens this company in Manage in a new tab"
                  onClick={() => openManage(manageCompanyEditPath(cmpId))}
                >
                  <Pencil size={16} aria-hidden="true" />
                  <span className="vaccount__item-text">Edit company</span>
                  <span className="sr-only"> (opens in a new tab)</span>
                </button>
                <button
                  type="button"
                  role="menuitem"
                  className="vaccount__item"
                  title="Opens this company's branches in Manage in a new tab"
                  onClick={() => openManage(manageBranchListPath(cmpId))}
                >
                  <GitBranch size={16} aria-hidden="true" />
                  <span className="vaccount__item-text">Manage branches</span>
                  <span className="sr-only"> (opens in a new tab)</span>
                </button>
              </>
            ) : null}
          </div>

          <div className="vaccount__section">
            <button
              type="button"
              role="menuitem"
              className="vaccount__item"
              title="Opens your profile in Manage in a new tab"
              onClick={() => openManage(manageProfilePath())}
            >
              <UserCog size={16} aria-hidden="true" />
              <span className="vaccount__item-text">Manage profile</span>
              <span className="sr-only"> (opens in a new tab)</span>
            </button>
            <button
              type="button"
              role="menuitem"
              className="vaccount__item"
              title="Opens your account settings in Manage in a new tab"
              onClick={() => openManage(manageAccountPath())}
            >
              <Users size={16} aria-hidden="true" />
              <span className="vaccount__item-text">Manage Account</span>
              <span className="sr-only"> (opens in a new tab)</span>
            </button>
          </div>

          {/* "Sign out", not "Log out": one wording across the suite, and it
              ends every account signed in on this browser, as the portal does. */}
          <div className="vaccount__section">
            <button
              type="button"
              role="menuitem"
              className="vaccount__item vaccount__item--danger"
              onClick={signOut}
            >
              <LogOut size={16} aria-hidden="true" /> Sign out
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
