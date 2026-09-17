/**
 * The live and recorded transcript.
 *
 * Four things it is careful about:
 *
 *  1. PARTIAL TEXT IS MARKED. The recogniser revises; a line it has not
 *     committed to is shown in muted italics and labelled. A UI that renders
 *     partial text as final quotes somebody saying something they did not say.
 *
 *  2. AUTO-SCROLL STOPS WHEN THE USER SCROLLS BACK. Reading what was said two
 *     minutes ago while new lines arrive is the normal case during a call, and
 *     yanking the view to the bottom every second makes it impossible.
 *
 *  3. UNCERTAINTY IS SHOWN. Where the recogniser reported low confidence the
 *     line says so. Where it reported none, nothing is claimed either way.
 *
 *  4. IT DOES NOT ANNOUNCE EVERY TOKEN. A screen reader reading each partial
 *     word as it arrives is unusable. The live region announces the speaker
 *     changing, not the stream.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { ArrowDown, Languages } from 'lucide-react'

import { Button, formatDuration } from '../ui'
import type { TranscriptSegment } from '../services/types'

export function VoiceTranscript({
  segments, live, activeSegmentId, onSeek, showTranslation = true,
}: {
  segments: TranscriptSegment[]
  /** True while the call is in progress — enables the follow-along behaviour. */
  live?: boolean
  activeSegmentId?: number | null
  /** Provided for a recording, so clicking a line seeks the audio to it. */
  onSeek?: (ms: number) => void
  showTranslation?: boolean
}) {
  const container = useRef<HTMLDivElement>(null)
  const [following, setFollowing] = useState(true)
  const [translate, setTranslate] = useState(showTranslation)

  const hasTranslation = segments.some((segment) => segment.translated_text)

  // Stick to the bottom only while the user has not scrolled away.
  const onScroll = useCallback(() => {
    const element = container.current
    if (!element) return
    const distanceFromBottom = element.scrollHeight - element.scrollTop - element.clientHeight
    setFollowing(distanceFromBottom < 48)
  }, [])

  useEffect(() => {
    if (!live || !following) return
    const element = container.current
    if (element) element.scrollTop = element.scrollHeight
  }, [segments.length, live, following])

  // Seeking a recording brings the line into view without hijacking the scroll
  // position the rest of the time.
  useEffect(() => {
    if (live || activeSegmentId == null) return
    const element = container.current?.querySelector(`[data-segment="${activeSegmentId}"]`)
    element?.scrollIntoView({ block: 'nearest' })
  }, [activeSegmentId, live])

  const lastSpeaker = segments.length > 0 ? segments[segments.length - 1].speaker : null

  return (
    <div>
      {hasTranslation ? (
        <div className="vspread" style={{ marginBottom: 10 }}>
          <span className="vmuted vsmall">Original language shown; translation available.</span>
          <Button
            size="sm"
            variant="ghost"
            icon={Languages}
            onClick={() => setTranslate((on) => !on)}
            aria-pressed={translate}
          >
            {translate ? 'Hide translation' : 'Show translation'}
          </Button>
        </div>
      ) : null}

      <div className="vtranscript" ref={container} onScroll={onScroll} tabIndex={0} aria-label="Transcript">
        {segments.length === 0 ? (
          <p className="vmuted vsmall" style={{ margin: 0 }}>
            {live ? 'Waiting for the first words…' : 'No transcript for this call.'}
          </p>
        ) : null}

        {segments.map((segment) => {
          const uncertain = segment.confidence !== null && segment.confidence < 0.6
          const isActive = activeSegmentId === segment.segment_id

          return (
            <div
              key={segment.segment_id}
              data-segment={segment.segment_id}
              className={`vtranscript__segment${isActive ? ' vtranscript__segment--active' : ''}`}
            >
              <div className="vtranscript__meta">
                {onSeek ? (
                  <button
                    className="vbtn vbtn--ghost vbtn--sm"
                    style={{ padding: 0, minHeight: 0, fontVariantNumeric: 'tabular-nums' }}
                    onClick={() => onSeek(segment.started_ms)}
                    aria-label={`Play from ${formatDuration(segment.started_ms / 1000)}`}
                  >
                    {formatDuration(segment.started_ms / 1000)}
                  </button>
                ) : (
                  <span style={{ fontVariantNumeric: 'tabular-nums' }}>
                    {formatDuration(segment.started_ms / 1000)}
                  </span>
                )}
                <strong style={{ color: 'var(--text)' }}>
                  {segment.speaker_label ?? speakerName(segment.speaker)}
                </strong>
                {segment.language ? <span>{segment.language.toUpperCase()}</span> : null}
                {!segment.is_final ? <span>· still being transcribed</span> : null}
                {uncertain ? <span>· unclear audio</span> : null}
              </div>

              <p className={`vtranscript__text${segment.is_final ? '' : ' vtranscript__text--partial'}`}>
                {segment.redacted ? <em>[redacted]</em> : segment.text}
              </p>

              {translate && segment.translated_text ? (
                <p className="vtranscript__translation">
                  {segment.translated_to ? `${segment.translated_to.toUpperCase()}: ` : ''}
                  {segment.translated_text}
                </p>
              ) : null}
            </div>
          )
        })}
      </div>

      {/* Announces who is speaking, not every token that arrives. */}
      <span className="sr-only" role="status" aria-live="polite">
        {live && lastSpeaker ? `${speakerName(lastSpeaker)} is speaking` : ''}
      </span>

      {live && !following ? (
        <div style={{ textAlign: 'center', marginTop: 8 }}>
          <Button
            size="sm"
            icon={ArrowDown}
            onClick={() => {
              setFollowing(true)
              const element = container.current
              if (element) element.scrollTop = element.scrollHeight
            }}
          >
            Jump to the latest
          </Button>
        </div>
      ) : null}
    </div>
  )
}

function speakerName(speaker: string): string {
  return ({ caller: 'Caller', agent: 'Agent', ai: 'AI agent', unknown: 'Unidentified' } as Record<string, string>)[
    speaker
  ] ?? speaker
}
