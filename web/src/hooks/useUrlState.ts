/**
 * Filters that live in the URL.
 *
 * So a filtered screen can be linked to — which is what makes the Command
 * Centre's "12 unanswered calls have no callback" one click from those twelve
 * calls rather than a number to go hunting for.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

export function useUrlState<T extends Record<string, string>>(
  defaults: T,
): [T, (next: Partial<T>) => void, () => void] {
  const [params, setParams] = useSearchParams()

  const value = useMemo(() => {
    const out = { ...defaults }
    for (const key of Object.keys(defaults) as Array<keyof T>) {
      const fromUrl = params.get(String(key))
      if (fromUrl !== null) out[key] = fromUrl as T[keyof T]
    }
    return out
  }, [params, defaults])

  const update = useCallback(
    (next: Partial<T>) => {
      setParams(
        (current) => {
          const merged = new URLSearchParams(current)
          for (const [key, entry] of Object.entries(next)) {
            // A value equal to the default is absent from the URL rather than
            // written out — otherwise every link carries a dozen no-op params.
            if (entry === undefined || entry === '' || entry === defaults[key as keyof T]) {
              merged.delete(key)
            } else {
              merged.set(key, String(entry))
            }
          }
          // Any filter change returns to the first page; page 4 of a different
          // filter is somebody else's page 4.
          if (!('page' in next)) merged.delete('page')
          return merged
        },
        { replace: true },
      )
    },
    [setParams, defaults],
  )

  const clear = useCallback(() => setParams(new URLSearchParams(), { replace: true }), [setParams])

  return [value, update, clear]
}
