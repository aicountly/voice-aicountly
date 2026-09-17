/**
 * Dashboard 1 — Command Centre.
 *
 * The overview card carries a waveform that is EXPLICITLY decorative and says
 * so. This screen monitors no audio; it reads counts. A moving waveform that
 * implied otherwise on a business-wide dashboard would be a claim about
 * listening to customers' calls.
 */

import { useNavigate } from 'react-router-dom'
import { ArrowRight, Bot, Headphones, PhoneCall, Users } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useLiveEvents } from '../hooks/useLiveEvents'
import type { AttentionItem, IntegrationStatus, QueueSummary } from '../services/types'
import { Badge, Bar, Button, Card, EmptyState, Row, StatusPill, formatDuration } from '../ui'
import { VoiceWaveform } from '../voice/VoiceWaveform'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  live: {
    active_calls: number
    ai_sessions: number
    available_agents: number
    unknown_agents: number
    queue_waiting: number
    longest_wait_seconds: number
    waveform_is_decorative: boolean
  }
  attention: AttentionItem[]
  outcomes: Array<{ key: string; label: string; count: number; percent: number | null }>
  volume_by_hour: Array<{ hour: number; total: number; inbound: number; outbound: number }>
  queues: QueueSummary[]
  workflows: IntegrationStatus[]
  capacity: { active: number; limit: number; source: string; percent: number }
}

export default function CommandCentre() {
  const navigate = useNavigate()
  const { can } = useVoice()
  const state = useDashboard<Panels>('command-centre', 60000)
  const { connection } = useLiveEvents({ onEvent: () => undefined })

  return (
    <DashboardFrame
      title="Voice Command Centre"
      subtitle="Every conversation. A clear next step."
      view="command-centre"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
      connection={connection}
      actions={
        can('voice.call.place') ? (
          <Button variant="primary" icon={PhoneCall} onClick={() => navigate('/calls?compose=1')}>
            Make a call
          </Button>
        ) : null
      }
    >
      {(envelope) => {
        const panels = envelope.panels

        return (
          <>
            <div className="vgrid vgrid--two">
              <Card
                hero
                title="Your voice operations, at a glance"
                subtitle="Counts across this company, refreshed each minute."
                action={<Badge tone="neutral">{panels.live.active_calls > 0 ? 'Calls in progress' : 'Quiet'}</Badge>}
              >
                <VoiceWaveform
                  mode={panels.live.active_calls > 0 ? 'simulated' : 'idle'}
                  caption={
                    panels.live.active_calls > 0
                      ? 'Illustrative visualisation. This screen shows counts — it does not listen to calls.'
                      : 'No calls in progress.'
                  }
                />

                <div className="vsplit" style={{ gap: 30, marginBottom: 18 }}>
                  <HeroStat icon={PhoneCall} value={panels.live.active_calls} label="Active calls" />
                  <HeroStat icon={Bot} value={panels.live.ai_sessions} label="AI sessions" />
                  <HeroStat icon={Users} value={panels.live.available_agents} label="Available agents" />
                  {panels.live.unknown_agents > 0 ? (
                    <HeroStat icon={Headphones} value={panels.live.unknown_agents} label="Availability unknown" />
                  ) : null}
                </div>

                <div className="vsoft">
                  <h3>What needs attention</h3>
                  {panels.attention.length === 0 ? (
                    <p style={{ margin: 0, fontSize: 13 }}>Nothing is outstanding right now.</p>
                  ) : (
                    <>
                      <p style={{ margin: 0, fontSize: 13 }}>{panels.attention[0].title}. {panels.attention[0].why}</p>
                      <div className="vactions">
                        <Button
                          size="sm"
                          onClick={() => navigate(withParams(panels.attention[0].link))}
                        >
                          {panels.attention[0].action}
                        </Button>
                      </div>
                    </>
                  )}
                </div>
              </Card>

              <Card title="Needs your attention" subtitle="Highest priority first.">
                {panels.attention.length === 0 ? (
                  <EmptyState title="Nothing outstanding" body="No unanswered callers, overdue callbacks or queue pressure." />
                ) : (
                  <>
                    {panels.attention.map((item) => (
                      <Row
                        key={item.id}
                        title={item.title}
                        detail={
                          <>
                            {item.why}
                            <span style={{ display: 'block', marginTop: 2 }}>{item.source}</span>
                          </>
                        }
                        trailing={
                          <Badge tone={item.priority === 'high' ? 'red' : item.priority === 'medium' ? 'amber' : 'neutral'}>
                            {item.priority}
                          </Badge>
                        }
                        onClick={() => navigate(withParams(item.link))}
                      />
                    ))}
                  </>
                )}
              </Card>
            </div>

            <div className="vgrid vgrid--three">
              <Card title="How calls ended" subtitle="Categories do not overlap.">
                {panels.outcomes.every((outcome) => outcome.count === 0) ? (
                  <EmptyState title="No completed calls in this period" />
                ) : (
                  panels.outcomes.map((outcome) => (
                    <Bar
                      key={outcome.key}
                      label={outcome.label}
                      value={`${outcome.count}${outcome.percent !== null ? ` · ${outcome.percent}%` : ''}`}
                      percent={outcome.percent}
                      tone={outcome.key === 'failed' ? 'danger' : outcome.key === 'no_answer' ? 'warning' : undefined}
                    />
                  ))
                )}
              </Card>

              <Card title="Queue activity" subtitle="Callers waiting now.">
                {panels.queues.length === 0 ? (
                  <EmptyState title="No queues configured" body="Queues route inbound calls to the right team." />
                ) : (
                  panels.queues.map((queue) => (
                    <Row
                      key={queue.queue_id}
                      title={queue.name}
                      detail={`${queue.waiting} waiting · ${queue.in_call} in call`}
                      trailing={
                        queue.longest_wait > 0 ? (
                          <Badge tone={queue.longest_wait > 120 ? 'red' : 'amber'}>
                            {formatDuration(queue.longest_wait)}
                          </Badge>
                        ) : (
                          <Badge tone="neutral">Clear</Badge>
                        )
                      }
                      onClick={() => navigate(`/live?queue=${queue.queue_id}`)}
                    />
                  ))
                )}
              </Card>

              <Card title="Connected workflows" subtitle="Outcomes the owning product confirmed.">
                {panels.workflows.map((workflow) => (
                  <Row
                    key={workflow.app}
                    title={workflow.label}
                    detail={
                      workflow.status === 'not_configured'
                        ? workflow.reason ?? 'Not connected.'
                        : `${workflow.confirmed ?? 0} confirmed in this period`
                    }
                    trailing={<StatusPill status={workflow.status} />}
                  />
                ))}
                <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>
                  Counted only from outcomes acknowledged by the product that owns them.
                </p>
              </Card>
            </div>

            <div className="vgrid vgrid--two">
              <Card title="Calls by hour" subtitle={`Local hours in ${envelope.timezone}.`}>
                <HourChart data={panels.volume_by_hour} />
              </Card>

              <Card title="Capacity" subtitle="Concurrent calls against the configured ceiling.">
                <Bar
                  label={panels.capacity.limit > 0 ? `${panels.capacity.source} limit` : 'No limit configured'}
                  value={panels.capacity.limit > 0 ? `${panels.capacity.active} / ${panels.capacity.limit}` : String(panels.capacity.active)}
                  percent={panels.capacity.limit > 0 ? panels.capacity.percent : null}
                  tone={panels.capacity.percent > 85 ? 'danger' : panels.capacity.percent > 70 ? 'warning' : undefined}
                />
                <div className="vactions">
                  <Button size="sm" icon={ArrowRight} onClick={() => navigate('/network')}>
                    Network &amp; usage
                  </Button>
                </div>
              </Card>
            </div>
          </>
        )
      }}
    </DashboardFrame>
  )
}

