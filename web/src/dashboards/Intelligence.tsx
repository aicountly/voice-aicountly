/**
 * Dashboard 5 — Conversation Intelligence.
 *
 * Search, listen, read, and turn what was said into something somebody will act
 * on — with the words it came from attached.
 *
 * ## Recordings load on interaction, never on render
 *
 * A list of forty calls does not fetch forty audio files. A playback URL is
 * minted when somebody presses play, expires in minutes, and the access is
 * audited.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download, FileText, Pause, Play, Search, Sparkles } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type {
  Call, Commitment, PlaybackGrant, TranscriptSegment,
} from '../services/types'
import {
  Badge, Button, Card, EmptyState, Notice, PanelState, Row, StatusPill,
  formatDateTime, formatDuration,
} from '../ui'
import { VoiceTranscript } from '../voice/VoiceTranscript'
import { VoiceWaveform } from '../voice/VoiceWaveform'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  recent_calls: Array<Call & {
    commitments: number
    has_transcript: boolean
    recording: { recording_uuid: string; duration_seconds: number; status: string } | null
  }>
  commitments: Commitment[]
  commitment_summary: Record<string, number>
  quality: { total: number; reviewed: number; passed: number; overridden: number; average_score: number | null; note: string }
  search: { scope: string; natural_language: boolean; natural_language_reason: string | null }
  permissions: Record<string, boolean>
}

export default function Intelligence() {
  const { timezone } = useVoice()
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState(params.get('q') ?? '')
  const state = useDashboard<Panels>('intelligence')
  const [selectedCall, setSelectedCall] = useState<number | null>(null)

  const panels = state.data?.data.panels
  const calls = panels?.recent_calls ?? []
  const current = calls.find((call) => call.call_id === selectedCall) ?? calls[0] ?? null

  const onSearch = useCallback(
    (event: React.FormEvent) => {
      event.preventDefault()
      const next = new URLSearchParams(params)
      if (query.trim()) next.set('q', query.trim())
      else next.delete('q')
      setParams(next, { replace: true })
    },
    [params, query, setParams],
  )

  return (
    <DashboardFrame
      title="Conversation Intelligence"
      subtitle="Find the evidence behind every next step."
      view="intelligence"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
    >
      {(envelope) => {
        const intel = envelope.panels

        return (
          <>
            <Card>
              <form onSubmit={onSearch} className="vsplit" role="search">
                <div style={{ flex: 1, position: 'relative', minWidth: 220 }}>
                  <Search
                    size={15}
                    aria-hidden="true"
                    style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)' }}
                  />
                  <input
                    className="vinput"
                    style={{ paddingLeft: 36 }}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Find calls where a customer asked for a revised quote…"
                    aria-label="Search conversations"
                  />
                </div>
                <Button type="submit" variant="primary">Search</Button>
              </form>
              <p className="vmuted vsmall" style={{ margin: '10px 0 0' }}>
                {intel.search.scope}
                {!intel.permissions.can_transcript
                  ? ' Transcript text is not searched — you do not have permission to read transcripts.'
                  : ''}
              </p>
            </Card>

            <div className="vgrid vgrid--three" style={{ marginTop: 20 }}>
              <Card title={`Calls (${calls.length})`} subtitle="Completed calls in this period.">
                {calls.length === 0 ? (
                  <EmptyState title="No calls in this period" />
                ) : (
                  calls.map((call) => (
                    <Row
                      key={call.call_id}
                      title={call.remote_masked ?? 'Unknown number'}
                      detail={
                        <>
                          {formatDateTime(call.initiated_at, timezone)} · {formatDuration(call.talk_seconds)}
                          {call.commitments > 0 ? ` · ${call.commitments} commitment${call.commitments === 1 ? '' : 's'}` : ''}
                        </>
                      }
                      trailing={
                        call.disposition_category ? (
                          <StatusPill status={call.disposition_category} />
                        ) : (
                          <Badge tone="neutral">{call.language?.toUpperCase() ?? '—'}</Badge>
                        )
                      }
                      onClick={() => setSelectedCall(call.call_id)}
                    />
                  ))
                )}
              </Card>

              {current ? (
                <CallEvidence
                  call={current}
                  canListen={Boolean(intel.permissions.can_listen)}
                  canDownload={Boolean(intel.permissions.can_download)}
                  canTranscript={Boolean(intel.permissions.can_transcript)}
                  timezone={timezone}
                />
              ) : (
                <Card title="Conversation evidence">
                  <EmptyState title="Pick a call" body="Its recording, transcript and summary appear here." />
                </Card>
              )}

              <div className="vstack">
                <Card
                  title="Commitment ledger"
                  subtitle={`${intel.commitment_summary.suggested ?? 0} suggestions, ${intel.commitment_summary.confirmed ?? 0} confirmed.`}
                >
                  {intel.commitments.length === 0 ? (
                    <EmptyState title="Nothing found yet" body="Promises made on a call appear here with the words they came from." />
                  ) : (
                    intel.commitments.slice(0, 6).map((commitment) => (
                      <CommitmentRow
                        key={commitment.commitment_id}
                        commitment={commitment}
                        timezone={timezone}
                        canConfirm={Boolean(intel.permissions.can_confirm)}
                        onDone={state.reload}
                      />
                    ))
                  )}
                  {(intel.commitment_summary.needs_date ?? 0) > 0 ? (
                    <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>
                      {intel.commitment_summary.needs_date} confirmed commitment(s) have no date. The call did not
                      settle one — it has not been guessed.
                    </p>
                  ) : null}
                </Card>

                <Card title="Quality" subtitle={intel.quality.note}>
                  <Row title="Reviewed" detail={`${intel.quality.reviewed} of ${intel.quality.total}`} />
                  <Row title="Passed" detail={String(intel.quality.passed)} />
                  {intel.quality.overridden > 0 ? (
                    <Row title="Reviewer overrides" detail={`${intel.quality.overridden} with a recorded reason`} />
                  ) : null}
                  {intel.quality.average_score !== null ? (
                    <Row title="Average score" detail={String(intel.quality.average_score)} />
                  ) : null}
                </Card>
              </div>
            </div>
          </>
        )
      }}
    </DashboardFrame>
  )
}

function CallEvidence({ call, canListen, canDownload, canTranscript, timezone }: {
  call: Call & { recording: { recording_uuid: string; duration_seconds: number } | null; has_transcript: boolean }
  canListen: boolean
  canDownload: boolean
  canTranscript: boolean
  timezone: string
}) {
  const { company, branchId } = useVoice()
  const [grant, setGrant] = useState<PlaybackGrant | null>(null)
  const [playing, setPlaying] = useState(false)
  const [position, setPosition] = useState(0)
  const audio = useRef<HTMLAudioElement | null>(null)

  const transcript = useApi<{ data: TranscriptSegment[] }>(
    (signal) => api.get(`v1/calls/${call.call_id}/transcript`, undefined, signal),
    [company?.cmp_id, branchId, call.call_id],
    { enabled: canTranscript && call.has_transcript },
  )

  const summary = useApi<{ data: { body: string | null; engine: string; evidence: number[]; note?: string } }>(
    (signal) => api.get(`v1/calls/${call.call_id}/summary`, undefined, signal),
    [company?.cmp_id, branchId, call.call_id],
    { enabled: canTranscript },
  )

  // The URL is minted here, on the press, and expires in minutes.
  const fetchGrant = useMutation((download: boolean) =>
    api.get<{ data: PlaybackGrant }>(
      `v1/recordings/${call.recording?.recording_uuid}/playback`,
      download ? { download: '1' } : undefined,
    ),
  )

  // The grant is dropped when the selection changes, so a URL for one call can
  // never be used against the next.
  useEffect(() => {
    setGrant(null)
    setPlaying(false)
    setPosition(0)
    audio.current?.pause()
    audio.current = null
  }, [call.call_id])

  const onPlay = useCallback(async () => {
    if (playing) {
      audio.current?.pause()
      setPlaying(false)
      return
    }

    let active = grant
    if (!active) {
      const result = await fetchGrant.mutate(false)
      if (!result) return
      active = result.data
      setGrant(active)
    }

    const element = audio.current ?? new Audio(active.url)
    audio.current = element
    element.ontimeupdate = () => setPosition(element.currentTime * 1000)
    element.onended = () => setPlaying(false)
    await element.play()
    setPlaying(true)
  }, [playing, grant, fetchGrant])

  const onDownload = useCallback(async () => {
    const result = await fetchGrant.mutate(true)
    if (result) window.open(result.data.url, '_blank', 'noopener')
  }, [fetchGrant])

  const activeSegment = transcript.data?.data.find(
    (segment) => position >= segment.started_ms && (segment.ended_ms === null || position < segment.ended_ms),
  )

  return (
    <Card
      title={call.remote_masked ?? 'Unknown number'}
      subtitle={`${formatDateTime(call.initiated_at, timezone)} · ${formatDuration(call.talk_seconds)}`}
    >
      {call.recording && canListen ? (
        <>
          <VoiceWaveform mode="idle" caption="Recording. Press play to listen." height={72} />
          <div className="vsplit" style={{ justifyContent: 'center', marginBottom: 14 }}>
            <Button icon={playing ? Pause : Play} variant="primary" onClick={() => void onPlay()} disabled={fetchGrant.pending}>
              {fetchGrant.pending ? 'Preparing…' : playing ? 'Pause' : 'Play recording'}
            </Button>
            {canDownload ? (
              <Button icon={Download} onClick={() => void onDownload()} disabled={fetchGrant.pending}>
                Download
              </Button>
            ) : null}
            <span className="vmuted vsmall">{formatDuration(call.recording.duration_seconds)}</span>
          </div>
          {fetchGrant.error ? <Notice tone="danger">{fetchGrant.error.message}</Notice> : null}
          <p className="vmuted vsmall" style={{ textAlign: 'center', marginTop: -6 }}>
            Opening a recording is recorded in the audit trail.
          </p>
        </>
      ) : call.recording ? (
        <Notice tone="info">
          <p>This call was recorded. You do not have permission to play recordings.</p>
        </Notice>
      ) : (
        <p className="vmuted vsmall">This call was not recorded.</p>
      )}

      {canTranscript ? (
        <>
          <div style={{ marginTop: 16, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
            <div className="vspread" style={{ marginBottom: 10 }}>
              <h3 style={{ margin: 0 }}>
                <Sparkles size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
                Summary
              </h3>
              {summary.data ? (
                <Badge tone="neutral">
                  {summary.data.data.engine === 'model'
                    ? 'Written by a model'
                    : summary.data.data.engine === 'human'
                      ? 'Edited by a person'
                      : 'Rule-based'}
                </Badge>
              ) : null}
            </div>

            <PanelState state={summary} what="the summary">
              {(data) =>
                data.data.body ? (
                  <>
                    <p style={{ fontSize: 13, margin: 0 }}>{data.data.body}</p>
                    <p className="vmuted vsmall" style={{ margin: '6px 0 0' }}>
                      Check it against the transcript before acting on it.
                    </p>
                  </>
                ) : (
                  <p className="vmuted vsmall" style={{ margin: 0 }}>{data.data.note ?? 'No summary.'}</p>
                )
              }
            </PanelState>
          </div>

          <div style={{ marginTop: 16, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
            <h3>
              <FileText size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
              Transcript
            </h3>
            <PanelState
              state={transcript}
              what="the transcript"
              isEmpty={(data) => data.data.length === 0}
              empty={<p className="vmuted vsmall" style={{ margin: 0 }}>No transcript for this call.</p>}
            >
              {(data) => (
                <VoiceTranscript
                  segments={data.data}
                  activeSegmentId={activeSegment?.segment_id ?? null}
                  onSeek={(ms) => {
                    if (audio.current) audio.current.currentTime = ms / 1000
                  }}
                />
              )}
            </PanelState>
          </div>
        </>
      ) : (
        <Notice tone="info">
          <p>You do not have permission to read transcripts.</p>
        </Notice>
      )}
    </Card>
  )
}

function CommitmentRow({ commitment, timezone, canConfirm, onDone }: {
  commitment: Commitment
  timezone: string
  canConfirm: boolean
  onDone: () => void
}) {
  const confirm = useMutation(() =>
    api.post<{ data: { operation: { message: string; confirmed: boolean } | null } }>(
      `v1/commitments/${commitment.commitment_id}/confirm`,
      {},
    ),
  )
  const [result, setResult] = useState<string | null>(null)

  const onConfirm = useCallback(async () => {
    const response = await confirm.mutate()
    if (response) {
      setResult(response.data.operation?.message ?? 'Confirmed.')
      onDone()
    }
  }, [confirm, onDone])

  return (
    <div style={{ padding: '14px 0', borderBottom: '1px solid var(--border)' }}>
      <div className="vspread">
        <strong style={{ fontSize: 13 }}>{commitment.description}</strong>
        <StatusPill status={commitment.status} />
      </div>

      <dl className="vdl" style={{ marginTop: 8 }}>
        <dt>Who</dt>
        <dd>{commitment.party === 'business' ? 'Us' : 'The caller'}</dd>
        <dt>Due</dt>
        <dd>
          {commitment.due_state === 'needs_clarification' ? (
            <span style={{ color: 'var(--warning)' }}>
              Needs clarification{commitment.due_text ? ` — “${commitment.due_text}”` : ''}
            </span>
          ) : (
            formatDateTime(commitment.due_at, timezone)
          )}
        </dd>
        {commitment.external_task_ref ? (
          <>
            <dt>Task</dt>
            <dd>{commitment.external_system?.toUpperCase()} · {commitment.external_task_ref}</dd>
          </>
        ) : null}
      </dl>

      {result ? (
        <div style={{ marginTop: 10 }}>
          <Notice tone="info">{result}</Notice>
        </div>
      ) : null}
      {confirm.error ? (
        <div style={{ marginTop: 10 }}>
          <Notice tone="danger">{confirm.error.message}</Notice>
        </div>
      ) : null}

      {commitment.status === 'suggested' && canConfirm ? (
        <div className="vactions">
          <Button size="sm" variant="primary" onClick={() => void onConfirm()} disabled={confirm.pending}>
            {confirm.pending ? 'Confirming…' : 'Confirm and create the task'}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
