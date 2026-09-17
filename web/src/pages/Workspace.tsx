/**
 * The remaining management screens.
 *
 * Numbers, queues, agents, recordings, voicemail, reports, integrations,
 * settings and the audit trail. They share their shape entirely — one list from
 * one endpoint, gated by one permission — so they live together rather than in
 * nine near-identical files.
 */

import { useCallback, useState } from 'react'
import {
  AlertTriangle, CheckCircle2, Hash, Headphones, ShieldCheck, TestTube2,
} from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import type {
  AgentSummary, AuditEvent, IntegrationStatus, ListResponse, Recording,
  VoiceNumber, VoiceSettings,
} from '../services/types'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, EmptyState, Field, Notice, PanelState, Row, Select,
  StatusPill, formatDateTime, formatDuration, timeAgo,
} from '../ui'

/* ------------------------------------------------------------------ numbers */

export function Numbers() {
  const { company, branchId } = useVoice()
  const state = useApi<{ data: VoiceNumber[] }>(
    (signal) => api.get('v1/numbers', undefined, signal),
    [company?.cmp_id, branchId],
  )

  return (
    <>
      <PageHeader title="Numbers" subtitle="The numbers customers reach you on, and where each one goes." />
      <Card>
        <PanelState
          state={state}
          what="numbers"
          isEmpty={(data) => data.data.length === 0}
          empty={<EmptyState title="No business numbers" body="Add a number so customers can call you." icon={Hash} />}
        >
          {(data) => (
            <div className="vtable-wrap">
              <table className="vtable">
                <caption className="sr-only">Business numbers</caption>
                <thead>
                  <tr>
                    <th scope="col">Number</th>
                    <th scope="col">Team</th>
                    <th scope="col">Inbound flow</th>
                    <th scope="col">Recording</th>
                    <th scope="col">Routing</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((number) => (
                    <tr key={number.number_id}>
                      <td>
                        <strong>{number.masked}</strong>
                        <small>{number.label || number.number_type}</small>
                      </td>
                      <td>{number.team_name ?? '—'}</td>
                      <td>{number.flow_name ?? 'Not routed'}</td>
                      <td>
                        {number.recording_policy === 'inherit' ? 'Company default' : number.recording_policy}
                      </td>
                      <td>
                        <StatusPill status={number.is_active ? number.routing_status : 'not_configured'} />
                        {number.status_detail ? <small>{number.status_detail}</small> : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </PanelState>
      </Card>
    </>
  )
}

/* ------------------------------------------------------------------- queues */

interface QueueDetail {
  queue_id: number
  name: string
  kind: string
  strategy: string
  ring_seconds: number
  timeout_seconds: number
  overflow_type: string | null
  overflow_ref: string | null
  max_waiting: number
  is_active: boolean
  members: Array<{ queue_member_id: number; agent_id: number | null; team_id: number | null; extension: string | null; team_name: string | null }>
  warnings: string[]
}

export function Queues() {
  const { company, branchId } = useVoice()
  const state = useApi<{ data: QueueDetail[] }>(
    (signal) => api.get('v1/queues', undefined, signal),
    [company?.cmp_id, branchId],
  )

  return (
    <>
      <PageHeader title="Queues & Ring Groups" subtitle="Where an inbound call goes, and what happens if nobody answers." />
      <Card>
        <PanelState
          state={state}
          what="queues"
          isEmpty={(data) => data.data.length === 0}
          empty={<EmptyState title="No queues configured" body="A queue holds callers until somebody can take them." />}
        >
          {(data) =>
            data.data.map((queue) => (
              <div key={queue.queue_id} style={{ padding: '16px 0', borderBottom: '1px solid var(--border)' }}>
                <div className="vspread">
                  <div>
                    <strong style={{ fontSize: 14 }}>{queue.name}</strong>
                    <p className="vmuted vsmall" style={{ margin: '3px 0 0' }}>
                      {queue.kind === 'ring_group' ? 'Ring group' : 'Queue'} · {queue.strategy.replace(/_/g, ' ')} ·
                      rings {queue.ring_seconds}s · times out after {queue.timeout_seconds}s
                    </p>
                  </div>
                  <StatusPill status={queue.is_active ? 'connected' : 'not_configured'} />
                </div>

                <p className="vmuted vsmall" style={{ margin: '8px 0 0' }}>
                  {queue.members.length} member{queue.members.length === 1 ? '' : 's'}
                  {queue.overflow_type
                    ? ` · overflows to ${queue.overflow_type}${queue.overflow_ref ? ` (${queue.overflow_ref})` : ''}`
                    : ''}
                  {queue.max_waiting > 0 ? ` · at most ${queue.max_waiting} waiting` : ''}
                </p>

                {queue.warnings.map((warning) => (
                  <p key={warning} className="vsmall" style={{ color: 'var(--warning)', margin: '6px 0 0' }}>
                    <AlertTriangle size={11} aria-hidden="true" style={{ verticalAlign: -1, marginRight: 4 }} />
                    {warning}
                  </p>
                ))}
              </div>
            ))
          }
        </PanelState>
      </Card>
    </>
  )
}

/* ------------------------------------------------------------------- agents */

export function Agents() {
  const { company, branchId } = useVoice()
  const state = useApi<{
    data: {
      agents: AgentSummary[]
      summary: Record<string, number>
      teams: Array<{ team_id: number; name: string; description: string; members: number; is_active: boolean }>
      note: string
    }
  }>(
    (signal) => api.get('v1/agents', undefined, signal),
    [company?.cmp_id, branchId],
  )

  return (
    <>
      <PageHeader title="Agents & Teams" subtitle="Who takes calls, on which extension, with which skills." />
      <PanelState state={state} what="agents">
        {(data) => (
          <div className="vgrid vgrid--two">
            <Card title="Agents" subtitle={`${data.data.summary.available ?? 0} available of ${data.data.summary.total ?? 0}.`}>
              {data.data.agents.length === 0 ? (
                <EmptyState title="No agents yet" body="Add the people who take calls." icon={Headphones} />
              ) : (
                data.data.agents.map((agent) => (
                  <Row
                    key={agent.agent_id}
                    title={agent.extension ? `Extension ${agent.extension}` : agent.user_uuid.slice(0, 14)}
                    detail={
                      <>
                        {agent.voice_role}
                        {agent.skills.length > 0 ? ` · ${agent.skills.join(', ')}` : ''}
                        {agent.languages.length > 0 ? ` · ${agent.languages.join(', ')}` : ''}
                        {agent.presence_is_stale ? ' · console stopped reporting' : ''}
                      </>
                    }
                    trailing={<StatusPill status={agent.presence} />}
                  />
                ))
              )}
              <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>{data.data.note}</p>
            </Card>

            <Card title="Teams">
              {data.data.teams.length === 0 ? (
                <EmptyState title="No teams" body="Teams group agents for routing." />
              ) : (
                data.data.teams.map((team) => (
                  <Row
                    key={team.team_id}
                    title={team.name}
                    detail={`${team.members} member${team.members === 1 ? '' : 's'}${team.description ? ` · ${team.description}` : ''}`}
                    trailing={<StatusPill status={team.is_active ? 'connected' : 'not_configured'} />}
                  />
                ))
              )}
            </Card>
          </div>
        )}
      </PanelState>
    </>
  )
}

/* --------------------------------------------------------------- recordings */

export function Recordings() {
  const { company, branchId, timezone } = useVoice()

  const recordings = useApi<ListResponse<Recording>>(
    (signal) => api.get('v1/recordings', { limit: 50 }, signal),
    [company?.cmp_id, branchId],
  )

  const voicemail = useApi<ListResponse<{
    voicemail_id: number
    from_masked: string | null
    duration_seconds: number
    status: string
    received_at: string
    recording_uuid: string | null
  }>>(
    (signal) => api.get('v1/voicemail', { limit: 50 }, signal),
    [company?.cmp_id, branchId],
  )

  return (
    <>
      <PageHeader
        title="Recordings & Voicemail"
        subtitle="Opening a recording is recorded in the audit trail."
      />

      <div className="vgrid vgrid--two">
        <Card title="Recordings">
          <PanelState
            state={recordings}
            what="recordings"
            isEmpty={(data) => data.data.length === 0}
            empty={<EmptyState title="No recordings" body="Calls are recorded only where the company's policy says so." />}
          >
            {(data) =>
              data.data.map((recording) => (
                <Row
                  key={recording.recording_uuid}
                  title={formatDateTime(recording.created_at, timezone)}
                  detail={
                    <>
                      {formatDuration(recording.duration_seconds)}
                      {recording.delete_after ? ` · deleted ${timeAgo(recording.delete_after)}` : ' · kept indefinitely'}
                      {recording.legal_hold ? ' · on legal hold' : ''}
                    </>
                  }
                  trailing={<StatusPill status={recording.status} />}
                />
              ))
            }
          </PanelState>
        </Card>

        <Card title="Voicemail">
          <PanelState
            state={voicemail}
            what="voicemail"
            isEmpty={(data) => data.data.length === 0}
            empty={<EmptyState title="No voicemail" />}
          >
            {(data) =>
              data.data.map((message) => (
                <Row
                  key={message.voicemail_id}
                  title={message.from_masked ?? 'Unknown number'}
                  detail={`${formatDateTime(message.received_at, timezone)} · ${formatDuration(message.duration_seconds)}`}
                  trailing={<StatusPill status={message.status === 'new' ? 'warning' : 'connected'} />}
                />
              ))
            }
          </PanelState>
        </Card>
      </div>
    </>
  )
}

/* ------------------------------------------------------------- integrations */

export function Integrations() {
  const { company, branchId } = useVoice()
  const [probing, setProbing] = useState(false)

  const state = useApi<{ data: { integrations: IntegrationStatus[]; note: string } }>(
    (signal) => api.get('v1/integrations', probing ? { probe: '1' } : undefined, signal),
    [company?.cmp_id, branchId, probing],
  )

  return (
    <>
      <PageHeader
        title="Integrations"
        subtitle="What Voice may read from and write to, and whether it is working."
        actions={
          <Button icon={TestTube2} onClick={() => setProbing(true)} disabled={state.loading}>
            {state.loading && probing ? 'Testing…' : 'Test all'}
          </Button>
        }
      />

      <PanelState state={state} what="integrations">
        {(data) => (
          <>
            <div className="vgrid vgrid--even">
              {data.data.integrations.map((integration) => (
                <Card
                  key={integration.app}
                  title={integration.label}
                  subtitle={integration.purpose}
                  action={<StatusPill status={integration.status} />}
                >
                  {integration.reason ? (
                    <Notice tone={integration.status === 'not_configured' ? 'info' : 'warning'}>
                      <p>{integration.reason}</p>
                    </Notice>
                  ) : (
                    <p className="vmuted vsmall" style={{ margin: 0 }}>
                      {integration.last_ok_at ? `Last worked ${timeAgo(integration.last_ok_at)}.` : 'Configured.'}
                      {integration.checked_at ? ` Checked ${timeAgo(integration.checked_at)}.` : ''}
                    </p>
                  )}
                </Card>
              ))}
            </div>

            <Notice tone="info" title="How Voice reaches other products">
              <p>{data.data.note}</p>
            </Notice>
          </>
        )}
      </PanelState>
    </>
  )
}

/* ----------------------------------------------------------------- settings */

export function Settings() {
  const { company, branchId, can } = useVoice()
  const [draft, setDraft] = useState<Partial<VoiceSettings>>({})
  const [saved, setSaved] = useState(false)

  const state = useApi<{ data: VoiceSettings }>(
    (signal) => api.get('v1/settings', undefined, signal),
    [company?.cmp_id, branchId],
  )

  const save = useMutation((values: Partial<VoiceSettings>) =>
    api.post<{ data: VoiceSettings }>('v1/settings', values),
  )

  const onSave = useCallback(async () => {
    const result = await save.mutate(draft)
    if (result) {
      setSaved(true)
      setDraft({})
      state.reload()
    }
  }, [draft, save, state])

  return (
    <>
      <PageHeader title="Settings" subtitle="Policies this business chooses. Voice enforces what it is told." />

      <PanelState state={state} what="settings">
        {(data) => {
          const settings = { ...data.data, ...draft }
          const editable = can('voice.settings.manage')

          return (
            <>
              <Notice tone="info">
                <p>{data.data.policy_note}</p>
              </Notice>

              <div className="vgrid vgrid--even" style={{ marginTop: 20 }}>
                <Card title="Recording and disclosure">
                  <div className="vstack vstack--tight">
                    <Field label="Recording policy" hint="Defaults to never. Calls are not recorded unless somebody chooses to.">
                      <select
                        value={settings.recording_policy}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, recording_policy: event.target.value as VoiceSettings['recording_policy'] })}
                      >
                        <option value="never">Never record</option>
                        <option value="on_consent">Record after the caller agrees</option>
                        <option value="always">Always record</option>
                      </select>
                    </Field>

                    <label className="vsplit" style={{ fontSize: 13 }}>
                      <input
                        type="checkbox"
                        checked={settings.recording_disclosure}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, recording_disclosure: event.target.checked })}
                      />
                      Tell the caller the call is recorded
                    </label>

                    <label className="vsplit" style={{ fontSize: 13 }}>
                      <input
                        type="checkbox"
                        checked={settings.ai_disclosure}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, ai_disclosure: event.target.checked })}
                      />
                      Tell the caller they are speaking to an AI agent
                    </label>
                  </div>
                </Card>

                <Card title="Retention">
                  <div className="vstack vstack--tight">
                    <Field label="Keep recordings for (days)" hint="0 keeps them until somebody deletes them.">
                      <input
                        type="number"
                        min={0}
                        value={settings.recording_retention_days}
                        disabled={!editable || !can('voice.retention.manage')}
                        onChange={(event) => setDraft({ ...draft, recording_retention_days: Number(event.target.value) })}
                      />
                    </Field>
                    <Field label="Keep transcripts for (days)">
                      <input
                        type="number"
                        min={0}
                        value={settings.transcript_retention_days}
                        disabled={!editable || !can('voice.retention.manage')}
                        onChange={(event) => setDraft({ ...draft, transcript_retention_days: Number(event.target.value) })}
                      />
                    </Field>
                    <p className="vmuted vsmall" style={{ margin: 0 }}>
                      Deleting removes the audio, the transcript and its search index together. A legal hold overrides
                      the clock.
                    </p>
                  </div>
                </Card>

                <Card title="Calling window" subtitle={`Local time in ${settings.timezone}.`}>
                  <div className="vstack vstack--tight">
                    <Field label="From (minutes past midnight)">
                      <input
                        type="number"
                        min={0}
                        max={1440}
                        value={settings.calling_window_start_min}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, calling_window_start_min: Number(event.target.value) })}
                      />
                    </Field>
                    <Field label="To (minutes past midnight)">
                      <input
                        type="number"
                        min={0}
                        max={1440}
                        value={settings.calling_window_end_min}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, calling_window_end_min: Number(event.target.value) })}
                      />
                    </Field>
                    <p className="vmuted vsmall" style={{ margin: 0 }}>
                      Outbound campaigns dial only inside this window, checked per call at the moment of dialling.
                    </p>
                  </div>
                </Card>

                <Card title="Operations">
                  <div className="vstack vstack--tight">
                    <label className="vsplit" style={{ fontSize: 13 }}>
                      <input
                        type="checkbox"
                        checked={settings.supervisor_monitoring}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, supervisor_monitoring: event.target.checked })}
                      />
                      Allow supervisors to listen to calls in progress
                    </label>
                    <label className="vsplit" style={{ fontSize: 13 }}>
                      <input
                        type="checkbox"
                        checked={settings.campaign_approval_required}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, campaign_approval_required: event.target.checked })}
                      />
                      A campaign must be approved before it can launch
                    </label>
                    <Field label="Maximum concurrent calls" hint="0 uses the provider's ceiling only.">
                      <input
                        type="number"
                        min={0}
                        value={settings.max_concurrent_calls}
                        disabled={!editable}
                        onChange={(event) => setDraft({ ...draft, max_concurrent_calls: Number(event.target.value) })}
                      />
                    </Field>
                  </div>
                </Card>
              </div>

              {save.error ? <Notice tone="danger">{save.error.message}</Notice> : null}
              {saved && Object.keys(draft).length === 0 ? (
                <Notice tone="info">
                  <p>
                    <CheckCircle2 size={13} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 4 }} />
                    Settings saved.
                  </p>
                </Notice>
              ) : null}

              {editable ? (
                <div className="vactions">
                  <Button
                    variant="primary"
                    onClick={() => void onSave()}
                    disabled={save.pending || Object.keys(draft).length === 0}
                  >
                    {save.pending ? 'Saving…' : 'Save settings'}
                  </Button>
                  {Object.keys(draft).length > 0 ? (
                    <Button onClick={() => setDraft({})}>Discard changes</Button>
                  ) : null}
                </div>
              ) : (
                <Notice tone="info">
                  <p>You can see these settings but not change them.</p>
                </Notice>
              )}
            </>
          )
        }}
      </PanelState>
    </>
  )
}

