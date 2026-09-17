/**
 * The handover brief.
 *
 * What the next person needs before they say hello, and — just as important —
 * what they must NOT assume.
 *
 * ## Verified is not the same as heard
 *
 * "Existing customer" is a verified fact only if Contacts confirmed it on this
 * call. "The caller said they are an existing customer" is a different claim
 * and appears under a different heading. Merging them is how somebody gets
 * treated as a customer because they said they were one.
 *
 * ## Accepted means accepted
 *
 * The brief does not say the next agent has it until that agent acknowledges.
 * Until then it is `offered`, and the fallback — a queue or voicemail — is
 * shown, because a handover to nobody is a dropped call.
 */

import { AlertTriangle, ArrowRightLeft, CheckCircle2, Clock, Quote } from 'lucide-react'

import { Badge, Button, Drawer, Notice, timeAgo } from '../ui'

export interface HandoverBrief {
  status: 'draft' | 'offered' | 'accepted' | 'declined' | 'timed_out'
  reason: string
  summary: string
  /** Confirmed against a product's live API during this call. */
  verifiedFacts: Array<{ label: string; value: string; source: string }>
  /** What the caller said. Unverified, and labelled as such. */
  callerStated: string[]
  /** Anything the caller has been promised so far on this call. */
  pendingPromises: string[]
  externalRefs: Array<{ system: string; ref: string; label: string }>
  suggestedNextStep: string
  offeredTo: string | null
  offeredAt: string | null
  fallback: string | null
}

export function VoiceHandoverDrawer({ brief, onClose, onAccept, onDecline, pending, canAccept }: {
  brief: HandoverBrief
  onClose: () => void
  onAccept?: () => void
  onDecline?: () => void
  pending?: boolean
  canAccept?: boolean
}) {
  return (
    <Drawer
      title="Handover brief"
      subtitle={brief.reason}
      onClose={onClose}
      footer={
        canAccept && brief.status === 'offered' ? (
          <>
            <Button variant="primary" icon={ArrowRightLeft} onClick={onAccept} disabled={pending}>
              Accept handover
            </Button>
            <Button onClick={onDecline} disabled={pending}>Cannot take it</Button>
          </>
        ) : null
      }
    >
      <div className="vstack vstack--tight">
        <StatusNotice brief={brief} />

        <section>
          <h3>What happened</h3>
          <p style={{ fontSize: 13, margin: 0 }}>{brief.summary}</p>
        </section>

        {brief.verifiedFacts.length > 0 ? (
          <section>
            <h3>
              <CheckCircle2 size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5, color: 'var(--primary)' }} />
              Verified on this call
            </h3>
            <dl className="vdl">
              {brief.verifiedFacts.map((fact) => (
                <div key={fact.label} style={{ display: 'contents' }}>
                  <dt>{fact.label}</dt>
                  <dd>
                    {fact.value}
                    <span className="vmuted vsmall" style={{ display: 'block', fontWeight: 400 }}>
                      per {fact.source}
                    </span>
                  </dd>
                </div>
              ))}
            </dl>
          </section>
        ) : null}

        {brief.callerStated.length > 0 ? (
          <section>
            <h3>
              <Quote size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5 }} />
              What the caller said
            </h3>
            {/* Deliberately separate from the verified list above. */}
            <p className="vmuted vsmall" style={{ marginTop: 0 }}>
              Not confirmed against any record.
            </p>
            <ul style={{ fontSize: 13, margin: 0, paddingLeft: 18 }}>
              {brief.callerStated.map((line) => <li key={line}>{line}</li>)}
            </ul>
          </section>
        ) : null}

        {brief.pendingPromises.length > 0 ? (
          <section>
            <h3>
              <AlertTriangle size={14} aria-hidden="true" style={{ verticalAlign: -2, marginRight: 5, color: 'var(--warning)' }} />
              Already promised to the caller
            </h3>
            <ul style={{ fontSize: 13, margin: 0, paddingLeft: 18 }}>
              {brief.pendingPromises.map((line) => <li key={line}>{line}</li>)}
            </ul>
          </section>
        ) : null}

        {brief.externalRefs.length > 0 ? (
          <section>
            <h3>Related records</h3>
            <div className="vchips">
              {brief.externalRefs.map((ref) => (
                <span className="vchip" key={`${ref.system}-${ref.ref}`}>
                  {ref.label} · {ref.system}
                </span>
              ))}
            </div>
            <p className="vmuted vsmall" style={{ margin: '8px 0 0' }}>
              References only — open them in the product that owns them for the current details.
            </p>
          </section>
        ) : null}

        <section>
          <h3>Suggested next step</h3>
          <p className="vquote">{brief.suggestedNextStep}</p>
        </section>
      </div>
    </Drawer>
  )
}

