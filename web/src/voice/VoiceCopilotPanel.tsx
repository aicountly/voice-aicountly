/**
 * The in-call AI Copilot.
 *
 * ## Four states, and the gap between them is the point
 *
 *   suggested  — the model proposed something. Nothing has happened.
 *   prepared   — we fetched live data to act on. Still nothing has happened.
 *   submitted  — the request is with the owning product. Outcome unknown.
 *   confirmed  — the owning product acknowledged it. NOW it has happened.
 *
 * Only `confirmed` may be worded as done. An agent reading "Booked for Friday"
 * off this panel is about to say it out loud to a customer, so the panel says
 * "Booked for Friday" only once Calendar has said so.
 *
 * ## Retrieved information carries its source and its age
 *
 * "Friday 11:30 is free" is useless without "per Calendar, 4 seconds ago". The
 * Copilot re-reads before it offers a slot and again before it books one,
 * because an availability read is stale almost immediately.
 */

import { Calendar, CheckCircle2, Clock, Copy, Sparkles, User } from 'lucide-react'

import { Badge, Button, Notice, timeAgo } from '../ui'

export type CopilotActionState = 'suggested' | 'prepared' | 'submitted' | 'confirmed' | 'failed'

export interface CopilotIntent {
  label: string
  /** null when the model reported none. Not "certain". */
  confidence: number | null
}

export interface CopilotFact {
  label: string
  value: string
  source: string
  retrievedAt: string
}

export interface CopilotAction {
  key: string
  label: string
  state: CopilotActionState
  /** Whether this product will require the caller to confirm before submitting. */
  requiresCallerConfirmation: boolean
  message?: string | null
  externalRef?: string | null
}

export function VoiceCopilotPanel({
  available, unavailableReason, intent, facts, suggestion, action, onPrepare, onSubmit, pending,
}: {
  available: boolean
  unavailableReason?: string | null
  intent: CopilotIntent | null
  facts: CopilotFact[]
  suggestion: string | null
  action: CopilotAction | null
  onPrepare?: () => void
  onSubmit?: () => void
  pending?: boolean
}) {
  if (!available) {
    return (
      <div className="vstack vstack--tight">
        <Notice tone="info" title="AI Copilot is not configured">
          {unavailableReason ?? 'No AI provider is configured for this deployment, so there are no suggestions.'}
        </Notice>
      </div>
    )
  }

  return (
    <div className="vstack vstack--tight">
      {intent ? (
        <div className="vsoft">
          <div className="vspread">
            <h3 style={{ margin: 0 }}>
              <Sparkles size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
              Suggested intent
            </h3>
            {/* A percentage is shown only where the model reported one. */}
            {intent.confidence !== null ? (
              <Badge tone="neutral">{Math.round(intent.confidence * 100)}% confidence</Badge>
            ) : (
              <Badge tone="neutral">Confidence not reported</Badge>
            )}
          </div>
          <p style={{ margin: '6px 0 0', fontWeight: 650 }}>{intent.label}</p>
          <p className="vmuted vsmall" style={{ margin: '4px 0 0' }}>
            A suggestion. Confirm what the caller wants before acting on it.
          </p>
        </div>
      ) : null}

      {facts.map((fact) => (
        <div className="vsoft" key={fact.label}>
          <div className="vspread">
            <h3 style={{ margin: 0 }}>
              <Calendar size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
              {fact.label}
            </h3>
            <span className="vmuted vsmall">
              <Clock size={11} aria-hidden="true" style={{ verticalAlign: -1, marginRight: 3 }} />
              {timeAgo(fact.retrievedAt)}
            </span>
          </div>
          <p style={{ margin: '6px 0 0', fontWeight: 650 }}>{fact.value}</p>
          <p className="vmuted vsmall" style={{ margin: '4px 0 0' }}>
            Read live from {fact.source}. Re-checked before anything is booked.
          </p>
        </div>
      ))}

      {suggestion ? (
        <div className="vsoft">
          <div className="vspread">
            <h3 style={{ margin: 0 }}>Suggested reply</h3>
            <Button
              size="sm"
              variant="ghost"
              icon={Copy}
              onClick={() => void navigator.clipboard?.writeText(suggestion)}
              title="Copy"
              aria-label="Copy the suggested reply"
            />
          </div>
          <p style={{ margin: '6px 0 0', fontSize: 13 }}>{suggestion}</p>
        </div>
      ) : null}

      {action ? <ActionStrip action={action} onPrepare={onPrepare} onSubmit={onSubmit} pending={pending} /> : null}
    </div>
  )
}

function ActionStrip({ action, onPrepare, onSubmit, pending }: {
  action: CopilotAction
  onPrepare?: () => void
  onSubmit?: () => void
  pending?: boolean
}) {
  if (action.state === 'confirmed') {
    return (
      <Notice tone="info" title={`${action.label} — confirmed`}>
        <p>
          {action.message ?? 'The owning product has confirmed this.'}
          {action.externalRef ? ` Reference ${action.externalRef}.` : ''}
        </p>
      </Notice>
    )
  }

  if (action.state === 'submitted') {
    // The case that must never read as success.
    return (
      <Notice tone="warning" title={`${action.label} — not confirmed yet`}>
        <p>
          {action.message ?? 'This has been sent but not acknowledged. Do not tell the caller it is done, and do not send it again.'}
        </p>
      </Notice>
    )
  }

  if (action.state === 'failed') {
    return (
      <Notice tone="danger" title={`${action.label} could not be done`}>
        <p>{action.message ?? 'The owning product declined this. Nothing was created.'}</p>
      </Notice>
    )
  }

  if (action.state === 'prepared') {
    return (
      <div className="vsoft">
        <h3>
          <CheckCircle2 size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
          Ready to submit
        </h3>
        <p className="vmuted vsmall" style={{ margin: '4px 0 0' }}>
          {action.requiresCallerConfirmation
            ? 'Read the details back to the caller and get their agreement before submitting.'
            : 'Nothing has been created yet.'}
        </p>
        <div className="vactions">
          <Button variant="primary" onClick={onSubmit} disabled={pending}>
            {action.requiresCallerConfirmation ? 'Caller agreed — submit' : 'Submit'}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="vactions">
      <Button variant="primary" icon={User} onClick={onPrepare} disabled={pending}>
        Prepare {action.label.toLowerCase()}
      </Button>
      <span className="vmuted vsmall" style={{ alignSelf: 'center' }}>
        Nothing is created until you submit.
      </span>
    </div>
  )
}
