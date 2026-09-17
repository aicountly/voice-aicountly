/**
 * The call history.
 *
 * Server-side pagination — a busy company makes thousands of calls a week and
 * a client-side filter over all of them is a page that never finishes loading.
 *
 * Filters live in the URL so a link from the Command Centre lands on exactly
 * the calls it was counting.
 */

import { useState } from 'react'
import { PhoneCall, PhoneIncoming, PhoneOutgoing } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { Call, ListResponse } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, EmptyState, PanelState, Select, StatusPill,
  formatDateTime, formatDuration,
} from '../ui'
import { CallDrawer } from './CallDrawer'
import { ComposeCall } from './ComposeCall'

const PAGE_SIZE = 25

export default function Calls() {
  const { timezone, can, company, branchId } = useVoice()
  const [filters, setFilters] = useUrlState({
    direction: '', state: '', outcome: '', handled_by: '', abandoned: '', q: '', page: '1', compose: '',
  })
  const [openCall, setOpenCall] = useState<number | null>(null)

  const page = Math.max(1, Number(filters.page) || 1)

  const state = useApi<ListResponse<Call>>(
    (signal) =>
      api.get('v1/calls', {
        direction: filters.direction,
        state: filters.state,
        outcome: filters.outcome,
        handled_by: filters.handled_by,
        abandoned: filters.abandoned,
        q: filters.q,
        limit: PAGE_SIZE,
        offset: (page - 1) * PAGE_SIZE,
      }, signal),
    [company?.cmp_id, branchId, filters.direction, filters.state, filters.outcome, filters.handled_by, filters.abandoned, filters.q, page],
  )

  return (
    <>
      <PageHeader
        title="Calls"
        subtitle="Every conversation, with what came of it."
        actions={
          can('voice.call.place') ? (
            <Button variant="primary" icon={PhoneCall} onClick={() => setFilters({ compose: '1' })}>
              Make a call
            </Button>
          ) : null
        }
      />

      <Card>
        <div className="vsplit">
          <Select
            label="Direction"
            value={filters.direction}
            onChange={(direction) => setFilters({ direction })}
            options={[
              { value: '', label: 'Any direction' },
              { value: 'inbound', label: 'Inbound' },
              { value: 'outbound', label: 'Outbound' },
            ]}
          />
          <Select
            label="Outcome"
            value={filters.outcome}
            onChange={(outcome) => setFilters({ outcome })}
            options={[
              { value: '', label: 'Any outcome' },
              { value: 'ai_completed', label: 'Completed by AI' },
              { value: 'handover_completed', label: 'Handed over' },
              { value: 'human_completed', label: 'Handled by a person' },
              { value: 'no_answer', label: 'No answer' },
              { value: 'voicemail', label: 'Voicemail' },
              { value: 'failed', label: 'Failed' },
            ]}
          />
          <Select
            label="Handled by"
            value={filters.handled_by}
            onChange={(handled_by) => setFilters({ handled_by })}
            options={[
              { value: '', label: 'Anyone' },
              { value: 'ai', label: 'AI' },
              { value: 'human', label: 'A person' },
              { value: 'both', label: 'Both' },
            ]}
          />
          <input
            className="vinput"
            style={{ maxWidth: 220 }}
            value={filters.q}
            onChange={(event) => setFilters({ q: event.target.value })}
            placeholder="Number…"
            aria-label="Search by number"
          />
          {filters.abandoned === '1' ? (
            <Badge tone="amber">Abandoned only</Badge>
          ) : null}
        </div>
      </Card>

      <div style={{ marginTop: 20 }}>
        <Card flush>
          <PanelState
            state={state}
            what="calls"
            isEmpty={(data) => data.data.length === 0}
            empty={
              <EmptyState
                title="No calls match these filters"
                body="Try widening the period or clearing a filter."
              />
            }
          >
            {(data) => (
              <>
                <div className="vtable-wrap">
                  <table className="vtable">
                    <caption className="sr-only">Call history</caption>
                    <thead>
                      <tr>
                        <th scope="col">Number</th>
                        <th scope="col">When</th>
                        <th scope="col" className="vtable__num">Duration</th>
                        <th scope="col">Handled by</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">State</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.data.map((call) => (
                        <tr key={call.call_id} onClick={() => setOpenCall(call.call_id)} style={{ cursor: 'pointer' }}>
                          <td>
                            <strong className="vsplit">
                              {call.direction === 'inbound'
                                ? <PhoneIncoming size={12} aria-hidden="true" />
                                : <PhoneOutgoing size={12} aria-hidden="true" />}
                              {call.remote_masked ?? 'Unknown'}
                            </strong>
                            {call.contact_ref ? <small>Linked to a contact</small> : null}
                          </td>
                          <td>{formatDateTime(call.initiated_at, timezone)}</td>
                          <td className="vtable__num">{formatDuration(call.talk_seconds)}</td>
                          <td>{call.handled_by === 'unassigned' ? '—' : call.handled_by}</td>
                          <td>
                            {call.disposition ?? (call.outcome ? <StatusPill status={call.outcome} /> : '—')}
                          </td>
                          <td><StatusPill status={call.state} /></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="vspread" style={{ padding: '14px 22px', borderTop: '1px solid var(--border)' }}>
                  <span className="vmuted vsmall">
                    {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, data.meta.total)} of {data.meta.total}
                  </span>
                  <div className="vsplit">
                    <Button
                      size="sm"
                      disabled={page <= 1}
                      onClick={() => setFilters({ page: String(page - 1) })}
                    >
                      Previous
                    </Button>
                    <Button
                      size="sm"
                      disabled={page * PAGE_SIZE >= data.meta.total}
                      onClick={() => setFilters({ page: String(page + 1) })}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              </>
            )}
          </PanelState>
        </Card>
      </div>

      {openCall !== null ? <CallDrawer callId={openCall} onClose={() => setOpenCall(null)} /> : null}
      {filters.compose === '1' ? (
        <ComposeCall onClose={() => setFilters({ compose: '' })} onPlaced={state.reload} />
      ) : null}
    </>
  )
}
