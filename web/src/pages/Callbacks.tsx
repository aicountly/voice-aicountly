/**
 * The callback queue.
 *
 * A callback is Voice's own plan to ring somebody back, and its due time is
 * kept here. When asked, that time is also blocked in Aicountly Calendar: a
 * busy entry in the assigned agent's diary (the creator's when nobody is
 * assigned), which Voice moves or cancels with the callback. The entry carries
 * no customer detail and is not a reminder. This page says exactly that, and
 * shows what Calendar last told Voice about each entry — never a "synced" it
 * cannot back.
 */

import { useCallback, useState } from 'react'
import { CalendarClock, Plus } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type { Callback, CallbackDiary, DiaryCheck, ListResponse } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, Drawer, EmptyState, Field, Notice, PanelState, Row, Select,
  StatusPill, formatDateTime, formatTime,
} from '../ui'

/** Whether this deployment has Calendar switched on, from the queue's meta. Configured, not proven. */
interface CalendarSwitch {
  enabled: boolean
  reason: string | null
}

/** A few words per diary state. The backend's own sentence follows them. */
const DIARY_LABEL: Record<CallbackDiary['state'], string> = {
  none: '',
  linked: 'In the diary',
  pending: 'Diary entry being written',
  unknown: 'Diary entry not confirmed yet',
  deferred: 'Diary entry waiting on Calendar',
  refused: 'Not in the diary',
  failed: 'Not in the diary',
  cancelled: 'Diary entry cancelled',
  not_connected: 'Not in the diary',
  legacy: 'Diary entry unverified',
}

export default function Callbacks() {
  const { timezone, can, company, branchId } = useVoice()
  const [filters, setFilters] = useUrlState({ status: '', overdue: '' })
  const [creating, setCreating] = useState(false)

  const state = useApi<ListResponse<Callback>>(
    (signal) => api.get('v1/callbacks', { status: filters.status, overdue: filters.overdue, limit: 100 }, signal),
    [company?.cmp_id, branchId, filters.status, filters.overdue],
  )
  const calendar = (state.data?.meta.calendar ?? null) as CalendarSwitch | null

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
            empty={
              <EmptyState
                title="Nothing to call back"
                body="A callback appears here when someone creates one — with New callback, or from a product connected to Voice."
              />
            }
          >
            {(data) => (
              <>
                {data.data.map((callback) => (
                  <Row
                    key={callback.callback_id}
                    title={`#${callback.callback_id} · ${callback.e164_masked}`}
                    detail={
                      <>
                        {callback.reason || 'No reason recorded'}
                        {callback.due_at ? ` · due ${formatDateTime(callback.due_at, timezone)}` : ' · no due time'}
                        {callback.due_at && callback.exact_time ? ' (time promised to the caller)' : ''}
                        {callback.attempts > 0 ? ` · ${callback.attempts} of ${callback.max_attempts} attempts` : ''}
                        {callback.calendar.state !== 'none' || callback.calendar.detail ? (
                          <span style={{ display: 'block', marginTop: 2 }}>
                            <CalendarClock size={11} aria-hidden="true" style={{ verticalAlign: -1, marginRight: 3 }} />
                            {[DIARY_LABEL[callback.calendar.state], callback.calendar.detail].filter(Boolean).join(' — ')}
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

      {creating ? (
        <NewCallback calendar={calendar} timezone={timezone} onClose={() => setCreating(false)} onCreated={state.reload} />
      ) : null}
    </>
  )
}

function NewCallback({ calendar, timezone, onClose, onCreated }: {
  calendar: CalendarSwitch | null
  timezone: string
  onClose: () => void
  onCreated: () => void
}) {
  const [number, setNumber] = useState('')
  const [reason, setReason] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [withCalendar, setWithCalendar] = useState(false)
  const [exactTime, setExactTime] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [diary, setDiary] = useState<DiaryCheck | null>(null)

  // Unknown (the queue did not load) is not "off": the server answers for itself.
  const calendarOff = calendar !== null && !calendar.enabled
  const dueIso = dueAt ? new Date(dueAt).toISOString() : null

  const create = useMutation(() =>
    api.post<{ data: Callback; message: string | null }>('v1/callbacks', {
      e164: number.trim(),
      reason: reason.trim(),
      // Sent as UTC. The picker is local; the wire is not.
      due_at: dueIso,
      exact_time: dueIso !== null && withCalendar && exactTime,
      create_calendar_event: withCalendar && !calendarOff,
    }),
  )

  const check = useMutation(() =>
    api.get<{ data: DiaryCheck }>('v1/callbacks/diary-check', { due_at: dueIso }),
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

  const onCheck = useCallback(async () => {
    setDiary(null)
    const result = await check.mutate()
    if (result) setDiary(result.data)
  }, [check])

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
          <input
            type="datetime-local"
            value={dueAt}
            onChange={(event) => {
              setDueAt(event.target.value)
              setDiary(null)
            }}
          />
        </Field>

        <label className="vsplit" style={{ fontSize: 13 }}>
          <input
            type="checkbox"
            checked={withCalendar && !calendarOff}
            disabled={calendarOff}
            onChange={(event) => setWithCalendar(event.target.checked)}
          />
          Block this time in my Aicountly Calendar diary
        </label>
        <p className="vmuted vsmall" style={{ margin: 0 }}>
          {calendarOff
            ? `Aicountly Calendar is not connected to Voice in this deployment, so no diary entry can be made.${calendar?.reason ? ` ${calendar.reason}` : ''}`
            : 'Voice adds a 15-minute busy entry, titled only with this callback’s reference (for example “Callback · #42”), to your diary — or to the assigned agent’s, if one is assigned. The phone number, the caller and the reason stay in Voice. Voice moves or cancels the entry when this callback is rescheduled, reassigned or cancelled here; it cannot be moved from Calendar. It is not a reminder: Voice sends none.'}
        </p>

        {withCalendar && !calendarOff ? (
          <>
            <label className="vsplit" style={{ fontSize: 13 }}>
              <input
                type="checkbox"
                checked={exactTime}
                disabled={!dueAt}
                onChange={(event) => setExactTime(event.target.checked)}
              />
              The caller was promised this exact time
            </label>
            <p className="vmuted vsmall" style={{ margin: 0 }}>
              Calendar then refuses the entry if your diary is already busy at that time, instead of double-booking
              you, and Voice tells you. The callback is saved either way.
            </p>
            <div>
              <Button size="sm" onClick={() => void onCheck()} disabled={!dueAt || check.pending}>
                {check.pending ? 'Checking…' : 'Check my diary for that time'}
              </Button>
            </div>
            {diary ? (
              <Notice tone={diary.state === 'free' ? 'info' : 'warning'}>
                {diary.message}
                {diary.conflicts.length > 0
                  ? ` Busy ${diary.conflicts
                    .map((c) => (c.all_day ? 'all day' : `${formatTime(c.start_at, timezone)}–${formatTime(c.end_at, timezone)}`))
                    .join(', ')}.`
                  : ''}
              </Notice>
            ) : null}
            {check.error ? <Notice tone="warning">{check.error.message}</Notice> : null}
          </>
        ) : null}

        {message ? <Notice tone="warning">{message}</Notice> : null}
        {create.error ? <Notice tone="danger">{create.error.message}</Notice> : null}
      </div>
    </Drawer>
  )
}
