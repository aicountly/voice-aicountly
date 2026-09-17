/**
 * In-call controls.
 *
 * ## A control exists only when it works
 *
 * The backend sends `controls.available` — the intersection of what the
 * provider can do and what this user may do — plus a reason per missing
 * control. A button that is absent because the carrier has no hold operation
 * and one absent because you lack the permission are different problems, and
 * the tooltip says which.
 *
 * There is no hardcoded button list here. A provider that gains attended
 * transfer tomorrow gets the control with no change to this file.
 *
 * ## Mute is not hold
 *
 * Two buttons, two backend actions, two provider capabilities. Mute stops the
 * agent's microphone; hold parks the caller and usually plays music to them.
 * Anyone who has been on the wrong end of the two being conflated knows why.
 */

import { useState } from 'react'
import {
  Grid3x3, Mic, MicOff, Pause, PhoneForwarded, PhoneOff, Play, Radio, Circle,
} from 'lucide-react'

import { Badge, Button, Notice } from '../ui'
import type { CallControls, CallState } from '../services/types'

const DTMF_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#']

export function VoiceCallControls({
  controls, state, muted, held, recording, pending, onAction,
}: {
  controls: CallControls
  state: CallState
  muted: boolean
  held: boolean
  recording: boolean
  pending: string | null
  onAction: (action: string, payload?: Record<string, unknown>) => void
}) {
  const [showKeypad, setShowKeypad] = useState(false)
  const [transferTo, setTransferTo] = useState('')
  const [showTransfer, setShowTransfer] = useState(false)

  const has = (control: string) => controls.available.includes(control)
  const why = (control: string) => {
    const reason = controls.reasons[control]
    if (reason === 'provider_unsupported') return `${controls.provider} does not support this`
    if (reason === 'permission_required') return 'You do not have permission for this'
    if (reason === 'disabled_by_policy') return 'Switched off for this company'
    return undefined
  }

  // A call that has ended, or whose state we cannot assert, takes no controls.
  const ended = ['completed', 'busy', 'unanswered', 'cancelled', 'failed'].includes(state)
  const unknown = state === 'unknown'
  const disabled = ended || unknown || pending !== null

  const missing = Object.keys(controls.reasons).filter((control) => controls.reasons[control] === 'provider_unsupported')

  return (
    <div className="vstack vstack--tight">
      {unknown ? (
        <Notice tone="warning" title="This call’s state is unknown">
          The provider has stopped reporting on it. It may still be connected. Controls are disabled
          until we hear from the provider again.
        </Notice>
      ) : null}

      <div className="vsplit" style={{ justifyContent: 'center' }} role="group" aria-label="Call controls">
        {has('mute') ? (
          <Button
            icon={muted ? MicOff : Mic}
            onClick={() => onAction(muted ? 'unmute' : 'mute')}
            disabled={disabled}
            variant={muted ? 'danger' : 'default'}
            title={muted ? 'Unmute your microphone' : 'Mute your microphone'}
          >
            {muted ? 'Unmute' : 'Mute'}
          </Button>
        ) : (
          <Button icon={Mic} disabled title={why('mute')}>Mute</Button>
        )}

        {has('hold') ? (
          <Button
            icon={held ? Play : Pause}
            onClick={() => onAction(held ? 'resume' : 'hold')}
            disabled={disabled}
            variant={held ? 'danger' : 'default'}
            title={held ? 'Take the caller off hold' : 'Put the caller on hold'}
          >
            {held ? 'Resume' : 'Hold'}
          </Button>
        ) : (
          <Button icon={Pause} disabled title={why('hold')}>Hold</Button>
        )}

        {has('dtmf') ? (
          <Button
            icon={Grid3x3}
            onClick={() => setShowKeypad((open) => !open)}
            disabled={disabled}
            aria-expanded={showKeypad}
          >
            Keypad
          </Button>
        ) : null}

        {has('transfer') ? (
          <Button
            icon={PhoneForwarded}
            onClick={() => setShowTransfer((open) => !open)}
            disabled={disabled}
            aria-expanded={showTransfer}
          >
            Transfer
          </Button>
        ) : (
          <Button icon={PhoneForwarded} disabled title={why('transfer')}>Transfer</Button>
        )}

        {has('record') ? (
          <Button
            icon={Circle}
            onClick={() => onAction(recording ? 'record_stop' : 'record_start')}
            disabled={disabled}
            variant={recording ? 'danger' : 'default'}
          >
            {recording ? 'Stop recording' : 'Record'}
          </Button>
        ) : null}

        {has('monitor') ? (
          <Button icon={Radio} onClick={() => onAction('monitor', { mode: 'listen' })} disabled={disabled}>
            Listen in
          </Button>
        ) : null}

        <Button
          icon={PhoneOff}
          variant="danger"
          onClick={() => onAction('hangup')}
          disabled={disabled}
        >
          End call
        </Button>
      </div>

      {showKeypad && has('dtmf') ? (
        <div className="vsoft">
          <h3>Keypad</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 6, maxWidth: 220, margin: '0 auto' }}>
            {DTMF_KEYS.map((key) => (
              <Button
                key={key}
                size="sm"
                onClick={() => onAction('dtmf', { digits: key })}
                disabled={disabled}
                aria-label={`Send ${key}`}
              >
                {key}
              </Button>
            ))}
          </div>
        </div>
      ) : null}

      {showTransfer && has('transfer') ? (
        <div className="vsoft">
          <h3>Transfer this call</h3>
          <div className="vsplit">
            <input
              className="vinput"
              value={transferTo}
              onChange={(event) => setTransferTo(event.target.value)}
              placeholder="Extension, queue or number"
              aria-label="Transfer destination"
              style={{ flex: 1, minWidth: 180 }}
            />
            <Button
              variant="primary"
              disabled={disabled || transferTo.trim() === ''}
              onClick={() => onAction('transfer', { destination: transferTo.trim(), mode: 'blind' })}
            >
              Transfer
            </Button>
          </div>
          <p className="vmuted vsmall" style={{ marginTop: 8, marginBottom: 0 }}>
            {has('attended_transfer')
              ? 'The caller is transferred immediately.'
              : `${controls.provider} supports immediate transfers only — the caller is handed over without an introduction first.`}
          </p>
        </div>
      ) : null}

      {missing.length > 0 ? (
        <p className="vmuted vsmall" style={{ textAlign: 'center', margin: 0 }}>
          {controls.provider} does not support: {missing.map((c) => c.replace(/_/g, ' ')).join(', ')}.
        </p>
      ) : null}

      {pending ? (
        <p className="vmuted vsmall" style={{ textAlign: 'center', margin: 0 }} role="status">
          Sending “{pending}” to {controls.provider}…
        </p>
      ) : null}
    </div>
  )
}

