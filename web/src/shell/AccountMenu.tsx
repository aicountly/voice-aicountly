/**
 * The account menu, behind the avatar in the top right.
 *
 * The layout is the suite-wide one — the same blocks in the same order in every
 * AICOUNTLY product, so the menu does not rearrange itself as people move
 * between them (see `AICOUNTLY_ACCOUNT_MENU.md` in the Saas Common Handler):
 *
 *   1. who you are      photo, name, e-mail, masked mobile, Cust ID
 *   2. your accounts    the others signed in on this browser, and "Use another"
 *   3. company          the topbar's own company and branch pickers, to the left
 *   4. appearance       Voice has no in-menu theme switch
 *   5. links            Manage profile, Manage Account (analytics preferences
 *                       keep their place in the sidebar footer, unduplicated)
 *   6. sign out
 *
 * The identity block is drawn entirely from the roster cookie, which is all
 * this app has: its API relays sign-in and sign-out and nothing else
 * (`server-php/index.php` RELAYED_PATHS), so there is no profile call and no
 * user-logo route. That means the e-mail shown is the portal's MASKED one
 * rather than the full address, and the avatar is initials — which the contract
 * allows as the fallback. Both become real the moment the API relays
 * `userprofile` and a user logo.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, LogOut, User, UserCog, UserPlus, Users } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { buildChooseAccountUrl, canAddAccount, performAccountSwitch, readAccountRoster } from '../auth/accounts'
import type { RosterAccount } from '../auth/accounts'
import { stopConnect } from '../connect/connect'
import { manageAccountPath, manageProfilePath, openManagePortal } from '../services/appLauncher'

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

export function AccountMenu() {
  const { signOut } = useAuth()
  const [open, setOpen] = useState(false)
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
        <span className="vavatar" aria-hidden="true">{initials || <User size={16} />}</span>
        <ChevronDown size={13} aria-hidden="true" />
      </button>

      {open ? (
        <div className="vaccount__menu" role="menu" aria-label="Account menu">
          {active ? (
            <div className="vaccount__who">
              <span className="vavatar vavatar--lg" aria-hidden="true">{initials || <User size={18} />}</span>
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