function HeroStat({ icon: Icon, value, label }: { icon: typeof PhoneCall; value: number; label: string }) {
  return (
    <div>
      <strong style={{ fontSize: 25, display: 'block', lineHeight: 1.1 }}>{value}</strong>
      <span style={{ color: '#c5e5d9', fontSize: 12, display: 'flex', gap: 5, alignItems: 'center', marginTop: 3 }}>
        <Icon size={12} aria-hidden="true" />
        {label}
      </span>
    </div>
  )
}

/**
 * Call volume by local hour.
 *
 * Inline SVG rather than a charting dependency: it is one path and a baseline,
 * and adding a library for it would be a hundred kilobytes for a sparkline.
 * All 24 hours are always present, so the axis does not shift as the day fills.
 */
function HourChart({ data }: { data: Array<{ hour: number; total: number; inbound: number; outbound: number }> }) {
  const peak = Math.max(1, ...data.map((point) => point.total))
  const width = 560
  const height = 150
  const step = width / Math.max(1, data.length - 1)

  const line = data
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${index * step} ${height - (point.total / peak) * (height - 20)}`)
    .join(' ')

  const area = `${line} L ${width} ${height} L 0 ${height} Z`
  const busiest = data.reduce((best, point) => (point.total > best.total ? point : best), data[0])

  return (
    <div>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        style={{ width: '100%', height: 150 }}
        role="img"
        aria-label={`Calls by hour. Busiest hour ${busiest?.hour ?? 0}:00 with ${busiest?.total ?? 0} calls.`}
        preserveAspectRatio="none"
      >
        <path d={area} fill="#e6f5ee" />
        <path d={line} fill="none" stroke="#087f5b" strokeWidth={2} strokeLinejoin="round" />
      </svg>
      <div className="vspread vmuted vsmall" style={{ marginTop: 6 }}>
        <span>00:00</span>
        <span>
          Busiest {String(busiest?.hour ?? 0).padStart(2, '0')}:00 — {busiest?.total ?? 0} calls
        </span>
        <span>23:00</span>
      </div>
    </div>
  )
}

function withParams(link: { route: string; params: Record<string, string> }): string {
  const query = new URLSearchParams(link.params).toString()
  return query ? `${link.route}?${query}` : link.route
}