/**
 * Browser calling readiness.
 *
 * Microphone permission is requested by the USER pressing a button, never on
 * page load. A product that asks for the microphone the moment somebody opens a
 * dashboard is a product people deny permanently.
 */
export function BrowserCallingStatus({
  supported, reason, deviceLabel, onEnable, state,
}: {
  supported: boolean
  reason?: string | null
  deviceLabel?: string | null
  onEnable: () => void
  state: 'idle' | 'requesting' | 'ready' | 'denied' | 'unsupported'
}) {
  if (!supported) {
    return (
      <Notice tone="warning" title="Browser calling is not available">
        {reason ?? 'This connection does not support calling from the browser. Use a desk phone or a SIP device.'}
      </Notice>
    )
  }

  if (state === 'unsupported') {
    return (
      <Notice tone="warning" title="This browser cannot make calls">
        Browser calling needs WebRTC and a microphone. Try a current version of Chrome, Edge, Firefox or Safari.
      </Notice>
    )
  }

  if (state === 'denied') {
    return (
      <Notice tone="danger" title="Microphone access was declined">
        Calling from the browser needs the microphone. Allow it in your browser’s site settings, then try again.
      </Notice>
    )
  }

  if (state === 'ready') {
    return (
      <div className="vsplit">
        <Badge>Ready to call</Badge>
        <span className="vmuted vsmall">{deviceLabel ?? 'Default microphone'}</span>
      </div>
    )
  }

  return (
    <div className="vsplit">
      <Button variant="primary" icon={Mic} onClick={onEnable} disabled={state === 'requesting'}>
        {state === 'requesting' ? 'Waiting for permission…' : 'Enable browser calling'}
      </Button>
      <span className="vmuted vsmall">Your browser will ask for the microphone.</span>
    </div>
  )
}
