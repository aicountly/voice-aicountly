/**
 * The reference of an API request (X-Request-Id), so a failure a person sees can be found in the API's log.
 *
 * Every API call sends a fresh 32-hex id. The API logs an uncaught failure under it and answers with the
 * same id (`request_id` in the body, the X-Request-Id header). On a 5xx the message shown ends with
 * " (Ref: <id>)" — an empty 5xx body included, which still gets a sentence: never a blank error, never
 * "Request failed (500)" alone. (Mirrors Books' apiFetch `_requestIdOf` / `_parseApiError`.)
 */

/** A fresh request id: 32 hex characters. */
export function newRequestId(): string {
  const bytes = new Uint8Array(16)
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
      crypto.getRandomValues(bytes)
      return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
    }
  } catch {
    // fall through
  }
  for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

const PLAUSIBLE = /^[A-Za-z0-9._-]{6,64}$/

/**
 * The id the API logged this request under: the one the request sent, else the one the response names
 * (X-Request-Id header, then `request_id` in the body).
 */
export function requestIdOf(
  sent: string | null | undefined,
  headers?: { get(name: string): string | null } | null,
  body?: unknown,
): string {
  const own = String(sent ?? '').trim()
  if (own) return own
  try {
    const header = String(headers?.get('X-Request-Id') ?? '').trim()
    if (PLAUSIBLE.test(header)) return header
  } catch {
    // no headers
  }
  if (body && typeof body === 'object') {
    const b = body as { request_id?: unknown; error?: { request_id?: unknown; details?: { request_id?: unknown } } }
    for (const candidate of [b.request_id, b.error?.request_id, b.error?.details?.request_id]) {
      if (typeof candidate === 'string' && PLAUSIBLE.test(candidate.trim())) return candidate.trim()
    }
  }
  return ''
}

/**
 * The message to show for a failed response. A 5xx with no message gets a sentence of its own, and every
 * 5xx ends with " (Ref: <id>)" unless the message already quotes that id.
 */
export function withRef(status: number, message: string | null | undefined, ref: string): string {
  let text = String(message ?? '').trim()
  if (!text) {
    text =
      status >= 500
        ? `The server hit an unexpected error and gave no details. Please retry; if it keeps failing, contact support${ref ? ' with this reference' : ''}.`
        : `Request failed (${status}).`
  }
  if (status >= 500 && ref && !text.includes(ref)) text = `${text} (Ref: ${ref})`
  return text
}
