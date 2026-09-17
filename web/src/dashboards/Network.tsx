/**
 * Dashboard 6 — Network & Usage.
 *
 * ## Four latencies, never averaged into one
 *
 * Media transport, speech recognition, model and tools, and time to first
 * audio. They have different causes and different fixes. Anything the gateway
 * does not report reads "not measured", which is the useful answer — a single
 * blended figure sends somebody to tune the wrong thing.
 *
 * ## An untested backup is not a backup
 *
 * A standby route shows when it was last actually tested, and prompts when that
 * was too long ago. A green dot on a route nobody has exercised is exactly the
 * reassurance that fails on the day it matters.
 */

import { useCallback } from 'react'
import { Activity, PlugZap, RefreshCw, TestTube2 } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { BudgetStatus, IntegrationStatus, UsageBreakdown, VoiceNumber } from '../services/types'
import {
  Badge, Bar, Button, Card, EmptyState, Notice, Row, StatusPill, formatMoney, timeAgo,
} from '../ui'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  connections: Array<{
    connection_id: number
    label: string
    adapter_label: string
    role: string
    status: string
    status_detail: string | null
    capabilities: Record<string, boolean>
    credentials: { configured: boolean; fields: string[] }
    latency_ms?: number | null
  }>
  routes: Array<{
    connection_id: number
    label: string
    role: string
    status: string
    status_detail: string | null
    active_calls: number
    latency_ms: number | null
    last_tested_at: string | null
    test_due: boolean
    failover: boolean
    failover_note: string
  }>
  numbers: VoiceNumber[]
  capacity: { active: number; limit: number; source: string; percent: number }
  usage: UsageBreakdown
  budget: BudgetStatus[]
  latency: {
    media_ms: number | null
    stt_ms: number | null
    model_ms: number | null
    first_audio_ms: number | null
    samples: number
    labels: Record<string, string>
    note: string
  }
  integrations: IntegrationStatus[]
  errors: Array<{ event_type: string; occurrences: number; last_seen: string }>
}

