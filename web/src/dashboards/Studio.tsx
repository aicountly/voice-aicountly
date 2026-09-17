/**
 * Dashboard 3 — AI Voice Studio.
 *
 * Every badge on this screen comes from a recorded rehearsal run. There is no
 * hardcoded "18 passed" anywhere: an agent nobody has tested says so, and the
 * Publish button is disabled because the SERVER will refuse it, not to look
 * tidy.
 */

import { useCallback, useMemo, useState } from 'react'
import { CheckCircle2, CircleDashed, Play, Rocket, Sparkles, XCircle } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { AiAgent, AiCheck, FlowDefinition, FlowValidation } from '../services/types'
import {
  Badge, Button, Card, EmptyState, Notice, PanelState, Row, StatusPill, timeAgo,
} from '../ui'
import { VoiceFlowEditor } from '../voice/VoiceFlowEditor'
import { DashboardFrame, useDashboard } from './frame'

interface Panels {
  agents: AiAgent[]
  ai: { available: boolean; model: string | null; provider: string | null; reason: string | null; admin_hint: string | null }
  scenarios: Array<{ key: string; label: string; detail: string }>
  provider_capabilities: Record<string, boolean>
  node_types: string[]
}

export default function Studio() {
  const { can } = useVoice()
  const state = useDashboard<Panels>('studio')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [scenario, setScenario] = useState('date_change_mid_sentence')

  const panels = state.data?.data.panels
  const agents = panels?.agents ?? []
  const selected = useMemo(
    () => agents.find((agent) => agent.ai_agent_id === selectedId) ?? agents[0] ?? null,
    [agents, selectedId],
  )

  const rehearse = useMutation((agentId: number, scenarioKey: string) =>
    api.post<{ data: { status: string; checks: AiCheck[]; simulated: boolean } }>(
      `v1/ai-agents/${agentId}/tests`,
      { scenario: scenarioKey },
    ),
  )

  const publish = useMutation((agentId: number) => api.post(`v1/ai-agents/${agentId}/publish`))

  const [lastRun, setLastRun] = useState<{ status: string; checks: AiCheck[] } | null>(null)

  const onRehearse = useCallback(async () => {
    if (!selected) return
    setLastRun(null)
    const result = await rehearse.mutate(selected.ai_agent_id, scenario)
    if (result) {
      setLastRun({ status: result.data.status, checks: result.data.checks })
      state.reload()
    }
  }, [selected, scenario, rehearse, state])

  const onPublish = useCallback(async () => {
    if (!selected) return
    const result = await publish.mutate(selected.ai_agent_id)
    if (result) state.reload()
  }, [selected, publish, state])

  return (
    <DashboardFrame
      title="AI Voice Studio"
      subtitle="Build confidence before the first call."
      view="studio"
      state={state}
      period={state.period}
      onPeriodChange={state.setPeriod}
      showPeriod={false}
    >
      {(envelope) => {
        const studio = envelope.panels
        const blocked = (selected?.validation?.errors.length ?? 0) > 0

        return (
          <>
            {!studio.ai.available ? (
              <Notice tone="warning" title="No AI provider is configured">
                <p>
                  {studio.ai.reason}
                  {studio.ai.admin_hint && can('voice.settings.manage') ? ` ${studio.ai.admin_hint}` : ''}
                  {' '}Agents can still be built and validated; they cannot take calls until a provider is connected.
                </p>
              </Notice>
            ) : null}

            <div className="vgrid vgrid--three">
              <Card
                title="Agents"
                subtitle={`${agents.length} configured.`}
                action={
                  can('voice.ai.manage') ? <Button size="sm" icon={Sparkles}>New agent</Button> : null
                }
              >
                {agents.length === 0 ? (
                  <EmptyState
                    title="No AI agents yet"
                    body="An AI agent answers calls, follows a flow you define, and hands over to a person when it should."
                  />
                ) : (
                  agents.map((agent) => (
                    <Row
                      key={agent.ai_agent_id}
                      title={agent.name}
                      detail={
                        <>
                          {agent.role || 'No role set'}
                          <span style={{ display: 'block', marginTop: 2 }}>
                            {agent.tests?.never_tested
                              ? 'Never rehearsed'
                              : `Last rehearsal ${timeAgo(agent.tests?.last_run_at)} · ${agent.tests?.passed ?? 0} checks passed`}
                          </span>
                        </>
                      }
                      trailing={<StatusPill status={agent.status} />}
                      onClick={() => setSelectedId(agent.ai_agent_id)}
                    />
                  ))
                )}
              </Card>

              <Card
                title={selected ? `${selected.name} · call flow` : 'Call flow'}
                subtitle={
                  selected
                    ? selected.published_version_no
                      ? `Published v${selected.published_version_no}${selected.draft_version_no ? `, draft v${selected.draft_version_no}` : ''}`
                      : 'Draft only — not taking calls'
                    : undefined
                }
              >
                {selected ? (
                  <FlowPreview flowId={selected.flow_id ?? null} nodeTypes={studio.node_types} />
                ) : (
                  <EmptyState title="Pick an agent" body="Its call flow appears here." />
                )}
              </Card>

              <div className="vstack">
                <Card title="Rehearsal room" subtitle="Simulated. No call is placed and nothing is written to another product.">
                  <Badge tone="neutral">Simulated conversation</Badge>

                  <label className="vfield" style={{ marginTop: 14 }}>
                    Scenario
                    <select value={scenario} onChange={(event) => setScenario(event.target.value)}>
                      {studio.scenarios.map((entry) => (
                        <option key={entry.key} value={entry.key}>{entry.label}</option>
                      ))}
                    </select>
                  </label>

                  <p className="vmuted vsmall" style={{ marginTop: 6 }}>
                    {studio.scenarios.find((entry) => entry.key === scenario)?.detail}
                  </p>

                  <div className="vactions">
                    <Button
                      variant="primary"
                      icon={Play}
                      onClick={() => void onRehearse()}
                      disabled={!selected || rehearse.pending || !can('voice.ai.manage')}
                    >
                      {rehearse.pending ? 'Running…' : 'Run rehearsal'}
                    </Button>
                  </div>

                  {rehearse.error ? (
                    <div style={{ marginTop: 12 }}>
                      <Notice tone="danger">{rehearse.error.message}</Notice>
                    </div>
                  ) : null}

                  {lastRun ? <RunResult run={lastRun} /> : null}
                </Card>

                <Card title="Readiness" subtitle="What the server checks before publishing.">
                  {!selected ? (
                    <EmptyState title="Pick an agent" />
                  ) : (
                    <>
                      {selected.validation?.errors.length === 0 && selected.validation.warnings.length === 0 ? (
                        <Row title="All checks pass" detail="Nothing outstanding." trailing={<Badge>Ready</Badge>} />
                      ) : null}

                      {selected.validation?.errors.map((issue) => (
                        <Row
                          key={`${issue.code}-${issue.node}`}
                          title={issue.message}
                          detail={issue.node ? `Step: ${issue.node}` : undefined}
                          trailing={<Badge tone="red">Blocks publishing</Badge>}
                        />
                      ))}

                      {selected.validation?.warnings.map((issue) => (
                        <Row
                          key={`${issue.code}-${issue.node}`}
                          title={issue.message}
                          detail={issue.node ? `Step: ${issue.node}` : undefined}
                          trailing={<Badge tone="amber">Worth a look</Badge>}
                        />
                      ))}

                      <div className="vactions">
                        <Button
                          variant="primary"
                          icon={Rocket}
                          full
                          disabled={blocked || publish.pending || !can('voice.ai.publish')}
                          onClick={() => void onPublish()}
                          title={blocked ? 'Fix the outstanding checks first' : undefined}
                        >
                          {blocked ? 'Publish after the checks pass' : publish.pending ? 'Publishing…' : 'Publish'}
                        </Button>
                      </div>

                      {publish.error ? <Notice tone="danger">{publish.error.message}</Notice> : null}

                      <p className="vmuted vsmall" style={{ marginTop: 10, marginBottom: 0 }}>
                        A published version is immutable. Editing creates a draft; calls in progress finish on the
                        version they started with.
                      </p>
                    </>
                  )}
                </Card>
              </div>
            </div>
          </>
        )
      }}
    </DashboardFrame>
  )
}

