/**
 * Typed fetch wrapper for the Voice API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on
 *    demand, with one silent retry on 401 (a key can be revoked before its
 *    local expiry).
 *  - Company context (cmp_id, bo_id) on every scoped call, as query parameters
 *    and — for JSON bodies — in the body too, which is what the backend's
 *    Http::param() reads.
 *  - An `Idempotency-Key` on every mutation, generated per call. Placing a call
 *    is the one mutation in this product where a retry does not create a
 *    duplicate ROW, it rings a member of the public a second time, and the
 *    surest way to get that right everywhere is to never leave it to the
 *    caller.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

export interface CompanyScope {
  cmp_id: number
  /** 0 = all branches for this company. */
  bo_id: number
}

export interface ListMeta {
  total: number
  limit: number
  offset: number
  [key: string]: unknown
}

export interface ListResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /**
   * True when pressing the same button again could reasonably work.
   *
   * The backend says so explicitly, because the difference between "the
   * provider was unreachable" and "that number is on the suppression list"
   * decides whether the UI offers Retry or explains why it never will.
   */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 502 || this.status === 503 || this.status === 504
  }

  /**
   * True when the request may have taken effect.
   *
   * THE IMPORTANT ONE IN THIS PRODUCT. A call whose outcome is unknown may be
   * ringing somebody. The UI must say so and must not offer a button that
   * dials them again.
   */
  get isUnknownOutcome(): boolean {
    return this.code === 'outcome_unknown'
  }

  /** True when this deployment has no telephony provider connected. */
  get isProviderMissing(): boolean {
    return this.code === 'provider_not_configured'
  }

  /** True when another product could not be reached. Never "there is no data". */
  get isOwnerUnavailable(): boolean {
    return this.code === 'owner_unavailable'
      || this.code === 'calendar_unavailable'
      || this.code === 'context_unavailable'
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  /** Field-level validation messages, when the backend sent any. */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [key, value] of Object.entries(this.details)) {
      if (typeof value === 'string' && key !== 'reason' && key !== 'retryable') out[key] = value
    }
    return out
  }
}

/** The company scope, registered once by VoiceProvider and read by every call. */
let scope: CompanyScope | null = null

export function setScope(next: CompanyScope | null): void {
  scope = next
}

export function getScope(): CompanyScope | null {
  return scope
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

/**
 * A key the backend will accept: 8–200 characters of [A-Za-z0-9._:-].
 *
 * `crypto.randomUUID` where it exists, and a composed fallback where it does
 * not — an older browser must still be protected from a double-tap, and that
 * is exactly the browser most likely to be on a bad connection.
 */
function idempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}-${Math.random()
    .toString(36)
    .slice(2, 12)}`
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  signal?: AbortSignal
  /** Reuse a key across retries of the same user action. Generated when omitted. */
  idempotencyKey?: string
}

async function send<T>(path: string, options: RequestOptions = {}, isRetry = false): Promise<T> {
  const method = options.method ?? 'GET'
  const scoped = options.scoped !== false

  let sesKey: string
  try {
    sesKey = await ensureSesKey()
  } catch (err) {
    throw new ApiError(401, 'unauthorized', err instanceof Error ? err.message : 'Sign in again to continue.')
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
  }

  let payload: string | undefined
  if (options.body !== undefined && method !== 'GET') {
    headers['Content-Type'] = 'application/json'
    // The scope goes in the body too: the backend reads either, and a POST
    // whose scope lives only in the query string is one refactor away from
    // losing it.
    const merged =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...(options.body as Record<string, unknown>), cmp_id: scope.cmp_id, bo_id: scope.bo_id }
        : options.body
    payload = JSON.stringify(merged)
  }

  if (method !== 'GET') {
    headers['Idempotency-Key'] = options.idempotencyKey ?? idempotencyKey()
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.params, scoped), {
      method,
      headers,
      body: payload,
      signal: options.signal,
    })
  } catch (err) {
    if ((err as Error)?.name === 'AbortError') throw err
    throw new ApiError(0, 'network_error', 'Could not reach the Voice service. Check your connection.')
  }

  // One silent retry: a ses_key can be revoked before its local expiry, and the
  // user should not see a sign-in screen for a key we can simply re-mint.
  if (response.status === 401 && !isRetry) {
    return send<T>(path, options, true)
  }

  if (response.status === 204) {
    return undefined as T
  }

  let parsed: unknown
  try {
    parsed = await response.json()
  } catch {
    parsed = null
  }

  if (!response.ok) {
    const envelope = (parsed ?? {}) as {
      error?: { code?: string; message?: string; details?: Record<string, unknown> }
      message?: string
    }
    throw new ApiError(
      response.status,
      envelope.error?.code ?? 'error',
      envelope.error?.message ?? envelope.message ?? `Request failed (${response.status}).`,
      envelope.error?.details ?? {},
    )
  }

  // 202 with an outcome_unknown error body: the request was accepted but its
  // effect is not confirmed. Surfaced as an ApiError so callers cannot mistake
  // it for success, with isUnknownOutcome to distinguish it from a failure.
  if (response.status === 202) {
    const envelope = (parsed ?? {}) as {
      error?: { code?: string; message?: string; details?: Record<string, unknown> }
      message?: string
    }
    if (envelope.error?.code === 'outcome_unknown') {
      throw new ApiError(
        202,
        'outcome_unknown',
        envelope.error.message ?? envelope.message ?? 'The outcome is not confirmed.',
        envelope.error.details ?? {},
      )
    }
  }

  return parsed as T
}

export const api = {
  get: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    send<T>(path, { method: 'GET', params, signal }),

  /** Unscoped GET, for endpoints that take no company context. */
  getGlobal: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    send<T>(path, { method: 'GET', params, scoped: false, signal }),

  post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    send<T>(path, { ...options, method: 'POST', body }),

  put: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    send<T>(path, { ...options, method: 'PUT', body }),
}

/** The SSE endpoint, with the scope and the session on the query string. */
export async function eventStreamUrl(lastEventId?: number): Promise<string> {
  const sesKey = await ensureSesKey()
  const url = new URL(`${getApiBaseUrl()}/v1/events`, window.location.origin)
  if (scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }
  if (lastEventId) url.searchParams.set('last_event_id', String(lastEventId))
  // EventSource cannot set headers, so the key travels as a parameter on a
  // same-origin request. The backend accepts either.
  url.searchParams.set('access_token', sesKey)
  return url.toString()
}
