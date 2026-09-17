/**
 * Dashboard 4 — Campaigns & Growth.
 *
 * ## The funnel shares one denominator
 *
 * Attempted, connected, qualified, confirmed — all as a share of ATTEMPTS. Two
 * denominators on one chart is how a reader concludes something the data does
 * not say.
 *
 * ## An unavailable mode is visibly unavailable
 *
 * A campaign mode the provider cannot serve is greyed with the reason, not
 * offered and then refused at launch.
 */

import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Ban, CheckCircle2, Pause, Play, Plus, XCircle } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { BudgetStatus, Campaign, ReadinessCheck } from '../services/types'
import {
  Badge, Bar, Button, Card, EmptyState, Notice, Row, StatusPill, formatMoney,
} from '../ui'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  campaigns: Campaign[]
  funnel: Array<{ key: string; label: string; count: number; percent: number | null }>
  modes: Array<{ key: string; label: string; available: boolean; reason: string | null }>
  budget: BudgetStatus[]
  callback_planner: Array<{ slot: string; due: number; priority: string }>
  planner: { drafts_only: boolean; note: string }
}

export default function Campaigns() {
  const navigate = useNavigate()
  const { can } = useVoice()
  const state = useDashboard<Panels>('campaigns')
  const [selected, setSelected] = useState<number | null>(null)

  const act = useMutation((campaignId: number, action: string) =>
    api.post<{ data: { status: string; message: string | null } }>(`v1/campaigns/${campaignId}/actions`, { action }),
  )

  const onAct = useCallback(
    async (campaignId: number, action: string) => {
      const result = await act.mutate(campaignId, action)
      if (result) state.reload()
    },
    [act, state],
  )

  return (
    <DashboardFrame
      title="Campaigns & Growth"
      subtitle="Turn outreach into measurable outcomes."
      view="campaigns"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
      actions={
        can('voice.campaigns.manage') ? (
          <Button variant="primary" icon={Plus} onClick={() => navigate('/campaigns?new=1')}>
            New campaign
          </Button>
        ) : null
      }
    >
      {(envelope) => {
        const panels = envelope.panels
        const detail = panels.campaigns.find((campaign) => campaign.campaign_id === selected) ?? null

        return (
          <>
            {act.error ? <Notice tone="danger">{act.error.message}</Notice> : null}

            <div className="vgrid vgrid--two">
              <Card title="Campaigns" subtitle="Running first.">
                {panels.campaigns.length === 0 ? (
                  <EmptyState
                    title="No campaigns yet"
                    body="A campaign dials a list of people you already have a reason to call."
                  />
                ) : (
                  <div className="vtable-wrap">
                    <table className="vtable">
                      <caption className="sr-only">Campaigns in this period</caption>
                      <thead>
                        <tr>
                          <th scope="col">Campaign</th>
                          <th scope="col" className="vtable__num">Attempted</th>
                          <th scope="col" className="vtable__num">Connected</th>
                          <th scope="col">Status</th>
                          <th scope="col"><span className="sr-only">Actions</span></th>
                        </tr>
                      </thead>
                      <tbody>
                        {panels.campaigns.map((campaign) => (
                          <tr
                            key={campaign.campaign_id}
                            onClick={() => setSelected(campaign.campaign_id)}
                            style={{ cursor: 'pointer' }}
                          >
                            <td>
                              <strong>{campaign.name}</strong>
                              <small>
                                {campaign.mode.replace(/_/g, ' ')} · {campaign.audience ?? 0} in the audience
                              </small>
                            </td>
                            <td className="vtable__num">{campaign.attempted ?? 0}</td>
                            <td className="vtable__num">{campaign.connected ?? 0}</td>
                            <td>
                              <StatusPill status={campaign.status} />
                              {campaign.blocking && campaign.blocking.length > 0 ? (
                                <small style={{ color: 'var(--warning)' }}>
                                  {campaign.blocking.length} check{campaign.blocking.length === 1 ? '' : 's'} outstanding
                                </small>
                              ) : null}
                            </td>
                            <td>
                              <CampaignActions
                                campaign={campaign}
                                canLaunch={can('voice.campaigns.launch')}
                                pending={act.pending}
                                onAct={(action) => void onAct(campaign.campaign_id, action)}
                              />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Card>

              <Card title="AI campaign planner" subtitle="Proposes a draft. It never starts a campaign.">
                <Badge tone="amber">Draft only</Badge>
                <p style={{ marginTop: 14, fontSize: 13 }}>
                  Describe what you want to achieve and the planner proposes an audience, a script and a schedule for
                  you to review.
                </p>
                <div className="vsoft">
                  <p className="vmuted vsmall" style={{ margin: 0 }}>{panels.planner.note}</p>
                </div>
                <div className="vactions">
                  <Button
                    variant="primary"
                    disabled={!can('voice.campaigns.manage')}
                    onClick={() => navigate('/campaigns?new=1&planner=1')}
                  >
                    Draft a campaign
                  </Button>
                </div>
              </Card>
            </div>

            <div className="vgrid vgrid--three">
              <Card title="Conversion funnel" subtitle="Every figure as a share of attempts.">
                {panels.funnel[0]?.count === 0 ? (
                  <EmptyState title="No attempts in this period" />
                ) : (
                  panels.funnel.map((stage) => (
                    <Bar
                      key={stage.key}
                      label={stage.label}
                      value={`${stage.count}${stage.percent !== null ? ` · ${stage.percent}%` : ''}`}
                      percent={stage.percent}
                    />
                  ))
                )}
                <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>
                  Confirmed outcomes are counted only when the owning product acknowledged them.
                </p>
              </Card>

              <Card title="Available modes" subtitle="What this connection can actually do.">
                {panels.modes.map((mode) => (
                  <Row
                    key={mode.key}
                    title={mode.label}
                    detail={mode.available ? undefined : mode.reason}
                    trailing={
                      mode.available ? (
                        <Badge><CheckCircle2 size={11} aria-hidden="true" />Available</Badge>
                      ) : (
                        <Badge tone="neutral"><Ban size={11} aria-hidden="true" />Not supported</Badge>
                      )
                    }
                  />
                ))}
              </Card>

              <Card title="Budget" subtitle="Enforced by the dispatcher, not only shown here.">
                {panels.budget.length === 0 ? (
                  <EmptyState title="No spending limit set" body="Set one in Settings to cap what campaigns can spend." />
                ) : (
                  panels.budget.map((policy) => (
                    <Bar
                      key={policy.policy_id}
                      label={`${policy.scope} · ${policy.period}`}
                      value={
                        policy.limit_minor > 0
                          ? `${formatMoney(policy.spent_minor, policy.currency)} of ${formatMoney(policy.limit_minor, policy.currency)}`
                          : formatMoney(policy.spent_minor, policy.currency)
                      }
                      percent={policy.limit_minor > 0 ? policy.percent : null}
                      tone={policy.state === 'exceeded' ? 'danger' : policy.state === 'warning' ? 'warning' : undefined}
                    />
                  ))
                )}
              </Card>
            </div>

            {detail ? <ReadinessPanel campaign={detail} onClose={() => setSelected(null)} /> : null}
          </>
        )
      }}
    </DashboardFrame>
  )
}

function CampaignActions({ campaign, canLaunch, pending, onAct }: {
  campaign: Campaign
  canLaunch: boolean
  pending: boolean
  onAct: (action: string) => void
}) {
  if (!canLaunch) return null

  return (
    <div className="vsplit" onClick={(event) => event.stopPropagation()}>
      {campaign.status === 'running' ? (
        <Button size="sm" icon={Pause} onClick={() => onAct('pause')} disabled={pending}>Pause</Button>
      ) : null}
      {campaign.status === 'paused' ? (
        <Button size="sm" icon={Play} onClick={() => onAct('resume')} disabled={pending}>Resume</Button>
      ) : null}
      {['draft', 'ready', 'scheduled'].includes(campaign.status) ? (
        <Button
          size="sm"
          variant="primary"
          icon={Play}
          onClick={() => onAct('start')}
          disabled={pending || campaign.ready === false}
          title={campaign.ready === false ? 'The launch checks have not passed' : undefined}
        >
          Start
        </Button>
      ) : null}
      {['running', 'paused', 'scheduled'].includes(campaign.status) ? (
        <Button size="sm" icon={XCircle} onClick={() => onAct('cancel')} disabled={pending}>Cancel</Button>
      ) : null}
    </div>
  )
}

function ReadinessPanel({ campaign, onClose }: { campaign: Campaign; onClose: () => void }) {
  const checks: ReadinessCheck[] = campaign.readiness?.checks ?? []

  return (
    <Card
      title={`${campaign.name} · launch checks`}
      subtitle="Every one of these is checked again by the server at launch."
      action={<Button size="sm" variant="ghost" onClick={onClose}>Close</Button>}
    >
      {checks.length === 0 ? (
        <EmptyState title="Not validated yet" body="Open the campaign and run the launch checks." />
      ) : (
        checks.map((check) => (
          <Row
            key={check.key}
            title={check.message}
            detail={check.note}
            trailing={<StatusPill status={check.status} />}
          />
        ))
      )}
      <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>
        These checks reflect the policies this business has configured. They are not a statement that a particular
        call is permitted — that depends on obligations this product cannot assess.
      </p>
    </Card>
  )
}