function StatusNotice({ brief }: { brief: HandoverBrief }) {
  if (brief.status === 'accepted') {
    return (
      <Notice tone="info" title="Accepted">
        <p>{brief.offeredTo} has the call.</p>
      </Notice>
    )
  }

  if (brief.status === 'offered') {
    return (
      <Notice tone="warning" title="Offered — not accepted yet">
        <p>
          Offered to {brief.offeredTo ?? 'the queue'} {brief.offeredAt ? timeAgo(brief.offeredAt) : ''}. The caller is
          still with you until somebody accepts.
          {brief.fallback ? ` If nobody does, the call goes to ${brief.fallback}.` : ''}
        </p>
      </Notice>
    )
  }

  if (brief.status === 'declined' || brief.status === 'timed_out') {
    return (
      <Notice tone="danger" title={brief.status === 'declined' ? 'Declined' : 'Nobody answered'}>
        <p>
          {brief.fallback
            ? `The call will go to ${brief.fallback}.`
            : 'There is no fallback configured for this handover, so the caller has nowhere to go.'}
        </p>
      </Notice>
    )
  }

  return (
    <div className="vsplit">
      <Badge tone="neutral">
        <Clock size={11} aria-hidden="true" />
        Draft — not sent
      </Badge>
    </div>
  )
}

/** The after-call work panel. */
export function VoiceWrapUp({
  summary, onSummaryChange, dispositions, disposition, onDispositionChange, note, onNoteChange,
  commitments, onCreateCallback, onSave, pending, secondsRemaining, error,
}: {
  summary: string
  onSummaryChange: (value: string) => void
  dispositions: Array<{ code: string; label: string; requires_note: boolean }>
  disposition: string
  onDispositionChange: (value: string) => void
  note: string
  onNoteChange: (value: string) => void
  commitments: Array<{ description: string; evidence: string }>
  onCreateCallback?: () => void
  onSave: () => void
  pending?: boolean
  secondsRemaining?: number | null
  error?: string | null
}) {
  const chosen = dispositions.find((entry) => entry.code === disposition)
  const noteRequired = chosen?.requires_note === true && note.trim() === ''

  return (
    <div className="vstack vstack--tight">
      {secondsRemaining !== null && secondsRemaining !== undefined ? (
        <div className="vspread">
          <span className="vmuted vsmall">Wrap-up time remaining</span>
          <Badge tone={secondsRemaining < 10 ? 'amber' : 'neutral'}>{secondsRemaining}s</Badge>
        </div>
      ) : null}

      <label className="vfield">
        Outcome
        <select className="vinput" value={disposition} onChange={(event) => onDispositionChange(event.target.value)}>
          <option value="">Choose an outcome…</option>
          {dispositions.map((entry) => (
            <option key={entry.code} value={entry.code}>{entry.label}</option>
          ))}
        </select>
      </label>

      <label className="vfield">
        Note
        {chosen?.requires_note ? <span className="vfield__hint">Required for this outcome.</span> : null}
        <textarea value={note} onChange={(event) => onNoteChange(event.target.value)} rows={2} />
      </label>

      <label className="vfield">
        Summary
        <span className="vfield__hint">
          Edit freely — what you write is kept alongside anything that was generated.
        </span>
        <textarea value={summary} onChange={(event) => onSummaryChange(event.target.value)} rows={4} />
      </label>

      {commitments.length > 0 ? (
        <div className="vsoft">
          <h3>Commitments found in this call</h3>
          <p className="vmuted vsmall" style={{ marginTop: 0 }}>
            Suggestions with the words they came from. None of these is a task until somebody confirms it.
          </p>
          <ul style={{ fontSize: 13, margin: 0, paddingLeft: 18 }}>
            {commitments.map((commitment) => (
              <li key={commitment.description}>
                {commitment.description}
                <span className="vmuted vsmall" style={{ display: 'block' }}>“{commitment.evidence}”</span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {error ? <Notice tone="danger">{error}</Notice> : null}

      <div className="vactions">
        <Button variant="primary" onClick={onSave} disabled={pending || disposition === '' || noteRequired}>
          Save outcome
        </Button>
        {onCreateCallback ? <Button onClick={onCreateCallback} disabled={pending}>Arrange a callback</Button> : null}
      </div>
    </div>
  )
}
