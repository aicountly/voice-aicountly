/**
 * One call, in full.
 *
 * Shows the legs beneath the call — a transferred call is ONE conversation with
 * several legs, and this is where that is visible — plus the external
 * operations it triggered, so an unconfirmed write is on the record rather than
 * quietly absent.
 */

import { useVoice } from '../context/VoiceContext'
import { useApi } from '../hooks/useApi'
import { api } from '../services/api'
import type { Call, Commitment, ExternalOperation, Recording } from '../services/types'
import {
  Badge, Drawer, Notice, PanelState, Row, StatusPill, formatDateTime, formatDuration,
} from '../ui'

interface CallDetail extends Call {
  legs: Array<{
    leg_id: number
    leg_role: string
    endpoint: string | null
    state: string
    started_at: string
    answered_at: string | null
    ended_at: string | null
    end_reason: string | null
    talk_seconds: number
  }>
  participants: Array<{ participant_id: number; party: string; monitor_mode: string | null; joined_at: string }>
  recordings: Recording[]
  commitments: Commitment[]
  external_operations: ExternalOperation[]
  permissions: Record<string, boolean>
}

export function CallDrawer({ callId, onClose }: { callId: number; onClose: () => void }) {
  const { timezone, company, branchId } = useVoice()

  const state = useApi<{ data: CallDetail }>(
    (signal) => api.get(`v1/calls/${callId}`, undefined, signal),
    [company?.cmp_id, branchId, callId],
  )

  return (
    <Drawer title="Call detail" onClose={onClose}>
      <PanelState state={state} what="this call">
        {(data) => {
          const call = data.data

          return (
            <div className="vstack vstack--tight">
              <div className="vspread">
                <h3 style={{ margin: 0 }}>{call.remote_masked ?? 'Unknown number'}</h3>
                <StatusPill status={call.state} />
              </div>

              {call.state_is_stale ? (
                <Notice tone="warning" title="State unknown">
                  <p>
                    The provider stopped reporting on this call. The last state we were told was
                    “{call.last_known_state}”.
                  </p>
                </Notice>
              ) : null}

              <dl className="vdl">
                <dt>Direction</dt>
                <dd>{call.direction}</dd>
                <dt>Started</dt>
                <dd>{formatDateTime(call.initiated_at, timezone)}</dd>
                <dt>Answered</dt>
                <dd>{call.answered_at ? formatDateTime(call.answered_at, timezone) : 'Not answered'}</dd>
                <dt>Talk time</dt>
                <dd>{formatDuration(call.talk_seconds)}</dd>
                <dt>Origin</dt>
                <dd>{call.origin.replace(/_/g, ' ').toLowerCase()}</dd>
                <dt>Handled by</dt>
                <dd>{call.handled_by}</dd>
                {call.outcome ? (
                  <>
                    <dt>Outcome</dt>
                    <dd><StatusPill status={call.outcome} /></dd>
                  </>
                ) : null}
                {call.disposition_note ? (
                  <>
                    <dt>Note</dt>
                    <dd style={{ textAlign: 'left', fontWeight: 400 }}>{call.disposition_note}</dd>
                  </>
                ) : null}
                <dt>Recording</dt>
                <dd>{recordingLabel(call)}</dd>
              </dl>

              {call.contact_ref ? (
                <Notice tone="info">
                  <p>
                    Linked to a contact in Aicountly Contacts. Open Contacts for their current details — Voice keeps
                    the reference only.
                  </p>
                </Notice>
              ) : null}

              {call.legs.length > 1 ? (
                <section>
                  <h3>Legs</h3>
                  <p className="vmuted vsmall" style={{ marginTop: 0 }}>
                    One conversation, {call.legs.length} legs. It counts once in every total.
                  </p>
                  {call.legs.map((leg) => (
                    <Row
                      key={leg.leg_id}
                      title={leg.leg_role.replace(/_/g, ' ')}
                      detail={
                        <>
                          {leg.endpoint ?? 'browser'} · {formatDuration(leg.talk_seconds)}
                          {leg.end_reason ? ` · ${leg.end_reason}` : ''}
                        </>
                      }
                      trailing={<StatusPill status={leg.state} />}
                    />
                  ))}
                </section>
              ) : null}

              {call.participants.some((participant) => participant.monitor_mode) ? (
                <section>
                  <h3>Monitoring</h3>
                  {call.participants
                    .filter((participant) => participant.monitor_mode)
                    .map((participant) => (
                      <Row
                        key={participant.participant_id}
                        title={`Supervisor ${participant.monitor_mode}`}
                        detail={formatDateTime(participant.joined_at, timezone)}
                      />
                    ))}
                </section>
              ) : null}

              {call.external_operations.length > 0 ? (
                <section>
                  <h3>Actions in other products</h3>
                  {call.external_operations.map((operation) => (
                    <Row
                      key={operation.operation_id}
                      title={`${operation.operation.replace(/_/g, ' ')} · ${operation.target_app}`}
                      detail={operation.message}
                      trailing={
                        <Badge tone={operation.confirmed ? 'default' : operation.status === 'failed' ? 'red' : 'amber'}>
                          {operation.confirmed ? 'Confirmed' : operation.status}
                        </Badge>
                      }
                    />
                  ))}
                </section>
              ) : null}

              {call.commitments.length > 0 ? (
                <section>
                  <h3>Commitments</h3>
                  {call.commitments.map((commitment) => (
                    <Row
                      key={commitment.commitment_id}
                      title={commitment.description}
                      detail={
                        commitment.due_state === 'needs_clarification'
                          ? `Due date not settled${commitment.due_text ? ` — “${commitment.due_text}”` : ''}`
                          : formatDateTime(commitment.due_at, timezone)
                      }
                      trailing={<StatusPill status={commitment.status} />}
                    />
                  ))}
                </section>
              ) : null}
            </div>
          )
        }}
      </PanelState>
    </Drawer>
  )
}

function recordingLabel(call: Call): string {
  if (call.consent_state === 'refused') return 'The caller declined to be recorded'
  return {
    none: 'Not recorded',
    recording: 'Recording',
    stored: 'Recorded',
    refused: 'Refused',
    failed: 'Recording failed',
  }[call.recording_state] ?? call.recording_state
}
