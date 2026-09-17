/**
 * The Voice component library.
 *
 * Every state a screen can be in has a component here, because the states are
 * where this product either tells the truth or does not:
 *
 *   loading      — we are fetching
 *   empty        — there is genuinely nothing
 *   error        — the request failed, and whether retrying helps
 *   forbidden    — you may not see this (different from empty)
 *   unavailable  — another product could not be reached (different again)
 *   partial      — some of this loaded and some did not
 *
 * A screen that renders "0" for all six is a screen that lies five times.
 */

import { useEffect, useId, useRef } from 'react'
import type { ReactNode } from 'react'
import {
  AlertTriangle, ArrowRight, Ban, CheckCircle2, ChevronDown, Info,
  Loader2, PlugZap, RefreshCw, TrendingDown, TrendingUp, X,
} from 'lucide-react'

import { ApiError } from '../services/api'
import type { Metric } from '../services/types'

/* ------------------------------------------------------------------ button */

export function Button({
  children, onClick, variant = 'default', size, type = 'button',
  disabled, title, icon: Icon, full, ...rest
}: {
  children?: ReactNode
  onClick?: () => void
  variant?: 'default' | 'primary' | 'danger' | 'ghost'
  size?: 'sm'
  type?: 'button' | 'submit'
  disabled?: boolean
  title?: string
  icon?: typeof ArrowRight
  full?: boolean
} & Record<string, unknown>) {
  const classes = [
    'vbtn',
    variant !== 'default' ? `vbtn--${variant}` : '',
    size === 'sm' ? 'vbtn--sm' : '',
    full ? 'vbtn--full' : '',
    !children && Icon ? 'vbtn--icon' : '',
  ].filter(Boolean).join(' ')

  return (
    <button
      type={type}
      className={classes}
      onClick={onClick}
      disabled={disabled}
      title={title}
      {...(rest as Record<string, never>)}
    >
      {Icon ? <Icon size={15} aria-hidden="true" /> : null}
      {children}
    </button>
  )
}

/* ------------------------------------------------------------------- badge */

export function Badge({
  tone = 'default', children, live,
}: {
  tone?: 'default' | 'neutral' | 'amber' | 'red'
  children: ReactNode
  live?: boolean
}) {
  const classes = ['vbadge', tone !== 'default' ? `vbadge--${tone}` : '', live ? 'vbadge--live' : '']
    .filter(Boolean).join(' ')
  return <span className={classes}>{children}</span>
}

/**
 * A call or agent state, rendered with the right tone.
 *
 * `unknown` is amber and reads as "state unknown", never green and never a
 * silent blank — the whole point of the backend reporting it is that the screen
 * says so.
 */
export function StatusPill({ status }: { status: string }) {
  const tone = ({
    answered: 'default', ringing: 'amber', queued: 'amber', held: 'amber',
    transferring: 'amber', initiated: 'neutral',
    completed: 'neutral', busy: 'red', unanswered: 'red', failed: 'red', cancelled: 'neutral',
    unknown: 'amber',
    available: 'default', wrap_up: 'amber', away: 'neutral', offline: 'neutral',
    connected: 'default', degraded: 'amber', unavailable: 'red',
    not_configured: 'neutral', standby: 'neutral', forbidden: 'red',
    running: 'default', paused: 'amber', draft: 'neutral', scheduled: 'neutral',
    published: 'default', tested: 'neutral', suggested: 'amber', confirmed: 'default',
    ok: 'default', warning: 'amber', exceeded: 'red', no_limit: 'neutral',
    pass: 'default', error: 'red', warn: 'amber',
    passed: 'default', not_applicable: 'neutral',
  } as Record<string, 'default' | 'neutral' | 'amber' | 'red'>)[status] ?? 'neutral'

  return <Badge tone={tone} live={status === 'answered'}>{statusLabel(status)}</Badge>
}

export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    unknown: 'State unknown',
    wrap_up: 'Wrap-up',
    not_configured: 'Not connected',
    not_applicable: 'N/A',
    ai_completed: 'AI completed',
    human_completed: 'Handled by a person',
    handover_completed: 'Handed over',
    no_answer: 'No answer',
    no_limit: 'No limit',
  }
  return labels[status] ?? status.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())
}

/* ------------------------------------------------------------------- card */

export function Card({
  title, subtitle, action, children, hero, flush, id,
}: {
  title?: ReactNode
  subtitle?: ReactNode
  action?: ReactNode
  children: ReactNode
  hero?: boolean
  flush?: boolean
  id?: string
}) {
  return (
    <section className={['vcard', hero ? 'vcard--hero' : '', flush ? 'vcard--flush' : ''].filter(Boolean).join(' ')} id={id}>
      {(title || action) && (
        <div className="vcard__head" style={flush ? { padding: '22px 22px 0' } : undefined}>
          <div style={{ minWidth: 0 }}>
            {typeof title === 'string' ? <h2>{title}</h2> : title}
            {subtitle ? <p className="vcard__subtitle">{subtitle}</p> : null}
          </div>
          {action}
        </div>
      )}
      {children}
    </section>
  )
}

