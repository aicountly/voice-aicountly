/**
 * The call-flow editor.
 *
 * ## This edits the executable flow, not a picture of one
 *
 * What it writes is `voice_call_flow_versions.definition` — the graph a live
 * caller is actually walked through. The diagram below IS that graph, laid out
 * from the entry node by following each step's destinations. There is no
 * decorative rendering beside a separate configuration.
 *
 * It is a structured editor rather than a free-form canvas: nodes are added,
 * connected and configured through forms, and the layout is derived. That is a
 * deliberate trade — a drag-and-drop canvas is nicer to demo, and this one
 * cannot produce a flow whose picture and behaviour disagree.
 *
 * ## Validation is live and the same validation the server runs
 *
 * Every change posts to /v1/call-flows/validate, which runs FlowValidator — the
 * same class that gates publishing. So the errors shown here are exactly the
 * errors that will block publication, never an approximation of them.
 */

import { useMemo, useState } from 'react'
import { AlertTriangle, ArrowDown, Plus, Trash2, TriangleAlert } from 'lucide-react'

import { Badge, Button, Field, Notice } from '../ui'
import type { FlowDefinition, FlowIssue, FlowNode, FlowValidation } from '../services/types'

const NODE_LABELS: Record<string, string> = {
  greeting: 'Greeting',
  disclosure: 'Recording / AI disclosure',
  intent: 'Understand intent',
  knowledge: 'Answer from knowledge',
  api_action: 'Take an action',
  confirm: 'Confirm with the caller',
  dtmf_input: 'Keypad input',
  handover: 'Hand over to a person',
  retry: 'Retry',
  voicemail: 'Voicemail',
  end_call: 'End the call',
}

const TERMINAL = ['end_call', 'voicemail', 'handover']
const NEEDS_TIMEOUT = ['intent', 'dtmf_input', 'confirm']

/**
 * A definition that is safe to walk.
 *
 * Defensive on purpose. A jsonb column that reaches the browser as a string,
 * or a version with no nodes yet, would otherwise throw inside the layout and
 * blank the whole screen — and a blank screen tells the person looking at it
 * nothing at all. An empty flow renders as an empty flow.
 */
function safeDefinition(input: FlowDefinition | null | undefined): FlowDefinition {
  if (!input || typeof input !== 'object') return { entry: '', nodes: {} }
  const nodes = input.nodes && typeof input.nodes === 'object' ? input.nodes : {}
  return { entry: typeof input.entry === 'string' ? input.entry : '', nodes }
}

