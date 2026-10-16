/**
 * The audit trail.
 *
 * Who did what, and when: recording access, campaign launches, provider and
 * settings changes, access changes, and every edit to the records a calling
 * business runs on. The list is filtered on the server; a row opens a drawer with
 * the field-by-field changes (old to new) and the before/after of the record.
 *
 * What is NOT here, by design: call audio, transcripts, note text, full telephone
 * numbers and provider credentials. The trail records that a thing changed and
 * who changed it, not the conversation.
 */

import { useCallback, useState } from 'react'
import { Download, ShieldCheck } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api, downloadFile } from '../services/api'
import type { ListResponse } from '../services/api'
import type { AuditEvent } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, Drawer, EmptyState, Notice, PanelState, Row, Select, formatDateTime,
} from '../ui'

const PAGE = 50

const ACTIONS = [
  { value: '', label: 'All actions' },
  { value: 'voice.recording.*', label: 'Recordings' },
  { value: 'voice.transcript.*', label: 'Transcripts' },
  { value: 'voice.call.*', label: 'Calls' },
  { value: 'voice.callback.*', label: 'Callbacks' },
  { value: 'voice.campaign.*', label: 'Campaigns' },
  { value: 'voice.ai.*', label: 'AI agents' },
  { value: 'voice.call_flow.*', label: 'Call flows' },
  { value: 'voice.number.*', label: 'Numbers' },
  { value: 'voice.queue.*', label: 'Queues' },
  { value: 'voice.agent.*', label: 'Agents' },
  { value: 'voice.provider.*', label: 'Providers' },
  { value: 'voice.settings.*', label: 'Settings' },
  { value: 'voice.retention.*', label: 'Retention' },
  { value: 'voice.access.*', label: 'Access' },
  { value: 'voice.suppression.*', label: 'Do not call' },
  { value: 'voice.export.*', label: 'Exports' },
]

const SEVERITIES = [
  { value: '', label: 'Any severity' },
  { value: 'security', label: 'Security' },
  { value: 'material', label: 'Material' },
  { value: 'info', label: 'Information' },
]

function severityTone(severity: AuditEvent['severity']): 'red' | 'amber' | 'neutral' {
  return severity === 'security' ? 'red' : severity === 'material' ? 'amber' : 'neutral'
}

function actionLabel(action: string): string {
  return action.replace(/^voice\./, '').replace(/[._]/g, ' ')
}

function actorLabel(event: AuditEvent): string {
  if (event.actor_kind === 'provider') return 'Telephony provider'
  return event.actor_label ?? event.actor_uuid ?? 'System'
}