export function Row({
  title, detail, trailing, onClick,
}: {
  title: ReactNode
  detail?: ReactNode
  trailing?: ReactNode
  onClick?: () => void
}) {
  const content = (
    <>
      <div className="vrow__main">
        <div className="vrow__title">{title}</div>
        {detail ? <p className="vrow__detail">{detail}</p> : null}
      </div>
      {trailing}
    </>
  )

  if (!onClick) return <div className="vrow">{content}</div>

  return (
    <div
      className="vrow"
      role="button"
      tabIndex={0}
      style={{ cursor: 'pointer' }}
      onClick={onClick}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onClick()
        }
      }}
    >
      {content}
    </div>
  )
}

/* ----------------------------------------------------------------- metrics */

export function formatMetric(value: number | null, unit: Metric['unit'], currency = 'INR'): string {
  if (value === null) return '—'
  switch (unit) {
    case 'percent':
      return `${value}%`
    case 'currency':
      return formatMoney(Math.round(value * 100), currency)
    case 'seconds':
      return formatDuration(value)
    case 'ms':
      return `${Math.round(value)} ms`
    default:
      return new Intl.NumberFormat('en-IN').format(value)
  }
}

export function formatMoney(minorUnits: number, currency = 'INR'): string {
  return new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(minorUnits / 100)
}

