/**
 * What the six dashboards share.
 *
 * Each is one request to /v1/dashboards/{view} and renders the metrics, panels
 * and definitions that came back. The period selector, the freshness footer and
 * the drilldown wiring live here so all six behave the same way.
 */

import { useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarDays } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, usePolling } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { DashboardEnvelope } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Button, FreshnessFooter, LoadingRows, MetricRow, Notice, Select, UnavailableState,
} from '../ui'
import type { LiveConnection } from '../hooks/useLiveEvents'

const PERIODS = [
  { value: 'today', label: 'Today' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
]

export function useDashboard<P>(view: string, pollMs = 0) {
  const { company, branchId } = useVoice()
  const [urlState, setUrlState] = useUrlState({ period: 'today' })

  const state = useApi<{ data: DashboardEnvelope<P> }>(
    (signal) => api.get(`v1/dashboards/${view}`, { period: urlState.period }, signal),
    [company?.cmp_id, branchId, view, urlState.period],
  )

  usePolling(state.reload, pollMs, pollMs > 0)

  const setPeriod = useCallback((period: string) => setUrlState({ period }), [setUrlState])

  return { ...state, period: urlState.period, setPeriod }
}

export function PeriodPicker({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return (
    <div className="vsplit">
      <CalendarDays size={15} aria-hidden="true" style={{ color: 'var(--muted)' }} />
      <Select label="Period" value={value} onChange={onChange} options={PERIODS} />
    </div>
  )
}

/**
 * The frame every dashboard renders inside.
 *
 * Handles the three ways a dashboard can fail to load — forbidden, another
 * product unavailable, or a plain error — so no individual dashboard has to.
 */
export function DashboardFrame<P>({
  title, subtitle, view, state, period, onPeriodChange, actions, connection, children, showPeriod = true,
}: {
  title: string
  subtitle: string
  /** Which dashboard this is. Keys the body so moving between them resets panel state. */
  view: string
  state: ReturnType<typeof useDashboard<P>>
  period: string
  onPeriodChange: (period: string) => void
  actions?: React.ReactNode
  connection?: LiveConnection
  children: (envelope: DashboardEnvelope<P>) => React.ReactNode
  showPeriod?: boolean
}) {
  const navigate = useNavigate()
  const { currency } = useVoice()

  const onDrilldown = useCallback(
    (route: string, params: Record<string, string>) => {
      const query = new URLSearchParams(params).toString()
      navigate(query ? `${route}?${query}` : route)
    },
    [navigate],
  )

  const envelope = state.data?.data ?? null

  const header = (
    <PageHeader
      title={title}
      subtitle={subtitle}
      actions={
        <>
          {showPeriod ? <PeriodPicker value={period} onChange={onPeriodChange} /> : null}
          {actions}
        </>
      }
    />
  )

  if (state.error) {
    return (
      <>
        {header}
        {state.error.isForbidden ? (
          <Notice tone="warning" title="You do not have access to this dashboard">
            <p>Ask an administrator for the permission if you need it.</p>
          </Notice>
        ) : (
          <UnavailableState
            what={title}
            reason={state.error.message}
            onRetry={state.error.retryable ? state.reload : undefined}
          />
        )}
      </>
    )
  }

  if (!envelope) {
    return (
      <>
        {header}
        {/* Reserved heights, so the page does not jump when the data lands. */}
        <div className="vmetrics">
          {[0, 1, 2, 3].map((index) => <div key={index} className="vskeleton" style={{ height: 132 }} />)}
        </div>
        <LoadingRows rows={3} height={200} />
      </>
    )
  }

  return (
    <>
      {header}
      <MetricRow metrics={envelope.metrics} currency={envelope.currency || currency} onDrilldown={onDrilldown} />
      {/* Keyed on the view so a selection made on one dashboard does not carry
          into the next one the user opens. */}
      <div key={view}>{children(envelope)}</div>
      <Definitions definitions={envelope.definitions} />
      <FreshnessFooter
        generatedAt={envelope.freshness.generated_at}
        sources={envelope.freshness.sources}
        connection={connection}
        onRefresh={state.reload}
      />
    </>
  )
}

/**
 * What the figures on this screen mean.
 *
 * Collapsed by default and one click away. An answer rate without its
 * denominator is a number two people read differently, and a dashboard that
 * cannot say which it used is one people stop trusting.
 */
function Definitions({ definitions }: { definitions: Record<string, string> }) {
  const entries = useMemo(() => Object.entries(definitions ?? {}), [definitions])
  if (entries.length === 0) return null

  return (
    <details className="vcard" style={{ marginBottom: 20 }}>
      <summary style={{ cursor: 'pointer', fontWeight: 650, fontSize: 13 }}>
        How these figures are calculated
      </summary>
      <dl className="vdl" style={{ marginTop: 14, gridTemplateColumns: 'minmax(120px, auto) 1fr' }}>
        {entries.map(([key, description]) => (
          <div key={key} style={{ display: 'contents' }}>
            <dt>{key.replace(/_/g, ' ')}</dt>
            <dd style={{ textAlign: 'left', fontWeight: 400 }}>{description}</dd>
          </div>
        ))}
      </dl>
    </details>
  )
}

export { Button }