/** A stored value as a reader sees it. */
function show(value: unknown): string {
  if (value === null || value === undefined || value === '') return '(empty)'
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function StateList({ title, state }: { title: string; state: Record<string, unknown> }) {
  const entries = Object.entries(state)
  if (entries.length === 0) return null
  return (
    <section>
      <h3>{title}</h3>
      <dl className="vstack vstack--tight" style={{ margin: 0 }}>
        {entries.map(([key, value]) => (
          <div key={key} className="vspread">
            <dt className="vmuted vsmall">{key.replace(/_/g, ' ')}</dt>
            <dd style={{ margin: 0, textAlign: 'right', wordBreak: 'break-word' }}>{show(value)}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

function EventDrawer({ event, timezone, onClose }: { event: AuditEvent; timezone: string; onClose: () => void }) {
  return (
    <Drawer
      title={event.summary ?? actionLabel(event.action)}
      subtitle={`${formatDateTime(event.occurred_at, timezone)} · ${actorLabel(event)}`}
      onClose={onClose}
    >
      <div className="vstack">
        <div className="vspread">
          <Badge tone={severityTone(event.severity)}>{event.severity}</Badge>
          <span className="vmuted vsmall">{event.action}</span>
        </div>

        {event.entity_type ? (
          <p className="vmuted">
            {event.entity_type.replace(/_/g, ' ')}
            {event.entity_label ? ` · ${event.entity_label}` : ''}
            {event.entity_id ? ` · #${event.entity_id}` : ''}
          </p>
        ) : null}

        {event.changes.length > 0 ? (
          <section>
            <h3>Changes</h3>
            <div className="vtable-wrap">
              <table className="vtable">
                <thead>
                  <tr><th>Field</th><th>Was</th><th>Now</th></tr>
                </thead>
                <tbody>
                  {event.changes.map((change) => (
                    <tr key={change.field}>
                      <td>{change.label}</td>
                      <td>{show(change.old)}</td>
                      <td>{show(change.new)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        ) : null}

        <StateList title="Before" state={event.before} />
        <StateList title="After" state={event.after} />
        <StateList title="Detail" state={event.detail} />

        <p className="vmuted vsmall">
          {event.request_id ? <>Request {event.request_id}<br /></> : null}
          {event.user_agent ?? null}
        </p>
      </div>
    </Drawer>
  )
}

export function AuditTrail() {
  const { company, branchId, timezone, can } = useVoice()
  const [filters, setFilters] = useUrlState({
    action: '', severity: '', from: '', to: '', q: '', actor_uuid: '', entity_type: '', entity_id: '', page: '1',
  })
  const [open, setOpen] = useState<AuditEvent | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportNote, setExportNote] = useState<{ tone: 'info' | 'warning' | 'danger'; text: string } | null>(null)

  const page = Math.max(1, Number(filters.page) || 1)
  const query = {
    action: filters.action, severity: filters.severity, from: filters.from, to: filters.to, q: filters.q,
    actor_uuid: filters.actor_uuid, entity_type: filters.entity_type, entity_id: filters.entity_id,
  }

  const state = useApi<ListResponse<AuditEvent>>(
    (signal) => api.get('v1/audit-log', { ...query, limit: PAGE, offset: (page - 1) * PAGE }, signal),
    [company?.cmp_id, branchId, ...Object.values(query), page],
  )

  const exportCsv = useCallback(async () => {
    setExporting(true)
    setExportNote(null)
    try {
      const file = await downloadFile('v1/audit-log/export', query)
      const url = URL.createObjectURL(file.blob)
      const link = document.createElement('a')
      link.href = url
      link.download = file.filename
      link.click()
      URL.revokeObjectURL(url)
      if (file.truncated) {
        setExportNote({ tone: 'warning', text: 'The file holds only the first rows of this trail. Narrow the dates or filters to export the rest.' })
      }
    } catch (err) {
      setExportNote({ tone: 'danger', text: err instanceof Error ? err.message : 'The export failed.' })
    } finally {
      setExporting(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.action, filters.severity, filters.from, filters.to, filters.q, filters.actor_uuid, filters.entity_type, filters.entity_id])

  return (
    <>
      <PageHeader
        title="Audit trail"
        subtitle="Who did what, and when. Recording access, campaign launches, provider, settings and access changes, and edits to calling records. Call audio, transcripts and note text are never recorded here."
        actions={can('voice.audit.export')
          ? <Button icon={Download} onClick={() => void exportCsv()} disabled={exporting}>Export CSV</Button>
          : undefined}
      />

      <Card>
        <div className="vgrid vgrid--three" style={{ marginBottom: 0 }}>
          <Select label="Action" value={filters.action} onChange={(action) => setFilters({ action })} options={ACTIONS} />
          <Select label="Severity" value={filters.severity} onChange={(severity) => setFilters({ severity })} options={SEVERITIES} />
          <input
            className="vinput" type="search" aria-label="Search" placeholder="Search summary or record"
            value={filters.q} onChange={(event) => setFilters({ q: event.target.value })}
          />
          <label className="vmuted vsmall">From
            <input className="vinput" type="date" value={filters.from} onChange={(event) => setFilters({ from: event.target.value })} />
          </label>
          <label className="vmuted vsmall">To
            <input className="vinput" type="date" value={filters.to} onChange={(event) => setFilters({ to: event.target.value })} />
          </label>
          <input
            className="vinput" aria-label="Person" placeholder="Person (user id)"
            value={filters.actor_uuid} onChange={(event) => setFilters({ actor_uuid: event.target.value.trim() })}
          />
        </div>
      </Card>

      {exportNote ? <div style={{ marginTop: 12 }}><Notice tone={exportNote.tone}>{exportNote.text}</Notice></div> : null}

      <div style={{ marginTop: 20 }}>
        <Card>
          <PanelState
            state={state}
            what="the audit trail"
            isEmpty={(data) => data.data.length === 0}
            empty={<EmptyState title="Nothing recorded for these filters" icon={ShieldCheck} />}
          >
            {(data) => (
              <>
                {data.data.map((event) => (
                  <Row
                    key={event.audit_id}
                    onClick={() => setOpen(event)}
                    title={event.summary ?? actionLabel(event.action)}
                    detail={
                      <>
                        {formatDateTime(event.occurred_at, timezone)} · {actorLabel(event)} · {event.action}
                        {event.changes.length > 0 ? ` · ${event.changes.length} change${event.changes.length === 1 ? '' : 's'}` : ''}
                      </>
                    }
                    trailing={<Badge tone={severityTone(event.severity)}>{event.severity}</Badge>}
                  />
                ))}
                <div className="vspread" style={{ marginTop: 12 }}>
                  <span className="vmuted vsmall">
                    {data.meta.offset + 1}–{data.meta.offset + data.data.length} of {data.meta.total}
                  </span>
                  <span className="vactions" style={{ marginTop: 0 }}>
                    <Button variant="ghost" disabled={page <= 1} onClick={() => setFilters({ page: String(page - 1) })}>Newer</Button>
                    <Button variant="ghost" disabled={data.meta.offset + data.data.length >= data.meta.total} onClick={() => setFilters({ page: String(page + 1) })}>Older</Button>
                  </span>
                </div>
              </>
            )}
          </PanelState>
        </Card>
      </div>

      {open ? <EventDrawer event={open} timezone={timezone} onClose={() => setOpen(null)} /> : null}
    </>
  )
}

/**
 * The history of one record, for the drawers of the screens that show it.
 * Asks GET v1/audit-log/entity/{type}/{id}; shows nothing to somebody who may not read the trail.
 */
export function EntityHistory({ entityType, entityId }: { entityType: string; entityId: string | number }) {
  const { company, branchId, timezone, can } = useVoice()
  const allowed = can('voice.audit.view')
  const state = useApi<ListResponse<AuditEvent>>(
    (signal) => api.get(`v1/audit-log/entity/${encodeURIComponent(entityType)}/${encodeURIComponent(String(entityId))}`, { limit: 20 }, signal),
    [company?.cmp_id, branchId, entityType, entityId],
    { enabled: allowed },
  )
  if (!allowed) return null

  return (
    <section>
      <h3>History</h3>
      <PanelState
        state={state}
        what="this record’s history"
        isEmpty={(data) => data.data.length === 0}
        empty={<p className="vmuted vsmall">Nothing recorded yet.</p>}
      >
        {(data) => data.data.map((event) => (
          <Row
            key={event.audit_id}
            title={event.summary ?? actionLabel(event.action)}
            detail={`${formatDateTime(event.occurred_at, timezone)} · ${actorLabel(event)}${
              event.changes.length > 0 ? ' · ' + event.changes.map((c) => c.label).join(', ') : ''
            }`}
          />
        ))}
      </PanelState>
    </section>
  )
}
