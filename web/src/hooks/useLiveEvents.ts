/**
 * The live event stream.
 *
 * Subscribes to the backend's SSE endpoint, which filters by tenant and
 * permission in SQL — the browser never receives another company's events and
 * so never has to filter them.
 *
 * What this hook handles:
 *
 *  - RECONNECTION with backoff, and `Last-Event-ID` so a short gap resumes
 *    rather than restarting.
 *  - A `resync` event, which means the gap was too long to fill incrementally.
 *    The hook does NOT try to patch up a partial history; it tells the screen to
 *    re-read authoritative state over REST.
 *  - STALENESS. If the stream is down, `connection` says so and the screen can
 *    show its data as possibly out of date rather than silently frozen.
 *  - A BOUNDED buffer, so a screen left open does not grow a list of every
 *    event since this morning.
 *
 * With `REALTIME` off, or the stream unreachable, the caller falls back to
 * visible-screen polling. That is polling of the API — never a second copy of
 * anything.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { eventStreamUrl } from '../services/api'

export type LiveConnection = 'connecting' | 'live' | 'reconnecting' | 'offline' | 'disabled'

export interface LiveEvent {
  type: string
  id: number
  data: Record<string, unknown>
}

interface Options {
  enabled?: boolean
  /** Called for every event. Keep it cheap — it runs on the stream. */
  onEvent?: (event: LiveEvent) => void
  /** Called when the stream says too much has changed to catch up. */
  onResync?: () => void
}

const MAX_BUFFER = 200
const BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000]

export function useLiveEvents(options: Options = {}): {
  connection: LiveConnection
  lastEventAt: Date | null
  events: LiveEvent[]
} {
  const { enabled = true, onEvent, onResync } = options

  const [connection, setConnection] = useState<LiveConnection>(enabled ? 'connecting' : 'disabled')
  const [lastEventAt, setLastEventAt] = useState<Date | null>(null)
  const [events, setEvents] = useState<LiveEvent[]>([])

  const lastEventId = useRef(0)
  const attempt = useRef(0)
  const source = useRef<EventSource | null>(null)
  const retryTimer = useRef<number | undefined>(undefined)

  // Held in refs so a caller redefining its handlers inline does not tear the
  // stream down and reopen it on every render.
  const onEventRef = useRef(onEvent)
  onEventRef.current = onEvent
  const onResyncRef = useRef(onResync)
  onResyncRef.current = onResync

  const push = useCallback((event: LiveEvent) => {
    lastEventId.current = Math.max(lastEventId.current, event.id)
    setLastEventAt(new Date())
    setEvents((current) => {
      const next = [...current, event]
      return next.length > MAX_BUFFER ? next.slice(next.length - MAX_BUFFER) : next
    })
    onEventRef.current?.(event)
  }, [])

  useEffect(() => {
    if (!enabled) {
      setConnection('disabled')
      return
    }

    let cancelled = false

    const connect = async () => {
      if (cancelled) return

      let url: string
      try {
        url = await eventStreamUrl(lastEventId.current || undefined)
      } catch {
        setConnection('offline')
        return
      }
      if (cancelled) return

      const stream = new EventSource(url)
      source.current = stream

      stream.onopen = () => {
        if (cancelled) return
        attempt.current = 0
        setConnection('live')
      }

      const handle = (type: string) => (raw: MessageEvent) => {
        if (cancelled) return
        let data: Record<string, unknown> = {}
        try {
          data = JSON.parse(raw.data) as Record<string, unknown>
        } catch {
          return
        }
        const id = Number(raw.lastEventId || 0)

        if (type === 'resync') {
          // Too much happened to catch up event by event. Re-read rather than
          // stitching together a history that would be subtly wrong.
          lastEventId.current = id
          onResyncRef.current?.()
          return
        }
        if (type === 'forbidden') {
          setConnection('offline')
          stream.close()
          return
        }
        if (type === 'reconnect') {
          // A clean close at the end of the server's window, not a failure.
          stream.close()
          attempt.current = 0
          window.setTimeout(connect, 200)
          return
        }
        if (type === 'ready') {
          setConnection('live')
          return
        }

        push({ type, id, data })
      }

      for (const type of [
        'call.updated', 'queue.updated', 'agent.presence.updated',
        'transcript.segment.updated', 'handover.updated',
        'campaign.updated', 'integration.health.updated',
        'ready', 'resync', 'reconnect', 'forbidden',
      ]) {
        stream.addEventListener(type, handle(type) as EventListener)
      }

      stream.onerror = () => {
        if (cancelled) return
        stream.close()
        source.current = null
        setConnection('reconnecting')

        const delay = BACKOFF_MS[Math.min(attempt.current, BACKOFF_MS.length - 1)]
        attempt.current += 1
        retryTimer.current = window.setTimeout(connect, delay)
      }
    }

    void connect()

    return () => {
      cancelled = true
      window.clearTimeout(retryTimer.current)
      source.current?.close()
      source.current = null
    }
  }, [enabled, push])

  // A stream that has been quiet for a long time is reported as stale, so a
  // screen can say its numbers may be out of date rather than pretending.
  useEffect(() => {
    if (connection !== 'live') return
    const timer = window.setInterval(() => {
      if (lastEventAt && Date.now() - lastEventAt.getTime() > 180000) {
        setConnection('reconnecting')
      }
    }, 30000)
    return () => window.clearInterval(timer)
  }, [connection, lastEventAt])

  return { connection, lastEventAt, events }
}