export function VoiceFlowEditor({
  definition: rawDefinition, validation, nodeTypes, actions, onChange, readOnly,
}: {
  definition: FlowDefinition | null
  validation: FlowValidation | null
  nodeTypes: string[]
  /** Action keys the agent may take, from the server's allowlist. */
  actions: Array<{ key: string; label: string; mode: string }>
  onChange: (next: FlowDefinition) => void
  readOnly?: boolean
}) {
  const definition = useMemo(() => safeDefinition(rawDefinition), [rawDefinition])
  const [selected, setSelected] = useState<string | null>(definition.entry || null)

  const order = useMemo(() => layout(definition), [definition])
  const issuesByNode = useMemo(() => groupIssues(validation), [validation])

  const update = (id: string, patch: Partial<FlowNode>) => {
    onChange({
      ...definition,
      nodes: { ...definition.nodes, [id]: { ...definition.nodes[id], ...patch } },
    })
  }

  const addNode = (type: string) => {
    const id = `${type}_${Object.keys(definition.nodes).length + 1}`
    const node: FlowNode = { type }
    if (NEEDS_TIMEOUT.includes(type)) node.timeout_seconds = 8
    if (type === 'retry') node.max_attempts = 2

    onChange({
      entry: definition.entry || id,
      nodes: { ...definition.nodes, [id]: node },
    })
    setSelected(id)
  }

  const removeNode = (id: string) => {
    const nodes = { ...definition.nodes }
    delete nodes[id]

    // Every reference to the removed step goes with it, or the flow is left
    // pointing at something that no longer exists.
    for (const [key, node] of Object.entries(nodes)) {
      const cleaned: FlowNode = { ...node }
      if (cleaned.next === id) delete cleaned.next
      for (const branch of ['timeout', 'on_failure', 'on_no_match'] as const) {
        if (cleaned[branch] === id) delete cleaned[branch]
      }
      if (cleaned.branches) {
        cleaned.branches = Object.fromEntries(
          Object.entries(cleaned.branches).filter(([, target]) => target !== id),
        )
      }
      nodes[key] = cleaned
    }

    onChange({
      entry: definition.entry === id ? (Object.keys(nodes)[0] ?? '') : definition.entry,
      nodes,
    })
    setSelected(null)
  }

  const nodeOptions = Object.keys(definition.nodes).map((id) => ({
    value: id,
    label: `${id} · ${NODE_LABELS[definition.nodes[id]?.type ?? ''] ?? definition.nodes[id]?.type}`,
  }))

  return (
    <div className="vgrid vgrid--two" style={{ marginBottom: 0 }}>
      <div>
        {validation ? <ValidationSummary validation={validation} /> : null}

        <div className="vflow" style={{ marginTop: 14 }}>
          {order.length === 0 ? (
            <p className="vmuted vsmall">No steps yet. Add a greeting to begin.</p>
          ) : null}

          {order.map((id, index) => {
            const node = definition.nodes[id]
            if (!node) return null
            const issues = issuesByNode[id] ?? []
            const hasError = issues.some((issue) => issue.severity === 'error')

            return (
              <div key={id}>
                <button
                  type="button"
                  className={[
                    'vflow__node',
                    selected === id ? 'vflow__node--selected' : '',
                    hasError ? 'vflow__node--error' : '',
                    node.type === 'handover' ? 'vflow__node--handover' : '',
                  ].filter(Boolean).join(' ')}
                  style={{ width: '100%', textAlign: 'left', cursor: 'pointer' }}
                  onClick={() => setSelected(id)}
                  aria-current={selected === id}
                >
                  <span className="vflow__type">
                    {NODE_LABELS[node.type] ?? node.type}
                    {id === definition.entry ? ' · starts the call' : ''}
                  </span>
                  <span className="vflow__title">{(node.label as string) ?? id}</span>

                  {node.type === 'api_action' && node.action ? (
                    <span className="vmuted vsmall">Action: {node.action}</span>
                  ) : null}
                  {node.type === 'handover' ? (
                    <span className="vmuted vsmall">
                      {node.destination ? `To ${node.destination}` : 'No destination assigned'}
                    </span>
                  ) : null}

                  <span className="vflow__branches">
                    {Object.entries(destinations(node)).map(([branch, target]) => (
                      <Badge key={branch} tone={branch === 'next' ? 'default' : 'neutral'}>
                        {branch === 'next' ? '→' : `${branch} →`} {target}
                      </Badge>
                    ))}
                  </span>

                  {issues.map((issue) => (
                    <span
                      key={issue.code}
                      className="vsmall"
                      style={{ color: issue.severity === 'error' ? 'var(--danger)' : 'var(--warning)' }}
                    >
                      {issue.severity === 'error' ? <AlertTriangle size={11} aria-hidden="true" /> : <TriangleAlert size={11} aria-hidden="true" />}
                      {' '}{issue.message}
                    </span>
                  ))}
                </button>

                {index < order.length - 1 && !TERMINAL.includes(node.type) ? (
                  <div className="vflow__arrow" aria-hidden="true"><ArrowDown size={14} /></div>
                ) : null}
              </div>
            )
          })}
        </div>

        {!readOnly ? (
          <div className="vactions">
            <select
              className="vinput"
              value=""
              onChange={(event) => {
                if (event.target.value) addNode(event.target.value)
              }}
              aria-label="Add a step"
              style={{ maxWidth: 240 }}
            >
              <option value="">Add a step…</option>
              {nodeTypes.map((type) => (
                <option key={type} value={type}>{NODE_LABELS[type] ?? type}</option>
              ))}
            </select>
          </div>
        ) : null}
      </div>

      <div className="vcard">
        {selected && definition.nodes[selected] ? (
          <NodeForm
            id={selected}
            node={definition.nodes[selected]}
            nodeOptions={nodeOptions.filter((option) => option.value !== selected)}
            actions={actions}
            isEntry={definition.entry === selected}
            readOnly={readOnly}
            onUpdate={(patch) => update(selected, patch)}
            onMakeEntry={() => onChange({ ...definition, entry: selected })}
            onRemove={() => removeNode(selected)}
          />
        ) : (
          <p className="vmuted vsmall">Pick a step to configure it.</p>
        )}
      </div>
    </div>
  )
}

