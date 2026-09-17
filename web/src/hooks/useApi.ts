/**
 * Data fetching for a screen.
 *
 * Three things it exists to get right, all of which are tenant-safety issues
 * rather than conveniences:
 *
 *  1. AN OBSOLETE REQUEST IS CANCELLED. Switching company fires a new request
 *     while the old one is in flight. Without an AbortController the slower
 *     response can land second and paint the PREVIOUS company's calls under the
 *     new company's header.
 *
 *  2. STATE IS CLEARED ON THE WAY IN, NOT ON THE WAY OUT. When a dependency
 *     changes, data resets to null immediately. A screen that keeps showing the
 *     last company's numbers while the next ones load is a screen that leaked
 *     them, however briefly.
 *
 *  3. A LATE RESPONSE FOR A SUPERSEDED REQUEST IS DISCARDED. The abort handles
 *     most of it; the generation counter handles the rest.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../services/api'

export interface ApiState<T> {
  data: T | null
  error: ApiError | null
  loading: boolean
  /** When this data was received, for the "last updated" line. */
  updatedAt: Date | null
  reload: () => void
}

/**
 * @param fetcher must accept the AbortSignal and pass it to the api call
 * @param deps    anything that changes what should be fetched — always include the company
 */
export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: ReadonlyArray<unknown>,
  options: { enabled?: boolean } = {},
): ApiState<T> {
  const enabled = options.enabled !== false

  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null)
  const [nonce, setNonce] = useState(0)

  // Kept in a ref so changing the callback identity does not refetch — pages
  // define their fetcher inline and it is a new function every render.
  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher

  const generation = useRef(0)

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const mine = ++generation.current
    const controller = new AbortController()

    // Cleared BEFORE the request, not after it resolves.
    setData(null)
    setError(null)
    setLoading(true)

    fetcherRef
      .current(controller.signal)
      .then((result) => {
        if (mine !== generation.current) return
        setData(result)
        setUpdatedAt(new Date())
        setLoading(false)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || mine !== generation.current) return
        setError(err instanceof ApiError ? err : new ApiError(0, 'error', 'Something went wrong loading this.'))
        setLoading(false)
      })

    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, enabled, nonce])

  const reload = useCallback(() => setNonce((n) => n + 1), [])

  return { data, error, loading, updatedAt, reload }
}

/**
 * A mutation with its own in-flight and error state.
 *
 * Deliberately NOT optimistic. In this product a mutation places a phone call
 * or writes into another product, and showing success before the backend
 * confirms is exactly the failure mode the whole architecture is built to
 * avoid.
 */
export function useMutation<TArgs extends unknown[], TResult>(
  run: (...args: TArgs) => Promise<TResult>,
): {
  mutate: (...args: TArgs) => Promise<TResult | null>
  pending: boolean
  error: ApiError | null
  reset: () => void
} {
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const mounted = useRef(true)

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const runRef = useRef(run)
  runRef.current = run

  const mutate = useCallback(async (...args: TArgs): Promise<TResult | null> => {
    setPending(true)
    setError(null)
    try {
      const result = await runRef.current(...args)
      if (mounted.current) setPending(false)
      return result
    } catch (err) {
      const apiError = err instanceof ApiError ? err : new ApiError(0, 'error', 'That could not be done.')
      if (mounted.current) {
        setError(apiError)
        setPending(false)
      }
      return null
    }
  }, [])

  const reset = useCallback(() => setError(null), [])

  return { mutate, pending, error, reset }
}

/**
 * Poll while the tab is visible.
 *
 * The visibility check is not a nicety: a dashboard left open on a second
 * monitor overnight would otherwise make 8,000 requests nobody reads, and on a
 * laptop it keeps the radio awake for the same reason.
 */
export function usePolling(reload: () => void, intervalMs: number, enabled = true): void {
  useEffect(() => {
    if (!enabled || intervalMs <= 0) return

    let timer: number | undefined

    const tick = () => {
      if (document.visibilityState === 'visible') reload()
    }

    const start = () => {
      window.clearInterval(timer)
      timer = window.setInterval(tick, intervalMs)
    }

    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        // Catch up immediately on return, then resume the cadence.
        reload()
        start()
      } else {
        window.clearInterval(timer)
      }
    }

    start()
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [reload, intervalMs, enabled])
}
