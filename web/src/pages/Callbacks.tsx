/**
 * The callback queue.
 *
 * A callback is Voice's own plan to ring somebody back. Where one also occupies
 * time in a diary, `calendar_event_ref` points at the event IN CALENDAR — the
 * time is read from there, never stored here, so it does not go stale when
 * somebody moves it.
 */

import { useCallback, useState } from 'react'
import { CalendarClock, Plus } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { Callback, ListResponse } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, Drawer, EmptyState, Field, Notice, PanelState, Row, Select,
  StatusPill, formatDateTime,
} from '../ui'

export default function Callbacks() {
  const { timezone, can, company, branchId } = useVoice()
  const [filters, setFilters] = useUrlState({ status: '', overdue: '' })
  const [creating, setCreating] = useState(false)

  const state = useApi<ListResponse<Callback>>(
    (signal) => api.get('v1/callbacks', { status: filters.status, overdue: filters.overdue, limit: 100 }, signal),
    [company?.cmp_id, branchId, filters.status, filters.overdue],
  )

  return (
    <>
      <PageHeader
        title="Callbacks"
        subtitle="Who to ring back, and by when."
        actions={
          can('voice.callbacks.manage') ? (
            <Button variant="primary" icon={Plus} onClick={() => setCreating(true)}>New callback</Button>
          ) : null
        }
      />

      <Card>
        <div className="vsplit">
          <Select
            label="Status"
            value={filters.status}
            onChange={(status) => setFilters({ status })}
            options={[
              { value: '', label: 'All' },
              { value: 'open', label: 'Open' },
              { value: 'scheduled', label: 'Scheduled' },
              { value: 'in_progress', label: 'In progress' },
              { value: 'completed', label: 'Completed' },
            ]}
          />
          <Button
            size="sm"
            variant={filters.overdue === '1' ? 'primary' : 'default'}
            onClick={() => setFilters({ overdue: filters.overdue === '1' ? '' : '1' })}
          >
            Overdue only
          </Button>
        </div>
      </Card>

      <div style={{ marginTop: 20 }}>
        <Card>
          <PanelState
            state={state}
            what="callbacks"
            isEmpty={(data) => data.data.length === 0}
            empty={<EmptyState title="Nothing to call back" body="Callbacks appear here when a caller asks for one or a call goes unanswered." />}
          >
            {(data) => (
              <>
                {data.data.map((callback) => (
                  <Row
                    key={callback.callback_id}
                    title={callback.e164_masked}
                    detail={
                      <>
                        {callback.reason || 'No reason recorded'}
                        {callback.due_at ? ` · due ${formatDateTime(callback.due_at, timezone)}` : ' · no due time'}
                        {callback.attempts > 0 ? ` · ${callback.attempts} of ${callback.max_attempts} attempts` : ''}
                        {callback.calendar_event_ref ? (
                          <span style={{ display: 'block', marginTop: 2 }}>
                            <CalendarClock size={11} aria-hidden="true" style={{ verticalAlign: -1, marginRight: 3 }} />
                            In a diary in Aicountly Calendar
                          </span>
                        ) : null}
                      </>
                    }
                    trailing={
                      <div className="vsplit">
                        {callback.priority !== 'normal' ? (
                          <Badge tone={callback.priority === 'high' ? 'red' : 'neutral'}>{callback.priority}</Badge>
                        ) : null}
                        <StatusPill status={callback.status} />
                      </div>
                    }
                  />
                ))}
                <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>
                  {String(data.meta.calendar_note ?? '')}
                </p>
              </>
            )}
          </PanelState>
        </Card>
      </div>

      {creating ? <NewCallback onClose={() => setCreating(false)} onCreated={state.reload} /> : null}
    </>
  )
}

function NewCallback({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [number, setNumber] = useState('')
  const [reason, setReason] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [withCalendar, setWithCalendar] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const create = useMutation(() =>
    api.post<{ data: Callback; message: string | null }>('v1/callbacks', {
      e164: number.trim(),
      reason: reason.trim(),
      // Sent as UTC. The picker is local; the wire is not.
      due_at: dueAt ? new Date(dueAt).toISOString() : null,
      create_calendar_event: withCalendar,
    }),
  )

  const onSave = useCallback(async () => {
    const result = await create.mutate()
    if (result) {
      onCreated()
      // The callback was created even when the diary entry was not — that
      // partial outcome is shown rather than closing on a half-success.
      if (result.message) setMessage(result.message)
      else onClose()
    }
  }, [create, onCreated, onClose])

  return (
    <Drawer
      title="New callback"
      onClose={onClose}
      footer={
        message ? (
          <Button onClick={onClose}>Close</Button>
        ) : (
          <>
            <Button variant="primary" onClick={() => void onSave()} disabled={create.pending || number.trim().length < 8}>
              {create.pending ? 'Saving…' : 'Create callback'}
            </Button>
            <Button onClick={onClose}>Cancel</Button>
          </>
        )
      }
    >
      <div className="vstack vstack--tight">
        <Field label="Number" hint="In international form.">
          <input value={number} onChange={(event) => setNumber(event.target.value)} placeholder="+91…" inputMode="tel" />
        </Field>
        <Field label="Reason">
          <input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="What is this about?" />
        </Field>
        <Field label="Due" hint="Your local time. Stored in UTC.">
          <input type="datetime-local" value={dueAt} onChange={(event) => setDueAt(event.target.value)} />
        </Field>

        <label className="vsplit" style={{ fontSize: 13 }}>
          <input type="checkbox" checked={withCalendar} onChange={(event) => setWithCalendar(event.target.checked)} />
          Also put it in my diary
        </label>
        <p className="vmuted vsmall" style={{ margin: 0 }}>
          The diary entry is created in Aicountly Calendar. Voice keeps only its reference, so moving it there moves
          it everywhere.
        </p>

        {message ? <Notice tone="warning">{message}</Notice> : null}
        {create.error ? <Notice tone="danger">{create.error.message}</Notice> : null}
      </div>
    </Drawer>
  )
}