function NodeForm({
  id, node, nodeOptions, actions, isEntry, readOnly, onUpdate, onMakeEntry, onRemove,
}: {
  id: string
  node: FlowNode
  nodeOptions: Array<{ value: string; label: string }>
  actions: Array<{ key: string; label: string; mode: string }>
  isEntry: boolean
  readOnly?: boolean
  onUpdate: (patch: Partial<FlowNode>) => void
  onMakeEntry: () => void
  onRemove: () => void
}) {
  const selectedAction = actions.find((action) => action.key === node.action)

  return (
    <div className="vstack vstack--tight">
      <div className="vspread">
        <h3 style={{ margin: 0 }}>{NODE_LABELS[node.type] ?? node.type}</h3>
        <Badge tone="neutral">{id}</Badge>
      </div>

      <Field label="Label" hint="What this step is for, in your own words.">
        <input
          value={(node.label as string) ?? ''}
          onChange={(event) => onUpdate({ label: event.target.value })}
          disabled={readOnly}
        />
      </Field>

      {node.type === 'api_action' ? (
        <>
          <Field label="Action" hint="Only actions this product can perform are listed.">
            <select
              value={node.action ?? ''}
              onChange={(event) => onUpdate({ action: event.target.value })}
              disabled={readOnly}
            >
              <option value="">Choose an action…</option>
              {actions.map((action) => (
                <option key={action.key} value={action.key}>{action.label}</option>
              ))}
            </select>
          </Field>
          {selectedAction ? (
            <Notice tone={selectedAction.mode === 'denied' ? 'danger' : 'info'}>
              <p>
                {selectedAction.mode === 'denied'
                  ? 'The agent is not permitted to take this action. Grant it in Action permissions, or remove this step.'
                  : selectedAction.mode === 'confirm_with_caller'
                    ? 'The caller must confirm before this runs. Put a confirmation step before it.'
                    : selectedAction.mode === 'handoff'
                      ? 'This action hands the call to a person rather than running.'
                      : 'The agent may take this action.'}
              </p>
            </Notice>
          ) : null}
        </>
      ) : null}

      {node.type === 'handover' ? (
        <Field label="Hand over to" hint="A queue, a team or an extension.">
          <input
            value={node.destination ?? ''}
            onChange={(event) => onUpdate({ destination: event.target.value })}
            disabled={readOnly}
          />
        </Field>
      ) : null}

      {NEEDS_TIMEOUT.includes(node.type) ? (
        <Field label="Wait for the caller (seconds)" hint="A step that waits forever strands the caller.">
          <input
            type="number"
            min={1}
            max={120}
            value={node.timeout_seconds ?? ''}
            onChange={(event) => onUpdate({ timeout_seconds: Number(event.target.value) })}
            disabled={readOnly}
          />
        </Field>
      ) : null}

      {node.type === 'retry' ? (
        <Field label="Maximum attempts" hint="Between 1 and 5. An unbounded retry is a caller who cannot leave.">
          <input
            type="number"
            min={1}
            max={5}
            value={node.max_attempts ?? ''}
            onChange={(event) => onUpdate({ max_attempts: Number(event.target.value) })}
            disabled={readOnly}
          />
        </Field>
      ) : null}

      {!TERMINAL.includes(node.type) ? (
        <Field label="Then go to">
          <select value={node.next ?? ''} onChange={(event) => onUpdate({ next: event.target.value })} disabled={readOnly}>
            <option value="">Not set</option>
            {nodeOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </Field>
      ) : null}

      {NEEDS_TIMEOUT.includes(node.type) ? (
        <Field label="If the caller says nothing" hint="Required — otherwise a silent caller waits forever.">
          <select value={node.timeout ?? ''} onChange={(event) => onUpdate({ timeout: event.target.value })} disabled={readOnly}>
            <option value="">Not set</option>
            {nodeOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </Field>
      ) : null}

      {node.type === 'api_action' ? (
        <Field label="If the action fails" hint="What happens when the owning product cannot be reached.">
          <select value={node.on_failure ?? ''} onChange={(event) => onUpdate({ on_failure: event.target.value })} disabled={readOnly}>
            <option value="">Not set</option>
            {nodeOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </Field>
      ) : null}

      {!readOnly ? (
        <div className="vactions">
          {!isEntry ? <Button size="sm" onClick={onMakeEntry}>Start the call here</Button> : null}
          <Button size="sm" variant="danger" icon={Trash2} onClick={onRemove}>Remove step</Button>
        </div>
      ) : null}
    </div>
  )
}

function ValidationSummary({ validation }: { validation: FlowValidation }) {
  const errors = Array.isArray(validation.errors) ? validation.errors : []
  const warnings = Array.isArray(validation.warnings) ? validation.warnings : []

  if (validation.valid && warnings.length === 0) {
    return (
      <Notice tone="info" title="This flow is executable">
        <p>{validation.checked ?? 0} steps checked, nothing outstanding.</p>
      </Notice>
    )
  }

  return (
    <div className="vstack vstack--tight">
      {errors.length > 0 ? (
        <Notice tone="danger" title={`${errors.length} ${errors.length === 1 ? 'problem' : 'problems'} blocking publication`}>
          <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
            {errors.map((issue) => (
              <li key={`${issue.code}-${issue.node}`}>
                {issue.node ? <strong>{issue.node}: </strong> : null}{issue.message}
              </li>
            ))}
          </ul>
        </Notice>
      ) : null}

      {warnings.length > 0 ? (
        <Notice tone="warning" title="Worth a look — these do not block publication">
          <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
            {warnings.map((issue) => (
              <li key={`${issue.code}-${issue.node}`}>
                {issue.node ? <strong>{issue.node}: </strong> : null}{issue.message}
              </li>
            ))}
          </ul>
        </Notice>
      ) : null}
    </div>
  )
}

/**
 * The order to draw the steps in.
 *
 * Breadth-first from the entry node, so the diagram follows the path a caller
 * takes. Anything unreachable is appended at the end rather than hidden — an
 * orphaned step somebody forgot to connect should be visible, and the validator
 * warns about it separately.
 */
function layout(definition: FlowDefinition): string[] {
  const seen = new Set<string>()
  const order: string[] = []
  const queue = definition.entry ? [definition.entry] : []

  while (queue.length > 0) {
    const id = queue.shift()!
    if (seen.has(id) || !definition.nodes[id]) continue
    seen.add(id)
    order.push(id)
    for (const target of Object.values(destinations(definition.nodes[id]))) {
      if (!seen.has(target)) queue.push(target)
    }
  }

  for (const id of Object.keys(definition.nodes)) {
    if (!seen.has(id)) order.push(id)
  }

  return order
}

function destinations(node: FlowNode): Record<string, string> {
  const out: Record<string, string> = {}
  if (node.next) out.next = node.next
  for (const branch of ['timeout', 'on_failure', 'on_no_match'] as const) {
    const target = node[branch]
    if (typeof target === 'string' && target) out[branch] = target
  }
  for (const [branch, target] of Object.entries(node.branches ?? {})) {
    if (target) out[branch] = target
  }
  return out
}

function groupIssues(validation: FlowValidation | null): Record<string, Array<FlowIssue & { severity: 'error' | 'warning' }>> {
  const out: Record<string, Array<FlowIssue & { severity: 'error' | 'warning' }>> = {}
  if (!validation) return out

  for (const issue of Array.isArray(validation.errors) ? validation.errors : []) {
    if (!issue.node) continue
    ;(out[issue.node] ??= []).push({ ...issue, severity: 'error' })
  }
  for (const issue of Array.isArray(validation.warnings) ? validation.warnings : []) {
    if (!issue.node) continue
    ;(out[issue.node] ??= []).push({ ...issue, severity: 'warning' })
  }

  return out
}

export { Plus }