export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—'
  const total = Math.max(0, Math.round(seconds))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`
}

export function MetricCard({ metric, currency, onDrilldown }: {
  metric: Metric
  currency: string
  onDrilldown?: (route: string, params: Record<string, string>) => void
}) {
  // A metric this deployment cannot measure says why. A zero would read as a
  // measurement, and a bad one.
  if (metric.status === 'unavailable') {
    return (
      <article className="vmetric vmetric--unavailable">
        <p className="vmetric__label">
          <Ban size={13} aria-hidden="true" />
          {metric.label}
        </p>
        <p className="vmetric__value">Not available</p>
        <p className="vmetric__note">{metric.unavailable_reason}</p>
      </article>
    )
  }

  const drilldown = metric.drilldown
  const clickable = Boolean(drilldown && onDrilldown)

  return (
    <article
      className="vmetric"
      onClick={clickable ? () => onDrilldown?.(drilldown!.route, drilldown!.params) : undefined}
      style={clickable ? { cursor: 'pointer' } : undefined}
      role={clickable ? 'button' : undefined}
      tabIndex={clickable ? 0 : undefined}
      onKeyDown={
        clickable
          ? (event) => {
              if (event.key === 'Enter') onDrilldown?.(drilldown!.route, drilldown!.params)
            }
          : undefined
      }
    >
      <p className="vmetric__label">{metric.label}</p>
      <p className="vmetric__value">{formatMetric(metric.value, metric.unit, currency)}</p>
      <div className="vmetric__foot">
        <div className="vsplit">
          <ChangeChip change={metric.change_pct} direction={metric.direction} />
          {clickable ? <ArrowRight size={13} aria-hidden="true" style={{ color: 'var(--muted)' }} /> : null}
        </div>
        {metric.note ? <p className="vmetric__note">{metric.note}</p> : null}
      </div>
    </article>
  )
}

/**
 * The change figure.
 *
 * `null` renders as "no comparison" rather than 0%. "Steady" and "we have
 * nothing to compare against" are different claims, and a new company seeing
 * ↑0% on every card is a dashboard lying quietly.
 */
export function ChangeChip({ change, direction }: {
  change: number | null
  direction: Metric['direction']
}) {
  if (change === null) {
    return <span className="vchange vchange--flat">No comparison</span>
  }

  const rising = change > 0
  const good = direction === 'neutral' ? null : direction === 'up_is_good' ? rising : !rising
  const tone = change === 0 ? 'flat' : good === null ? 'flat' : good ? 'good' : 'bad'
  const Icon = change === 0 ? null : rising ? TrendingUp : TrendingDown

  return (
    <span className={`vchange vchange--${tone}`}>
      {Icon ? <Icon size={13} aria-hidden="true" /> : null}
      {change > 0 ? '+' : ''}{change}%
    </span>
  )
}

export function MetricRow({ metrics, currency, onDrilldown }: {
  metrics: Metric[]
  currency: string
  onDrilldown?: (route: string, params: Record<string, string>) => void
}) {
  return (
    <section className="vmetrics" aria-label="Key metrics">
      {metrics.map((metric) => (
        <MetricCard key={metric.id} metric={metric} currency={currency} onDrilldown={onDrilldown} />
      ))}
    </section>
  )
}

/* ------------------------------------------------------------------- bars */

export function Bar({ label, value, percent, tone }: {
  label: ReactNode
  value: ReactNode
  percent: number | null
  tone?: 'warning' | 'danger' | 'muted'
}) {
  const width = percent === null ? 0 : Math.max(0, Math.min(100, percent))
  return (
    <div className="vbar">
      <div className="vbar__label">
        <span>{label}</span>
        <strong>{value}</strong>
      </div>
      <div
        className="vbar__track"
        role="img"
        aria-label={percent === null ? 'No measurement' : `${width}%`}
      >
        <div className={`vbar__fill${tone ? ` vbar__fill--${tone}` : ''}`} style={{ width: `${width}%` }} />
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ states */

export function LoadingRows({ rows = 4, height = 54 }: { rows?: number; height?: number }) {
  return (
    <div className="vstack vstack--tight" aria-busy="true" aria-live="polite">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="vskeleton" style={{ height }} />
      ))}
    </div>
  )
}

export function EmptyState({ title, body, action, icon: Icon = Info }: {
  title: string
  body?: ReactNode
  action?: ReactNode
  icon?: typeof Info
}) {
  return (
    <div className="vstate">
      <Icon size={26} className="vstate__icon" aria-hidden="true" />
      <p className="vstate__title">{title}</p>
      {body ? <p className="vstate__body">{body}</p> : null}
      {action}
    </div>
  )
}

/**
 * Another product could not be reached.
 *
 * Deliberately NOT an empty state. "Aicountly Calendar is unavailable" and
 * "there are no appointments" are different facts, and a screen that shows the
 * second when the first is true is how somebody double-books a room.
 */
export function UnavailableState({ what, reason, onRetry, action }: {
  what: string
  reason?: string | null
  onRetry?: () => void
  action?: ReactNode
}) {
  return (
    <div className="vstate">
      <PlugZap size={26} className="vstate__icon" aria-hidden="true" />
      <p className="vstate__title">{what} is unavailable</p>
      <p className="vstate__body">
        {reason ?? 'This information could not be loaded right now. It has not been replaced with anything stored locally.'}
      </p>
      {onRetry ? <Button icon={RefreshCw} onClick={onRetry} size="sm">Try again</Button> : null}
      {action}
    </div>
  )
}

export function ForbiddenState({ what }: { what: string }) {
  return (
    <div className="vstate">
      <Ban size={26} className="vstate__icon" aria-hidden="true" />
      <p className="vstate__title">You do not have access to {what}</p>
      <p className="vstate__body">Ask an administrator for the permission if you need it.</p>
    </div>
  )
}

export function Notice({ tone = 'info', title, children, action }: {
  tone?: 'info' | 'warning' | 'danger'
  title?: ReactNode
  children: ReactNode
  action?: ReactNode
}) {
  const Icon = tone === 'danger' ? AlertTriangle : tone === 'warning' ? AlertTriangle : Info
  return (
    <div className={`vnotice vnotice--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon size={16} aria-hidden="true" style={{ flexShrink: 0, marginTop: 1 }} />
      <div className="vnotice__body">
        {title ? <strong style={{ display: 'block', marginBottom: 2 }}>{title}</strong> : null}
        {children}
      </div>
      {action}
    </div>
  )
}

/**
 * One place that decides which of the six states a panel is in.
 *
 * Pages pass their ApiState and a renderer. The distinction between forbidden,
 * unavailable and empty is made HERE so it is made the same way everywhere.
 */
export function PanelState<T>({
  state, children, empty, isEmpty, what = 'this',
}: {
  state: { data: T | null; error: ApiError | null; loading: boolean; reload: () => void }
  children: (data: T) => ReactNode
  empty?: ReactNode
  isEmpty?: (data: T) => boolean
  what?: string
}) {
  if (state.loading && state.data === null) return <LoadingRows />

  if (state.error) {
    if (state.error.isForbidden) return <ForbiddenState what={what} />
    if (state.error.isOwnerUnavailable || state.error.isProviderMissing) {
      return <UnavailableState what={what} reason={state.error.message} onRetry={state.error.retryable ? state.reload : undefined} />
    }
    return (
      <div className="vstate">
        <AlertTriangle size={26} className="vstate__icon" aria-hidden="true" />
        <p className="vstate__title">That did not load</p>
        <p className="vstate__body">{state.error.message}</p>
        {state.error.retryable ? <Button icon={RefreshCw} onClick={state.reload} size="sm">Try again</Button> : null}
      </div>
    )
  }

  if (state.data === null) return <LoadingRows />

  if (isEmpty?.(state.data)) {
    return <>{empty ?? <EmptyState title={`Nothing here yet`} />}</>
  }

  return <>{children(state.data)}</>
}

