/**
 * Company scope, permissions and settings for the whole app.
 *
 * ## Switching company must not leak the previous one
 *
 * Two mechanisms, and both are needed:
 *
 *  1. `setScope` in the API client is updated BEFORE anything refetches, so no
 *     request can go out carrying the old company.
 *  2. Every screen is keyed on the company id (see AppShell), so React unmounts
 *     and remounts the tree rather than reusing components that still hold the
 *     previous tenant's rows in state. Clearing state field by field is the
 *     approach that misses one.
 *
 * ## Permissions here are a courtesy
 *
 * `can()` hides links and buttons somebody cannot use. It is not a control —
 * every one of these is asserted again in the backend, which is one curl away
 * from being the only thing that matters.
 */

import {
  createContext, useCallback, useContext, useEffect, useMemo, useRef, useState,
} from 'react'
import type { ReactNode } from 'react'

import { ApiError, api, setScope } from '../services/api'
import type { AccessInfo, VoiceSettings } from '../services/types'

export interface Company {
  cmp_id: number
  name: string
  bo_id: number
  branches?: Array<{ bo_id: number; name: string }>
}

interface VoiceContextValue {
  company: Company | null
  companies: Company[]
  branchId: number
  switchCompany: (cmpId: number) => void
  switchBranch: (boId: number) => void

  permissions: string[]
  can: (permission: string) => boolean

  settings: VoiceSettings | null
  /** IANA zone for rendering. Timestamps are stored and sent in UTC. */
  timezone: string
  currency: string

  status: 'loading' | 'ready' | 'no-companies' | 'error'
  error: ApiError | null
  reload: () => void
}

const VoiceContext = createContext<VoiceContextValue | null>(null)

const STORAGE_KEY = 'voice.company'

export function VoiceProvider({ children }: { children: ReactNode }) {
  const [companies, setCompanies] = useState<Company[]>([])
  const [company, setCompany] = useState<Company | null>(null)
  const [branchId, setBranchId] = useState(0)
  const [access, setAccess] = useState<AccessInfo | null>(null)
  const [settings, setSettings] = useState<VoiceSettings | null>(null)
  const [status, setStatus] = useState<VoiceContextValue['status']>('loading')
  const [error, setError] = useState<ApiError | null>(null)
  const [nonce, setNonce] = useState(0)

  // The scope is registered synchronously, before the effects that fetch with
  // it run. An async setScope would let one request escape with the old value.
  if (company) {
    setScope({ cmp_id: company.cmp_id, bo_id: branchId })
  }

  const generation = useRef(0)

  // ---- companies, from Manage ------------------------------------------
  useEffect(() => {
    const mine = ++generation.current
    const controller = new AbortController()

    setStatus('loading')
    setError(null)

    // Voice's own endpoint, which reads Manage live. Not the portal auth relay:
    // the portal authenticates people and Manage owns companies.
    api
      .getGlobal<{ data: Array<{ cmp_id: number; name: string; branches: Array<{ bo_id: number; name: string }> }> }>(
        'v1/companies',
        undefined,
        controller.signal,
      )
      .then((response) => {
        if (mine !== generation.current) return

        const list: Company[] = (response.data ?? []).map((row) => ({
          cmp_id: row.cmp_id,
          name: row.name,
          bo_id: 0,
          branches: row.branches.length > 0 ? row.branches : undefined,
        }))

        setCompanies(list)

        if (list.length === 0) {
          setStatus('no-companies')
          return
        }

        const remembered = Number(readRemembered())
        const chosen = list.find((entry) => entry.cmp_id === remembered) ?? list[0]
        setCompany(chosen)
        setScope({ cmp_id: chosen.cmp_id, bo_id: 0 })
        setStatus('ready')
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted || mine !== generation.current) return
        // Unreachable is not "no companies". Told apart, because one is a
        // retry and the other is an administrator's problem.
        setError(err instanceof ApiError ? err : new ApiError(0, 'error', 'Could not load your companies.'))
        setStatus('error')
      })

    return () => controller.abort()
  }, [nonce])

  // ---- permissions and settings, per company ---------------------------
  useEffect(() => {
    if (!company) return

    const controller = new AbortController()
    const forCompany = company.cmp_id

    // Cleared first: a screen must never render the previous company's
    // permissions against the new company's name.
    setAccess(null)
    setSettings(null)

    Promise.all([
      api.get<{ data: AccessInfo }>('v1/access', undefined, controller.signal),
      api.get<{ data: VoiceSettings }>('v1/settings', undefined, controller.signal),
    ])
      .then(([accessResponse, settingsResponse]) => {
        if (controller.signal.aborted || company.cmp_id !== forCompany) return
        setAccess(accessResponse.data)
        setSettings(settingsResponse.data)
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        if (err instanceof ApiError && err.isForbidden) {
          setAccess({ catalog: {}, granted: [], grantable: [], profiles: [], note: '' })
          return
        }
        setError(err instanceof ApiError ? err : null)
      })

    return () => controller.abort()
  }, [company, branchId])

  const switchCompany = useCallback(
    (cmpId: number) => {
      const next = companies.find((entry) => entry.cmp_id === cmpId)
      if (!next || next.cmp_id === company?.cmp_id) return

      // Order matters: the client's scope changes before anything refetches.
      setScope({ cmp_id: next.cmp_id, bo_id: 0 })
      setBranchId(0)
      setAccess(null)
      setSettings(null)
      setCompany(next)
      remember(next.cmp_id)
    },
    [companies, company],
  )

  const switchBranch = useCallback(
    (boId: number) => {
      if (!company) return
      setScope({ cmp_id: company.cmp_id, bo_id: boId })
      setBranchId(boId)
    },
    [company],
  )

  const permissions = access?.granted ?? []

  const can = useCallback(
    (permission: string) => permissions.includes(permission),
    [permissions],
  )

  const value = useMemo<VoiceContextValue>(
    () => ({
      company,
      companies,
      branchId,
      switchCompany,
      switchBranch,
      permissions,
      can,
      settings,
      // The browser's zone is not the business's. Times are rendered in the
      // company's configured zone and persisted in UTC.
      timezone: settings?.timezone ?? 'Asia/Kolkata',
      currency: settings?.currency ?? 'INR',
      status,
      error,
      reload: () => setNonce((n) => n + 1),
    }),
    [company, companies, branchId, switchCompany, switchBranch, permissions, can, settings, status, error],
  )

  return <VoiceContext.Provider value={value}>{children}</VoiceContext.Provider>
}

export function useVoice(): VoiceContextValue {
  const ctx = useContext(VoiceContext)
  if (!ctx) throw new Error('useVoice must be used inside <VoiceProvider>')
  return ctx
}

/**
 * The remembered company.
 *
 * A per-viewer convenience and nothing more: it stores ONE NUMBER, the id of
 * the company last opened. It is not a cache of that company's data, and the
 * backend re-verifies access to it on every request regardless.
 */
function readRemembered(): string {
  try {
    return window.localStorage.getItem(STORAGE_KEY) ?? ''
  } catch {
    return ''
  }
}

function remember(cmpId: number): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, String(cmpId))
  } catch {
    // Private browsing, blocked storage. The app works without it.
  }
}
