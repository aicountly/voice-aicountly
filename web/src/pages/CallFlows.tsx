/**
 * Call flows.
 *
 * The editor writes the EXECUTABLE definition. Validation runs against the same
 * server-side validator that gates publishing, so what is shown here is exactly
 * what will block publication.
 */

import { useCallback, useEffect, useState } from 'react'
import { Plus, Rocket, Save } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi, useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { FlowDefinition, FlowValidation } from '../services/types'
import { PageHeader } from '../shell/AppShell'
import { Button, Card, EmptyState, Notice, PanelState, Row, StatusPill } from '../ui'
import { VoiceFlowEditor } from '../voice/VoiceFlowEditor'

interface FlowSummary {
  flow_id: number
  name: string
  description: string
  published_version_no: number | null
  draft_version_no: number | null
  validation: FlowValidation | null
  is_active: boolean
}

export default function CallFlows() {
  const { company, branchId, can } = useVoice()
  const [selected, setSelected] = useState<number | null>(null)

  const list = useApi<{ data: { flows: FlowSummary[]; node_types: string[]; capabilities: Record<string, boolean> } }>(
    (signal) => api.get('v1/call-flows', undefined, signal),
    [company?.cmp_id, branchId],
  )

  const flows = list.data?.data.flows ?? []
  const current = flows.find((flow) => flow.flow_id === selected) ?? flows[0] ?? null

  return (
    <>
      <PageHeader
        title="Call Flows"
        subtitle="What a caller is walked through. This is the flow that runs, not a picture of one."
        actions={can('voice.flows.manage') ? <Button variant="primary" icon={Plus}>New flow</Button> : null}
      />

      <PanelState
        state={list}
        what="call flows"
        isEmpty={(data) => data.data.flows.length === 0}
        empty={
          <Card>
            <EmptyState
              title="No call flows yet"
              body="A flow decides what happens when somebody calls: what they hear, what is asked, and who they reach."
            />
          </Card>
        }
      >
        {(data) => (
          <div className="vgrid vgrid--two">
            <Card title="Flows">
              {data.data.flows.map((flow) => (
                <Row
                  key={flow.flow_id}
                  title={flow.name}
                  detail={
                    flow.published_version_no
                      ? `Published v${flow.published_version_no}${flow.draft_version_no ? `, draft v${flow.draft_version_no}` : ''}`
                      : 'Draft only — not routing calls'
                  }
                  trailing={
                    <StatusPill status={flow.published_version_no ? 'published' : 'draft'} />
                  }
                  onClick={() => setSelected(flow.flow_id)}
                />
              ))}
            </Card>

            {current ? (
              <FlowEditorPanel
                flowId={current.flow_id}
                name={current.name}
                nodeTypes={data.data.node_types}
                canEdit={can('voice.flows.manage')}
                onSaved={list.reload}
              />
            ) : null}
          </div>
        )}
      </PanelState>
    </>
  )
}

function FlowEditorPanel({ flowId, name, nodeTypes, canEdit, onSaved }: {
  flowId: number
  name: string
  nodeTypes: string[]
  canEdit: boolean
  onSaved: () => void
}) {
  const { company, branchId } = useVoice()
  const [definition, setDefinition] = useState<FlowDefinition | null>(null)
  const [validation, setValidation] = useState<FlowValidation | null>(null)
  const [dirty, setDirty] = useState(false)

  const detail = useApi<{
    data: { versions: Array<{ version_id: number; definition: FlowDefinition; validation: FlowValidation; status: string }> }
  }>(
    (signal) => api.get(`v1/call-flows/${flowId}`, undefined, signal),
    [company?.cmp_id, branchId, flowId],
  )

  // The loaded version seeds the editor. Switching flow resets it entirely
  // rather than merging into whatever was open.
  useEffect(() => {
    const version = detail.data?.data.versions[0]
    setDefinition(version?.definition ?? { entry: '', nodes: {} })
    setValidation(version?.validation ?? null)
    setDirty(false)
  }, [detail.data, flowId])

  const validate = useMutation((next: FlowDefinition) =>
    api.post<{ data: FlowValidation }>('v1/call-flows/validate', { definition: next }),
  )

  const save = useMutation((next: FlowDefinition) =>
    api.post<{ data: { version_id: number; validation: FlowValidation } }>(
      `v1/call-flows/${flowId}/versions`,
      { definition: next },
    ),
  )

  const publish = useMutation(() => api.post(`v1/call-flows/${flowId}/publish`))

  // Validated by the same class the publish endpoint uses, so nothing shown
  // here can disagree with what blocks publication.
  const onChange = useCallback(
    async (next: FlowDefinition) => {
      setDefinition(next)
      setDirty(true)
      const result = await validate.mutate(next)
      if (result) setValidation(result.data)
    },
    [validate],
  )

  const onSave = useCallback(async () => {
    if (!definition) return
    const result = await save.mutate(definition)
    if (result) {
      setValidation(result.data.validation)
      setDirty(false)
      onSaved()
    }
  }, [definition, save, onSaved])

  const onPublish = useCallback(async () => {
    const result = await publish.mutate()
    if (result) {
      detail.reload()
      onSaved()
    }
  }, [publish, detail, onSaved])

  return (
    <Card
      title={name}
      subtitle="Every change is checked against the same validator that gates publishing."
      action={
        canEdit ? (
          <div className="vsplit">
            <Button size="sm" icon={Save} onClick={() => void onSave()} disabled={!dirty || save.pending}>
              {save.pending ? 'Saving…' : 'Save draft'}
            </Button>
            <Button
              size="sm"
              variant="primary"
              icon={Rocket}
              onClick={() => void onPublish()}
              disabled={dirty || publish.pending || validation?.valid !== true}
              title={dirty ? 'Save the draft first' : validation?.valid ? undefined : 'Fix the outstanding problems first'}
            >
              Publish
            </Button>
          </div>
        ) : null
      }
    >
      {publish.error ? <Notice tone="danger">{publish.error.message}</Notice> : null}
      {save.error ? <Notice tone="danger">{save.error.message}</Notice> : null}

      <PanelState state={detail} what="this flow">
        {() =>
          definition ? (
            <VoiceFlowEditor
              definition={definition}
              validation={validation}
              nodeTypes={nodeTypes}
              actions={[]}
              onChange={(next) => void onChange(next)}
              readOnly={!canEdit}
            />
          ) : null
        }
      </PanelState>
    </Card>
  )
}