/* ------------------------------------------------------------------ drawer */

export function Drawer({ title, subtitle, onClose, children, footer }: {
  title: ReactNode
  subtitle?: ReactNode
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  const panel = useRef<HTMLDivElement>(null)
  const titleId = useId()

  // Focus moves into the drawer, Escape closes it, and the page behind does not
  // scroll away underneath.
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    panel.current?.focus()

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)

    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
      previous?.focus?.()
    }
  }, [onClose])

  return (
    <div className="vdrawer">
      <button className="vdrawer__scrim" aria-label="Close" onClick={onClose} />
      <div
        className="vdrawer__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        ref={panel}
      >
        <div className="vdrawer__head">
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId} style={{ margin: 0 }}>{title}</h2>
            {subtitle ? <p className="vcard__subtitle">{subtitle}</p> : null}
          </div>
          <Button icon={X} variant="ghost" onClick={onClose} title="Close" aria-label="Close" />
        </div>
        {children}
        {footer ? <div className="vactions">{footer}</div> : null}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------- forms */

export function Field({ label, hint, error, children }: {
  label: ReactNode
  hint?: ReactNode
  error?: string
  children: ReactNode
}) {
  return (
    <label className={`vfield${error ? ' vfield--invalid' : ''}`}>
      {label}
      {hint ? <span className="vfield__hint">{hint}</span> : null}
      {children}
      {error ? <span className="vfield__error">{error}</span> : null}
    </label>
  )
}

export function Select({ value, onChange, options, label }: {
  value: string
  onChange: (value: string) => void
  options: Array<{ value: string; label: string; disabled?: boolean }>
  label: string
}) {
  return (
    <>
      <span className="sr-only">{label}</span>
      <select className="vinput" value={value} onChange={(event) => onChange(event.target.value)} aria-label={label}>
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
    </>
  )
}

/* ---------------------------------------------------------------- freshness */

/**
 * When this was computed, and where it came from.
 *
 * Operational screens go stale in seconds. A reader who can see the figure is
 * four minutes old, and that one panel came from Calendar, can tell which
 * number to distrust when something looks wrong.
 */
export function FreshnessFooter({ generatedAt, sources, connection, onRefresh }: {
  generatedAt?: string | null
  sources?: Array<{ name: string; kind: string }>
  connection?: 'connecting' | 'live' | 'reconnecting' | 'offline' | 'disabled'
  onRefresh?: () => void
}) {
  const connectionLabel: Record<string, string> = {
    live: 'Live',
    connecting: 'Connecting…',
    reconnecting: 'Reconnecting — figures may be out of date',
    offline: 'Live updates unavailable — refresh to see changes',
    disabled: 'Live updates off for this deployment',
  }

  return (
    <footer className="vfoot">
      {connection ? (
        <span className="vsplit">
          {connection === 'live' ? (
            <CheckCircle2 size={12} aria-hidden="true" style={{ color: 'var(--primary)' }} />
          ) : connection === 'connecting' ? (
            <Loader2 size={12} aria-hidden="true" />
          ) : (
            <AlertTriangle size={12} aria-hidden="true" style={{ color: 'var(--warning)' }} />
          )}
          {connectionLabel[connection]}
        </span>
      ) : null}
      {generatedAt ? <span>Computed {timeAgo(generatedAt)}</span> : null}
      {sources && sources.length > 0 ? (
        <span>Sources: {sources.map((source) => source.name).join(', ')}</span>
      ) : null}
      {onRefresh ? (
        <Button variant="ghost" size="sm" icon={RefreshCw} onClick={onRefresh}>Refresh</Button>
      ) : null}
    </footer>
  )
}

export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return 'never'
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return 'unknown'
  const seconds = Math.round((Date.now() - then) / 1000)
  if (seconds < 5) return 'just now'
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.round(hours / 24)}d ago`
}

/* --------------------------------------------------------------- date/time */

/**
 * Rendered in the COMPANY'S timezone, not the browser's.
 *
 * A supervisor in London looking at a Bangalore operation needs Bangalore's
 * clock, because that is the clock the queue is being measured against.
 * Everything is stored and transmitted in UTC.
 */
export function formatTime(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit', minute: '2-digit', timeZone: timezone,
  }).format(date)
}

export function formatDate(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: timezone,
  }).format(date)
}

export function formatDateTime(iso: string | null | undefined, timezone: string): string {
  if (!iso) return '—'
  return `${formatDate(iso, timezone)}, ${formatTime(iso, timezone)}`
}

export { ChevronDown }