export default function Network() {
  const { can } = useVoice()
  const state = useDashboard<Panels>('network')

  const test = useMutation((connectionId: number) => api.post(`v1/provider-connections/${connectionId}/test`))

  const onTest = useCallback(
    async (connectionId: number) => {
      const result = await test.mutate(connectionId)
      if (result) state.reload()
    },
    [test, state],
  )

  return (
    <DashboardFrame
      title="Network & Usage"
      subtitle="Keep every conversation connected."
      view="network"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
      actions={<Button icon={RefreshCw} onClick={state.reload}>Re-check</Button>}
    >
      {(envelope) => {
        const panels = envelope.panels

        return (
          <>
            {test.error ? <Notice tone="danger">{test.error.message}</Notice> : null}

            <div className="vgrid vgrid--two">
              <Card title="Connection health" subtitle="Checked against the provider just now.">
                {panels.routes.length === 0 ? (
                  <EmptyState
                    title="No telephony provider connected"
                    body="Voice cannot place or receive calls until a provider connection is configured."
                    icon={PlugZap}
                    action={
                      can('voice.providers.manage') ? <Button variant="primary">Add a connection</Button> : undefined
                    }
                  />
                ) : (
                  <div className="vtable-wrap">
                    <table className="vtable">
                      <caption className="sr-only">Provider routes</caption>
                      <thead>
                        <tr>
                          <th scope="col">Route</th>
                          <th scope="col">Status</th>
                          <th scope="col" className="vtable__num">Calls</th>
                          <th scope="col">Last tested</th>
                          <th scope="col"><span className="sr-only">Actions</span></th>
                        </tr>
                      </thead>
                      <tbody>
                        {panels.routes.map((route) => (
                          <tr key={route.connection_id}>
                            <td>
                              <strong>{route.label}</strong>
                              <small>{route.role === 'backup' ? 'Backup route' : 'Primary route'}</small>
                            </td>
                            <td>
                              <StatusPill status={route.status} />
                              {route.status_detail ? <small>{route.status_detail}</small> : null}
                            </td>
                            <td className="vtable__num">{route.active_calls}</td>
                            <td>
                              {route.last_tested_at ? timeAgo(route.last_tested_at) : 'Never'}
                              {route.test_due ? (
                                <small style={{ color: 'var(--warning)' }}>Test overdue</small>
                              ) : null}
                            </td>
                            <td>
                              {can('voice.providers.manage') ? (
                                <Button
                                  size="sm"
                                  icon={TestTube2}
                                  onClick={() => void onTest(route.connection_id)}
                                  disabled={test.pending}
                                >
                                  Test
                                </Button>
                              ) : null}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {panels.routes.some((route) => route.role === 'backup') ? (
                  <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>
                    {panels.routes.find((route) => route.role === 'backup')?.failover_note}
                  </p>
                ) : null}
              </Card>

              <Card title="Usage" subtitle={`${envelope.period.label} · ${panels.usage.currency}`}>
                {panels.usage.categories.length === 0 ? (
                  <EmptyState title="No usage priced for this period" />
                ) : (
                  <>
                    {panels.usage.categories.map((category) => {
                      const total = panels.usage.totals.estimated_minor + panels.usage.totals.confirmed_minor
                      const amount = category.confirmed_minor > 0 ? category.confirmed_minor : category.estimated_minor
                      return (
                        <Bar
                          key={category.category}
                          label={
                            <>
                              {category.category.replace(/_/g, ' ')}
                              {category.confirmed_minor > 0 ? (
                                <Badge tone="neutral" >Confirmed</Badge>
                              ) : (
                                <Badge tone="amber">Estimated</Badge>
                              )}
                            </>
                          }
                          value={formatMoney(amount, panels.usage.currency)}
                          percent={total > 0 ? (amount / total) * 100 : null}
                          tone={category.confirmed_minor > 0 ? undefined : 'muted'}
                        />
                      )
                    })}

                    <div className="vrow" style={{ borderTop: '1px solid var(--border)', marginTop: 8 }}>
                      <strong>Estimated</strong>
                      <strong>{formatMoney(panels.usage.totals.estimated_minor, panels.usage.currency)}</strong>
                    </div>
                    <div className="vrow">
                      <strong>Provider-confirmed</strong>
                      <strong>{formatMoney(panels.usage.totals.confirmed_minor, panels.usage.currency)}</strong>
                    </div>

                    {panels.usage.note ? (
                      <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>{panels.usage.note}</p>
                    ) : null}
                  </>
                )}
              </Card>
            </div>

            <div className="vgrid vgrid--three">
              <Card title="Response times" subtitle="Measured separately — never blended.">
                {panels.latency.samples === 0 ? (
                  <EmptyState
                    title="Not measured"
                    body="The gateway has not reported timing for this period."
                    icon={Activity}
                  />
                ) : (
                  <>
                    {(['media_ms', 'stt_ms', 'model_ms', 'first_audio_ms'] as const).map((key) => (
                      <Row
                        key={key}
                        title={panels.latency.labels[key]}
                        detail={panels.latency[key] === null ? 'Not reported by this gateway' : undefined}
                        trailing={
                          panels.latency[key] === null ? (
                            <Badge tone="neutral">Not measured</Badge>
                          ) : (
                            <Badge tone={panels.latency[key]! > 400 ? 'amber' : 'default'}>
                              {panels.latency[key]} ms
                            </Badge>
                          )
                        }
                      />
                    ))}
                    <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>{panels.latency.note}</p>
                  </>
                )}
              </Card>

              <Card title="Your numbers" subtitle={`${panels.numbers.length} configured.`}>
                {panels.numbers.length === 0 ? (
                  <EmptyState title="No business numbers" body="Add a number so customers can reach you." />
                ) : (
                  panels.numbers.slice(0, 8).map((number) => (
                    <Row
                      key={number.number_id}
                      title={number.masked}
                      detail={
                        <>
                          {number.label || number.number_type}
                          {number.flow_name ? ` · ${number.flow_name}` : ''}
                          {number.team_name ? ` · ${number.team_name}` : ''}
                        </>
                      }
                      trailing={<StatusPill status={number.is_active ? number.routing_status : 'not_configured'} />}
                    />
                  ))
                )}
              </Card>

              <Card title="Capacity & budget">
                <Bar
                  label="Concurrent calls"
                  value={panels.capacity.limit > 0 ? `${panels.capacity.active} / ${panels.capacity.limit}` : String(panels.capacity.active)}
                  percent={panels.capacity.limit > 0 ? panels.capacity.percent : null}
                  tone={panels.capacity.percent > 85 ? 'danger' : panels.capacity.percent > 70 ? 'warning' : undefined}
                />
                {panels.capacity.limit === 0 ? (
                  <p className="vmuted vsmall" style={{ marginTop: -8 }}>
                    No concurrency limit is configured for this company.
                  </p>
                ) : null}

                {panels.budget.map((policy) => (
                  <Bar
                    key={policy.policy_id}
                    label={`${policy.period} budget`}
                    value={
                      policy.limit_minor > 0
                        ? `${formatMoney(policy.spent_minor, policy.currency)} of ${formatMoney(policy.limit_minor, policy.currency)}`
                        : formatMoney(policy.spent_minor, policy.currency)
                    }
                    percent={policy.limit_minor > 0 ? policy.percent : null}
                    tone={policy.state === 'exceeded' ? 'danger' : policy.state === 'warning' ? 'warning' : undefined}
                  />
                ))}

                {panels.budget.some((policy) => policy.state === 'exceeded' && policy.on_exceed === 'block') ? (
                  <Notice tone="danger" title="Spending limit reached">
                    <p>New calls are being refused until the period resets or the limit is raised.</p>
                  </Notice>
                ) : null}
              </Card>
            </div>

            <div className="vgrid vgrid--two">
              <Card title="Aicountly integrations" subtitle="What Voice may read from and write to.">
                {panels.integrations.map((integration) => (
                  <Row
                    key={integration.app}
                    title={integration.label}
                    detail={
                      integration.reason
                        ?? (integration.last_ok_at ? `Last worked ${timeAgo(integration.last_ok_at)}` : undefined)
                    }
                    trailing={<StatusPill status={integration.status} />}
                  />
                ))}
                <p className="vmuted vsmall" style={{ marginTop: 12, marginBottom: 0 }}>
                  Every cross-product read and write goes through the owning product’s live API. Voice stores
                  references, never copies of their records.
                </p>
              </Card>

              <Card title="Provider errors" subtitle="Most frequent first, this period.">
                {panels.errors.length === 0 ? (
                  <EmptyState title="No provider errors" body="Nothing has failed in this period." />
                ) : (
                  panels.errors.map((error) => (
                    <Row
                      key={error.event_type}
                      title={error.event_type}
                      detail={`Last seen ${timeAgo(error.last_seen)}`}
                      trailing={<Badge tone={error.occurrences > 10 ? 'red' : 'amber'}>{error.occurrences}</Badge>}
                    />
                  ))
                )}
              </Card>
            </div>
          </>
        )
      }}
    </DashboardFrame>
  )
}
