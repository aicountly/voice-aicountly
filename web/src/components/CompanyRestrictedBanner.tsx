import { useEffect, useRef, useState } from 'react'
import {
  COMPANY_RESTRICTED_EVENT,
  formatRestrictedUntil,
  getCompanyRestriction,
} from '../services/companyRestriction'
import type { CompanyRestrictionEventDetail } from '../services/companyRestriction'

/** One toast per company per this many ms, however many writes a screen tries. */
const TOAST_EVERY_MS = 30_000
const TOAST_SHOWN_MS = 8_000

/**
 * The read-only notice for a company whose Voice subscription lapsed (Manage's company lifecycle,
 * docs/COMPANY_LIFECYCLE.md).
 *
 * Shown above every screen of the company while Manage's companyinfo says `restricted` /
 * `purge_pending`, or once the API has refused a write with 423 COMPANY_RESTRICTED. Calls, recordings, transcripts and dashboards keep
 * working, so the notice says so: the window is for taking the data out. A refused write also raises a
 * toast with the server's sentence, so a screen that does not show its own error still explains why
 * nothing was saved.
 */
export function CompanyRestrictedBanner({ cmpId }: { cmpId: number | null }) {
  const [, setVersion] = useState(0)
  const [toast, setToast] = useState<string | null>(null)
  const lastToast = useRef(0)

  useEffect(() => {
    const onChange = (event: Event) => {
      setVersion((v) => v + 1)
      const detail = (event as CustomEvent<CompanyRestrictionEventDetail>).detail
      if (detail?.restricted && detail.cmpId === cmpId && detail.source === 'api' && detail.message && Date.now() - lastToast.current > TOAST_EVERY_MS) {
        lastToast.current = Date.now()
        setToast(detail.message)
      }
    }
    window.addEventListener(COMPANY_RESTRICTED_EVENT, onChange)
    return () => window.removeEventListener(COMPANY_RESTRICTED_EVENT, onChange)
  }, [cmpId])

  useEffect(() => {
    if (toast === null) return
    const timer = window.setTimeout(() => setToast(null), TOAST_SHOWN_MS)
    return () => window.clearTimeout(timer)
  }, [toast])

  const restriction = getCompanyRestriction(cmpId)
  const until = restriction ? formatRestrictedUntil(restriction.restrictedUntil) : null
  const title = restriction
    ? restriction.lifecycle === 'purged'
      ? 'This company’s Voice data was deleted'
      : `${until ? `Read-only until ${until}` : 'Read-only'}${restriction.lifecycle === 'purge_pending' ? ' — scheduled for removal' : ''}`
    : ''

  return (
    <>
      {restriction && (
        <div
          role="status"
          data-testid="company-restricted-banner"
          style={{
            margin: '12px 16px',
            padding: '12px 14px',
            borderRadius: 10,
            border: '1px solid rgb(135 85 0 / 28%)',
            background: 'var(--warning-bg, var(--warning-soft, #fff7e6))',
            color: 'inherit',
            fontSize: 13.5,
            lineHeight: 1.5,
          }}
        >
          <strong style={{ display: 'block', marginBottom: 2 }}>{title}</strong>
          {restriction.message} Calls, recordings, transcripts and dashboards stay open to view and download{until ? ` until ${until}` : ''}. Download the recordings you need: recordings and transcripts are deleted when the company’s Voice data is removed.
        </div>
      )}
      {toast !== null && (
        <div
          role="alert"
          data-testid="company-restricted-toast"
          style={{
            position: 'fixed',
            right: 16,
            bottom: 16,
            zIndex: 1000,
            maxWidth: 420,
            padding: '12px 14px',
            borderRadius: 12,
            border: '1px solid rgb(135 85 0 / 28%)',
            background: 'var(--warning-bg, var(--warning-soft, #fff7e6))',
            color: 'inherit',
            boxShadow: '0 8px 24px rgb(0 0 0 / 12%)',
            fontSize: 13,
          }}
        >
          <strong style={{ display: 'block', marginBottom: 2 }}>Not saved</strong>
          {toast}
        </div>
      )}
    </>
  )
}
