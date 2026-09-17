/**
 * Dashboard 2 — Live Operations.
 *
 * The screen an agent has open while somebody is on the phone. Three columns:
 * the queue, the call, and what the Copilot suggests about it.
 *
 * Live updates come from the SSE stream. When that is unavailable the screen
 * polls the API on a short interval and says so in the footer — a screen that
 * silently stops updating during a busy hour is worse than one that admits it.
 */

import { useCallback, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Headphones, PhoneIncoming, PhoneOutgoing } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation, usePolling } from '../hooks/useApi'
import { useLiveEvents } from '../hooks/useLiveEvents'
import { api } from '../services/api'
import type { AgentSummary, Call, CallControls, QueueSummary, TranscriptSegment } from '../services/types'
import {
  Badge, Card, EmptyState, Notice, PanelState, Row, StatusPill,
  formatDuration, formatTime,
} from '../ui'
import { VoiceCallControls } from '../voice/VoiceCallControls'
import { VoiceCopilotPanel } from '../voice/VoiceCopilotPanel'
import { VoiceTranscript } from '../voice/VoiceTranscript'
import { VoiceWaveform } from '../voice/VoiceWaveform'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  queues: QueueSummary[]
  agents: AgentSummary[]
  agent_summary: Record<string, number>
  active_calls: Call[]
  controls: CallControls
  wrap_up_seconds: number
  transcription: { enabled: boolean; reason: string | null; note: string }
}

export default function LiveOperations() {
  const { timezone, can } = useVoice()
  const [params, setParams] = useSearchParams()
  const selectedId = params.get('call')

  const state = useDashboard<Panels>('live', 0)

  // The stream is the primary signal; a resync re-reads rather than patching a
  // partial history together.
  const { connection } = useLiveEvents({
    onEvent: useCallback((event: { type: string }) => {
      if (event.type === 'call.updated' || event.type === 'agent.presence.updated') state.reload()
    }, [state]),
    onResync: state.reload,
  })

  // Visible-screen polling when the stream is not available. Polling the API —
  // never a second copy of anything.
  usePolling(state.reload, 5000, connection === 'offline' || connection === 'disabled')

  const panels = state.data?.data.panels
  const calls = panels?.active_calls ?? []
  const selected = useMemo(
    () => calls.find((call) => String(call.call_id) === selectedId) ?? calls[0] ?? null,
    [calls, selectedId],
  )

  const select = useCallback(
    (callId: number) => {
      const next = new URLSearchParams(params)
      next.set('call', String(callId))
      setParams(next, { replace: true })
    },
    [params, setParams],
  )

  return (
    <DashboardFrame
      title="Live Operations"
      subtitle="See the queue. Support the conversation."
      view="live"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
      connection={connection}
      showPeriod={false}
    >
      {(envelope) => {
        const live = envelope.panels

        return (
          <>
            {connection === 'offline' || connection === 'disabled' ? (
              <Notice tone="warning" title="Live updates are not available">
                <p>
                  This screen is refreshing every few seconds instead. Figures may be up to five seconds old.
                </p>
              </Notice>
            ) : null}

            <div className="vgrid vgrid--live">
              <div className="vstack">
                <Card title="Queues" subtitle="Callers waiting now.">
                  {live.queues.length === 0 ? (
                    <EmptyState title="No queues configured" />
                  ) : (
                    live.queues.map((queue) => (
                      <Row
                        key={queue.queue_id}
                        title={queue.name}
                        detail={`${queue.waiting} waiting · ${queue.in_call} in call`}
                        trailing={
                          <Badge tone={queue.longest_wait > 120 ? 'red' : queue.longest_wait > 0 ? 'amber' : 'neutral'}>
                            {queue.longest_wait > 0 ? formatDuration(queue.longest_wait) : 'Clear'}
                          </Badge>
                        }
                      />
                    ))
                  )}
                </Card>

                <Card
                  title="Agents"
                  subtitle={`${live.agent_summary.available ?? 0} available of ${live.agent_summary.total ?? 0}.`}
                >
                  {live.agents.length === 0 ? (
                    <EmptyState title="No agents configured" body="Add agents so calls can be routed to people." />
                  ) : (
                    live.agents.map((agent) => (
                      <Row
                        key={agent.agent_id}
                        title={agent.extension ? `Extension ${agent.extension}` : agent.user_uuid.slice(0, 12)}
                        detail={
                          agent.presence_is_stale
                            ? 'Console stopped reporting — availability unknown'
                            : agent.active_calls > 0
                              ? `${agent.active_calls} call${agent.active_calls === 1 ? '' : 's'} in progress`
                              : agent.presence_since
                                ? `Since ${formatTime(agent.presence_since, timezone)}`
                                : undefined
                        }
                        trailing={<StatusPill status={agent.presence} />}
                      />
                    ))
                  )}
                  {(live.agent_summary.unknown ?? 0) > 0 ? (
                    <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>
                      {live.agent_summary.unknown} agent(s) whose console stopped reporting. They are not counted as
                      available and the router will skip them.
                    </p>
                  ) : null}
                </Card>
              </div>

              <SelectedCall
                call={selected}
                controls={live.controls}
                transcription={live.transcription}
                timezone={timezone}
                onReload={state.reload}
              />

              <div className="vstack">
                <Card title="AI Copilot" subtitle="Suggestions. Nothing acts on its own.">
                  <VoiceCopilotPanel
                    available={false}
                    unavailableReason="No AI provider is configured for this deployment, so there are no live suggestions."
                    intent={null}
                    facts={[]}
                    suggestion={null}
                    action={null}
                  />
                </Card>

                <Card title="Active calls" subtitle={`${calls.length} in progress.`}>
                  {calls.length === 0 ? (
                    <EmptyState title="No calls in progress" icon={Headphones} />
                  ) : (
                    calls.map((call) => (
                      <Row
                        key={call.call_id}
                        title={
                          <span className="vsplit">
                            {call.direction === 'inbound'
                              ? <PhoneIncoming size={13} aria-hidden="true" />
                              : <PhoneOutgoing size={13} aria-hidden="true" />}
                            {call.remote_masked ?? 'Unknown number'}
                          </span>
                        }
                        detail={
                          <>
                            {call.queue_name ?? 'Direct'}
                            {call.waiting_seconds ? ` · waiting ${formatDuration(call.waiting_seconds)}` : ''}
                          </>
                        }
                        trailing={<StatusPill status={call.state} />}
                        onClick={() => select(call.call_id)}
                      />
                    ))
                  )}
                </Card>
              </div>
            </div>

            {!can('voice.call.handle') ? (
              <Notice tone="info">
                <p>You can see the queue but not take calls. Ask an administrator for call handling if you need it.</p>
              </Notice>
            ) : null}
          </>
        )
      }}
    </DashboardFrame>
  )
}

