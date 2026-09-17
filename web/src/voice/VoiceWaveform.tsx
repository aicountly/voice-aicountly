/**
 * A waveform.
 *
 * ## It never implies we are listening to a call we are not
 *
 * Three modes, and the component REFUSES to draw the live one without data:
 *
 *   live       — driven by real amplitude values the caller passes in, from
 *                audio this browser is already permitted to hear (the agent's
 *                own call, through the gateway). Animates with the audio.
 *   simulated  — clearly captioned as a visualisation. Used on overview cards.
 *                It is decoration and says so.
 *   idle       — flat and grey. No animation at all.
 *
 * A dashboard showing a moving waveform while nobody is on the phone tells the
 * person looking at it that a call is live. On an operations screen that is not
 * a cosmetic mistake.
 *
 * `aria-label` always states which of the three it is.
 */

import { useEffect, useRef, useState } from 'react'

const BAR_COUNT = 48

export function VoiceWaveform({
  mode, amplitudes, caption, height = 96,
}: {
  mode: 'live' | 'simulated' | 'idle'
  /** 0–1 values, newest last. Required for `live`; ignored otherwise. */
  amplitudes?: number[]
  caption?: string
  height?: number
}) {
  const reduced = usePrefersReducedMotion()
  const hidden = useDocumentHidden()

  // A `live` waveform with no data is not live. Falling back to idle rather
  // than animating is the whole point of this component.
  const effective = mode === 'live' && (!amplitudes || amplitudes.length === 0) ? 'idle' : mode

  // Animations are paused in a hidden tab: a background dashboard should not
  // keep a laptop's GPU busy drawing bars nobody can see.
  const animate = effective === 'simulated' && !reduced && !hidden

  const label =
    effective === 'live'
      ? 'Live audio levels for this call'
      : effective === 'simulated'
        ? 'Illustrative waveform — not live audio'
        : 'No audio'

  const bars = Array.from({ length: BAR_COUNT }, (_, index) => {
    if (effective === 'live') {
      const source = amplitudes ?? []
      const value = source[Math.max(0, source.length - BAR_COUNT + index)] ?? 0
      return Math.max(3, Math.min(1, value) * (height - 16))
    }
    if (effective === 'simulated') {
      return 14 + Math.abs(Math.sin(index * 0.63)) * (height - 42)
    }
    return 3
  })

  return (
    <div>
      <div
        className={[
          'vwave',
          effective === 'idle' ? 'vwave--idle' : '',
          animate ? 'vwave--simulated' : '',
        ].filter(Boolean).join(' ')}
        style={{ minHeight: height }}
        role="img"
        aria-label={label}
      >
        {bars.map((barHeight, index) => (
          <span
            key={index}
            className="vwave__bar"
            style={{
              height: `${barHeight}px`,
              animationDelay: animate ? `${index * -0.04}s` : undefined,
            }}
          />
        ))}
      </div>
      {caption ? <p className="vwave__caption">{caption}</p> : null}
      {effective === 'simulated' && !caption ? (
        <p className="vwave__caption">Illustrative visualisation — not live audio.</p>
      ) : null}
    </div>
  )
}

function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState(
    () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches,
  )

  useEffect(() => {
    const query = window.matchMedia?.('(prefers-reduced-motion: reduce)')
    if (!query) return
    const onChange = () => setReduced(query.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  return reduced
}

function useDocumentHidden(): boolean {
  const [hidden, setHidden] = useState(() => typeof document !== 'undefined' && document.hidden)

  useEffect(() => {
    const onChange = () => setHidden(document.hidden)
    document.addEventListener('visibilitychange', onChange)
    return () => document.removeEventListener('visibilitychange', onChange)
  }, [])

  return hidden
}

/**
 * Amplitudes from a MediaStream the user has already granted.
 *
 * Only ever called with a stream the agent's own console obtained after an
 * explicit permission prompt. There is no path here that opens a microphone on
 * its own, and nothing streams audio anywhere — the analyser runs locally and
 * produces numbers for the bars above.
 */
export function useAudioLevels(stream: MediaStream | null): number[] {
  const [levels, setLevels] = useState<number[]>([])
  const frame = useRef<number | undefined>(undefined)

  useEffect(() => {
    if (!stream) {
      setLevels([])
      return
    }

    let context: AudioContext | null = null
    let analyser: AnalyserNode | null = null

    try {
      context = new AudioContext()
      analyser = context.createAnalyser()
      analyser.fftSize = 256
      context.createMediaStreamSource(stream).connect(analyser)
    } catch {
      // No Web Audio in this browser. The waveform falls back to idle, which
      // is honest, rather than animating something invented.
      return
    }

    const data = new Uint8Array(analyser.frequencyBinCount)

    const tick = () => {
      if (!analyser) return
      analyser.getByteTimeDomainData(data)

      let peak = 0
      for (const sample of data) {
        peak = Math.max(peak, Math.abs(sample - 128) / 128)
      }

      setLevels((current) => {
        const next = [...current, peak]
        return next.length > BAR_COUNT ? next.slice(next.length - BAR_COUNT) : next
      })

      frame.current = requestAnimationFrame(tick)
    }

    frame.current = requestAnimationFrame(tick)

    return () => {
      if (frame.current) cancelAnimationFrame(frame.current)
      void context?.close()
    }
  }, [stream])

  return levels
}