/* -------------------------------------------------------------------- audit */

export function Audit() {
  const { company, branchId, timezone } = useVoice()
  const [filters, setFilters] = useUrlState({ action: '' })

  const state = useApi<ListResponse<AuditEvent>>(
    (signal) => api.get('v1/audit', { action: filters.action, limit: 100 }, signal),
    [company?.cmp_id, branchId, filters.action],
  )

  return (
    <>
      <PageHeader
        title="Audit"
        subtitle="Who did what, and when. Recording access, campaign launches, provider changes and retention edits."
      />

      <Card>
        <Select
          label="Action"
          value={filters.action}
          onChange={(action) => setFilters({ action })}
          options={[
            { value: '', label: 'All actions' },
            { value: 'voice.recording.played', label: 'Recording played' },
            { value: 'voice.recording.downloaded', label: 'Recording downloaded' },
            { value: 'voice.transcript.viewed', label: 'Transcript read' },
            { value: 'voice.call.monitored', label: 'Call monitored' },
            { value: 'voice.campaign.launched', label: 'Campaign launched' },
            { value: 'voice.provider.configured', label: 'Provider configured' },
            { value: 'voice.retention.changed', label: 'Retention changed' },
          ]}
        />
      </Card>

      <div style={{ marginTop: 20 }}>
        <Card>
          <PanelState
            state={state}
            what="the audit trail"
            isEmpty={(data) => data.data.length === 0}
            empty={<EmptyState title="Nothing recorded yet" icon={ShieldCheck} />}
          >
            {(data) =>
              data.data.map((event) => (
                <Row
                  key={event.audit_id}
                  title={event.action.replace(/^voice\./, '').replace(/\./g, ' ')}
                  detail={
                    <>
                      {formatDateTime(event.occurred_at, timezone)} ·{' '}
                      {event.actor_kind === 'provider'
                        ? 'telephony provider'
                        : event.actor_uuid ?? 'system'}
                      {event.entity_type ? ` · ${event.entity_type} ${event.entity_id ?? ''}` : ''}
                    </>
                  }
                  trailing={<Badge tone="neutral">{event.actor_kind}</Badge>}
                />
              ))
            }
          </PanelState>
        </Card>
      </div>
    </>
  )
}

/* ------------------------------------------------------------------ reports */

export function Reports() {
  const { company, branchId } = useVoice()
  const [period, setPeriod] = useState('30d')

  const state = useApi<{ data: { usage: unknown; budget: unknown } }>(
    (signal) => api.get('v1/usage', { period }, signal),
    [company?.cmp_id, branchId, period],
  )

  return (
    <>
      <PageHeader
        title="Reports"
        subtitle="Usage and cost, with estimated and provider-confirmed charges kept apart."
        actions={
          <Select
            label="Period"
            value={period}
            onChange={setPeriod}
            options={[
              { value: '7d', label: 'Last 7 days' },
              { value: '30d', label: 'Last 30 days' },
              { value: '90d', label: 'Last 90 days' },
            ]}
          />
        }
      />
      <Card>
        <PanelState state={state} what="reports">
          {() => (
            <EmptyState
              title="Usage reporting"
              body="Detailed usage is on the Network & Usage dashboard, where estimated and provider-confirmed charges are shown separately."
            />
          )}
        </PanelState>
      </Card>
    </>
  )
}