function RunResult({ run }: { run: { status: string; checks: AiCheck[] } }) {
  return (
    <div style={{ marginTop: 16, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
      <div className="vspread" style={{ marginBottom: 10 }}>
        <h3 style={{ margin: 0 }}>Result</h3>
        <Badge tone={run.status === 'passed' ? 'default' : 'red'}>
          {run.status === 'passed' ? 'Passed' : 'Failed'}
        </Badge>
      </div>

      {run.checks.map((check) => (
        <Row
          key={check.key}
          title={
            <span className="vsplit">
              {check.status === 'passed' ? (
                <CheckCircle2 size={14} aria-hidden="true" style={{ color: 'var(--primary)' }} />
              ) : check.status === 'failed' ? (
                <XCircle size={14} aria-hidden="true" style={{ color: 'var(--danger)' }} />
              ) : (
                <CircleDashed size={14} aria-hidden="true" style={{ color: 'var(--muted)' }} />
              )}
              {check.label}
            </span>
          }
          detail={check.detail}
        />
      ))}
    </div>
  )
}

/** The agent's flow, read and validated against the live provider capabilities. */
function FlowPreview({ flowId, nodeTypes }: {
  flowId: number | null
  nodeTypes: string[]
}) {
  const { company, branchId } = useVoice()

  const flow = useApi<{ data: { versions: Array<{ definition: FlowDefinition; validation: FlowValidation; status: string }> } }>(
    (signal) => api.get(`v1/call-flows/${flowId}`, undefined, signal),
    [company?.cmp_id, branchId, flowId],
    { enabled: flowId !== null },
  )

  if (flowId === null) {
    return (
      <EmptyState
        title="No call flow attached"
        body="An agent needs a flow to follow. Create one under Call Flows and attach it here."
      />
    )
  }

  return (
    <PanelState state={flow} what="the call flow">
      {(data) => {
        const version = data.data.versions[0]
        if (!version) return <EmptyState title="This flow has no versions yet" />

        return (
          <VoiceFlowEditor
            definition={version.definition}
            validation={version.validation}
            nodeTypes={nodeTypes}
            actions={[]}
            onChange={() => undefined}
            readOnly
          />
        )
      }}
    </PanelState>
  )
}