function SelectedCall({ call, controls, transcription, timezone, onReload }: {
  call: Call | null
  controls: CallControls
  transcription: { enabled: boolean; reason: string | null; note: string }
  timezone: string
  onReload: () => void
}) {
  const [pending, setPending] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const { company, branchId } = useVoice()

  const transcript = useApi<{ data: TranscriptSegment[] }>(
    (signal) => api.get(`v1/calls/${call?.call_id}/transcript`, undefined, signal),
    [company?.cmp_id, branchId, call?.call_id],
    { enabled: Boolean(call) && transcription.enabled },
  )

  const control = useMutation((action: string, payload?: Record<string, unknown>) =>
    api.post(`v1/calls/${call?.call_id}/actions`, { action, ...payload }),
  )

  const onAction = useCallback(
    async (action: string, payload?: Record<string, unknown>) => {
      setPending(action)
      setActionError(null)
      const result = await control.mutate(action, payload)
      setPending(null)
      if (result === null) {
        setActionError(control.error?.message ?? 'That control did not work.')
        return
      }
      onReload()
    },
    [control, onReload],
  )

  if (!call) {
    return (
      <Card title="No call selected">
        <EmptyState
          title="Nothing in progress"
          body="When a call comes in it appears here with its transcript and controls."
          icon={Headphones}
        />
      </Card>
    )
  }

  const muted = call.state === 'held' ? false : false
  const held = call.state === 'held'
  const recording = call.recording_state === 'recording'

  return (
    <Card
      title={call.remote_masked ?? 'Unknown number'}
      subtitle={`${call.direction === 'inbound' ? 'Inbound' : 'Outbound'} · started ${formatTime(call.initiated_at, timezone)}`}
      action={
        <div className="vsplit">
          {recording ? <Badge tone="red" live>Recording</Badge> : null}
          <StatusPill status={call.state} />
        </div>
      }
    >
      {call.consent_state === 'refused' ? (
        <Notice tone="warning" title="The caller declined to be recorded">
          <p>Recording has stopped and the refusal is on the call record.</p>
        </Notice>
      ) : null}

      <VoiceWaveform
        mode={call.state === 'answered' ? 'simulated' : 'idle'}
        caption={
          call.state === 'answered'
            ? 'Illustrative visualisation. Live audio levels appear here when this console is carrying the call.'
            : 'Not connected.'
        }
      />

      {call.contact_ref ? (
        <p className="vmuted vsmall" style={{ textAlign: 'center' }}>
          Linked to a contact in Aicountly Contacts — open Contacts for their details.
        </p>
      ) : (
        <p className="vmuted vsmall" style={{ textAlign: 'center' }}>
          This number is not linked to a contact.
        </p>
      )}

      <div style={{ marginTop: 16 }}>
        <VoiceCallControls
          controls={controls}
          state={call.state}
          muted={muted}
          held={held}
          recording={recording}
          pending={pending}
          onAction={(action, payload) => void onAction(action, payload)}
        />
      </div>

      {actionError ? (
        <div style={{ marginTop: 12 }}>
          <Notice tone="danger">{actionError}</Notice>
        </div>
      ) : null}

      <div style={{ marginTop: 20, borderTop: '1px solid var(--border)', paddingTop: 16 }}>
        <div className="vspread" style={{ marginBottom: 10 }}>
          <h3 style={{ margin: 0 }}>Live transcript</h3>
          {!transcription.enabled ? <Badge tone="neutral">Not enabled</Badge> : null}
        </div>

        {!transcription.enabled ? (
          <p className="vmuted vsmall" style={{ margin: 0 }}>
            {transcription.reason ?? 'Live transcription is not configured for this deployment.'}
          </p>
        ) : (
          <PanelState
            state={transcript}
            what="the transcript"
            isEmpty={(data) => data.data.length === 0}
            empty={<p className="vmuted vsmall" style={{ margin: 0 }}>Waiting for the first words…</p>}
          >
            {(data) => <VoiceTranscript segments={data.data} live />}
          </PanelState>
        )}
      </div>
    </Card>
  )
}
