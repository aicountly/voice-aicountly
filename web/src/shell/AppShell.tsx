/**
 * The application frame.
 *
 * ## The company key is the tenant guarantee
 *
 * `<div key={company.cmp_id}>` around the routed outlet. When somebody switches
 * company React unmounts the whole tree and mounts a fresh one, so no component
 * can carry the previous tenant's rows in state into the new company's screens.
 *
 * Clearing state field by field is the approach that misses one, and the one it
 * misses here is a list of somebody's customers.
 */

import { Suspense, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Bell, LogOut, PhoneCall, Search, Settings2 } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import { useVoice } from '../context/VoiceContext'
import { APP_ENV } from '../config'
import { Badge, Button, EmptyState, LoadingRows, Notice } from '../ui'
import { visibleNav } from './navConfig'
import './app-shell.css'

export function AppShell() {
  const { company, companies, branchId, switchCompany, switchBranch, can, status, error, reload } = useVoice()
  const { signOut } = useAuth()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')

  // The simplified view is the default. An administrator turns the rest on.
  const [showAdvanced, setShowAdvanced] = useState(() => readAdvanced())

  if (status === 'loading') {
    return (
      <div className="vpage" style={{ maxWidth: 720, paddingTop: 60 }}>
        <LoadingRows rows={4} height={64} />
      </div>
    )
  }

  if (status === 'no-companies') {
    return (
      <div className="vpage" style={{ maxWidth: 620, paddingTop: 60 }}>
        <EmptyState
          title="No companies are available to you"
          body="Voice works inside an AICOUNTLY company. Ask an administrator to add you to one, then reload."
          action={<Button onClick={reload}>Try again</Button>}
        />
      </div>
    )
  }

  if (status === 'error' || !company) {
    return (
      <div className="vpage" style={{ maxWidth: 620, paddingTop: 60 }}>
        <Notice tone="danger" title="Could not load your companies">
          <p>{error?.message ?? 'The company service could not be reached.'}</p>
        </Notice>
        <div className="vactions">
          <Button variant="primary" onClick={reload}>Try again</Button>
          <Button onClick={signOut}>Sign out</Button>
        </div>
      </div>
    )
  }

  const items = visibleNav(can, {}, showAdvanced)

  const onSearch = (event: React.FormEvent) => {
    event.preventDefault()
    if (query.trim() === '') return
    navigate(`/intelligence?q=${encodeURIComponent(query.trim())}`)
  }

  return (
    <div className="vshell">
      <a className="skip-link" href="#main">Skip to content</a>

      <aside className="vsidebar" aria-label="Voice navigation">
        <NavLink to="/" className="vbrand">
          <span className="vbrand__mark" aria-hidden="true"><PhoneCall size={20} /></span>
          <span>
            <span className="vbrand__name">aicountly</span>
            <span className="vbrand__sub">VOICE</span>
          </span>
        </NavLink>

        <p className="vnav-caption">WORKSPACES</p>
        <nav className="vnav">
          {items.map((item) => (
            <span key={item.to} style={{ display: 'contents' }}>
              {item.separator ? <span className="vnav__separator" aria-hidden="true" /> : null}
              <NavLink to={item.to} end={item.exact}>
                <item.icon size={16} aria-hidden="true" />
                {item.label}
              </NavLink>
            </span>
          ))}
        </nav>

        <div className="vsidebar__foot">
          <Button
            size="sm"
            variant="ghost"
            icon={Settings2}
            onClick={() => {
              const next = !showAdvanced
              setShowAdvanced(next)
              rememberAdvanced(next)
            }}
            full
          >
            {showAdvanced ? 'Simplified view' : 'All settings'}
          </Button>
          <div className="vsidebar__note" style={{ marginTop: 10 }}>
            Smarter conversations for a brighter business.
            {APP_ENV !== 'production' ? <><br /><strong>{APP_ENV}</strong> environment</> : null}
          </div>
        </div>
      </aside>

      <div className="vmain">
        <header className="vtopbar">
          <div className="vcontext">
            <label className="sr-only" htmlFor="company-picker">Company</label>
            <select
              id="company-picker"
              value={company.cmp_id}
              onChange={(event) => switchCompany(Number(event.target.value))}
            >
              {companies.map((entry) => (
                <option key={entry.cmp_id} value={entry.cmp_id}>{entry.name}</option>
              ))}
            </select>

            {company.branches && company.branches.length > 0 ? (
              <>
                <label className="sr-only" htmlFor="branch-picker">Branch</label>
                <select
                  id="branch-picker"
                  value={branchId}
                  onChange={(event) => switchBranch(Number(event.target.value))}
                >
                  <option value={0}>All branches</option>
                  {company.branches.map((branch) => (
                    <option key={branch.bo_id} value={branch.bo_id}>{branch.name}</option>
                  ))}
                </select>
              </>
            ) : null}
          </div>

          <form className="vsearch" onSubmit={onSearch} role="search">
            <Search size={15} className="vsearch__icon" aria-hidden="true" />
            <label className="sr-only" htmlFor="global-search">Search conversations</label>
            <input
              id="global-search"
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search calls, numbers, transcripts…"
            />
          </form>

          <Button variant="ghost" className="vbell" title="Notifications" aria-label="Notifications" icon={Bell} />
          <Button variant="ghost" onClick={signOut} icon={LogOut} title="Sign out">
            <span className="sr-only">Sign out</span>
          </Button>
        </header>

        <main id="main" tabIndex={-1} className="vpage">
          {/* The tenant boundary. See the file comment. */}
          <div key={`${company.cmp_id}:${branchId}`}>
            <Suspense fallback={<LoadingRows rows={5} height={72} />}>
              <Outlet />
            </Suspense>
          </div>
        </main>
      </div>
    </div>
  )
}

/** The page header every screen shares. */
export function PageHeader({ title, subtitle, actions, badge }: {
  title: string
  subtitle?: string
  actions?: React.ReactNode
  badge?: React.ReactNode
}) {
  return (
    <header className="vpage__head">
      <div>
        <p className="vpage__eyebrow">AICOUNTLY VOICE</p>
        <h1 className="vpage__title">{title}</h1>
        {subtitle ? <p className="vpage__subtitle">{subtitle}</p> : null}
        {badge ? <div style={{ marginTop: 8 }}>{badge}</div> : null}
      </div>
      {actions ? <div className="vpage__actions">{actions}</div> : null}
    </header>
  )
}

export { Badge }

const ADVANCED_KEY = 'voice.advanced-nav'

/**
 * Whether this viewer wants the full administration surface.
 *
 * A per-viewer preference stored in this browser. It hides nothing that matters
 * — every hidden route is still reachable by URL and still permission-checked
 * on the server.
 */
function readAdvanced(): boolean {
  try {
    return window.localStorage.getItem(ADVANCED_KEY) === '1'
  } catch {
    return false
  }
}

function rememberAdvanced(value: boolean): void {
  try {
    window.localStorage.setItem(ADVANCED_KEY, value ? '1' : '0')
  } catch {
    // Storage blocked. The preference simply does not persist.
  }
}
