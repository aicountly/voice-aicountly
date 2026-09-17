/**
 * Placing an outbound call.
 *
 * ## The unknown outcome is the whole reason this is its own component
 *
 * Three answers are possible and they must look completely different:
 *
 *   success — the provider accepted it. The drawer closes.
 *   refused — suppressed, out of hours, no budget, no provider. The reason is
 *             shown and there is NO retry button, because retrying will fail
 *             identically.
 *   unknown — the provider did not confirm. It may be ringing. The drawer shows
 *             a warning and REMOVES the Call button, because the one thing
 *             nobody should do here is press it again.
 */

import { useCallback, useState } from 'react'
import { PhoneCall } from 'lucide-react'

import { useMutation } from '../hooks/useApi'
import { api } from '../services/api'
import type { Call } from '../services/types'
import { Button, Drawer, Field, Notice } from '../ui'

export function ComposeCall({ onClose, onPlaced }: { onClose: () => void; onPlaced: () => void }) {
  const [number, setNumber] = useState('')
  const [unknownOutcome, setUnknownOutcome] = useState<string | null>(null)

  const place = useMutation((to: string) => api.post<{ data: Call }>('v1/calls', { to }))

  const onCall = useCallback(async () => {
    setUnknownOutcome(null)
    const result = await place.mutate(number.trim())

    if (result) {
      onPlaced()
      onClose()
      return
    }

    // A call whose outcome nobody knows. Not a failure, and not something to
    // try again.
    if (place.error?.isUnknownOutcome) {
      setUnknownOutcome(place.error.message)
    }
  }, [number, place, onPlaced, onClose])

  const blocked = unknownOutcome !== null

  return (
    <Drawer
      title="Make a call"
      subtitle="The call is placed through your configured provider."
      onClose={onClose}
      footer={
        blocked ? (
          <Button onClick={onClose}>Close</Button>
        ) : (
          <>
            <Button
              variant="primary"
              icon={PhoneCall}
              onClick={() => void onCall()}
              disabled={place.pending || number.trim().length < 8}
            >
              {place.pending ? 'Dialling…' : 'Call'}
            </Button>
            <Button onClick={onClose}>Cancel</Button>
          </>
        )
      }
    >
      <div className="vstack vstack--tight">
        <Field
          label="Number"
          hint="In international form, for example +919876543210."
          error={place.error && !place.error.isUnknownOutcome ? undefined : undefined}
        >
          <input
            value={number}
            onChange={(event) => setNumber(event.target.value)}
            placeholder="+91…"
            inputMode="tel"
            autoFocus
            disabled={blocked}
          />
        </Field>

        {unknownOutcome ? (
          <Notice tone="warning" title="The outcome of this call is not confirmed">
            <p>
              {unknownOutcome} It may be ringing. Check the call list before dialling again — pressing Call a second
              time could ring this person twice.
            </p>
          </Notice>
        ) : null}

        {place.error && !place.error.isUnknownOutcome ? (
          <Notice tone={place.error.isProviderMissing ? 'warning' : 'danger'} title="That call was not placed">
            <p>{place.error.message}</p>
            {place.error.retryable ? null : (
              <p className="vmuted vsmall" style={{ marginTop: 6 }}>
                Trying again will not change this.
              </p>
            )}
          </Notice>
        ) : null}
      </div>
    </Drawer>
  )
}
