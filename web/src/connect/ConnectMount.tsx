import { useEffect, useRef } from 'react'
import { sesKeyForEmbed } from '../auth/portal'
import { useVoice } from '../context/VoiceContext'
import { buildAppJumpUrl } from '../services/appLauncher'
import { CONNECT_PRODUCT, closeConnect, connectScopeFrom, startConnect, stopConnect, updateConnect } from './connect'
import { resolveConnectOrigin } from './loader'
import { CONNECT_EVENTS, CONNECT_HOST_ID } from './types'

/**
 * Stacking level of the whole widget (launcher, panel, its dialogs).
 *
 * Above the sticky top bar (20), whose company notices live in its layer, so
 * none of it cuts into the open panel; below Voice's own layers — the call
 * drawer and its scrim (60), the skip link (100), and the toasts (1000) — so a
 * Voice drawer is never covered by the Connect launcher and a toast is never
 * hidden behind it.
 */
export const CONNECT_Z_INDEX = 50

/**
 * AICOUNTLY Connect, mounted for the signed-in person.
 *
 * Rendered by App inside VoiceProvider, which exists only while somebody is
 * signed in — so mounting here IS "after sign-in", once, and outside the
 * company-keyed outlet, so a company switch is an update() rather than a new
 * widget. Unmounting (sign-out, or the session ending) destroys it, and
 * sign-out stops it first, before the portal logout (AuthProvider). It draws
 * nothing itself: the widget lives in a shadow root on its own element under
 * <body>, where Voice's styles cannot reach it and it cannot reach them.
 *
 * The company it is given is the one Voice is working in, with its branch;
 * none while there is none. Nothing here blocks or breaks Voice: if Connect
 * cannot be loaded the status is 'unavailable', and that is all.
 */
export function ConnectMount() {
  const { company, branchId } = useVoice()
  const scope = connectScopeFrom(company, branchId)

  // The widget is started once; it must start with the scope of the render it
  // is started from, not the first one ever seen.
  const latest = useRef(scope)
  useEffect(() => {
    latest.current = scope
  })

  useEffect(() => {
    void startConnect(resolveConnectOrigin(), {
      getSesKey: sesKeyForEmbed,
      product: CONNECT_PRODUCT,
      ...latest.current,
      advisorUrl: buildAppJumpUrl('advisor') ?? undefined,
      position: 'bottom-right',
      zIndex: CONNECT_Z_INDEX,
    })

    return () => stopConnect()
  }, [])

  useEffect(() => {
    updateConnect({ companyId: scope.companyId, fyId: scope.fyId, boId: scope.boId })
  }, [scope.companyId, scope.fyId, scope.boId])

  // Going back to Voice — pressing anywhere outside the widget — closes the
  // panel, so it is never left open over the queue or a call somebody is on,
  // and the top bar's notices never open underneath it.
  useEffect(() => closeConnectOnOutsidePress(), [])

  return null
}

/**
 * Close the Connect panel on a press outside it. Presses inside the widget reach
 * the document retargeted to its host element, so they are told apart by that.
 */
function closeConnectOnOutsidePress(): () => void {
  let open = false
  const onOpened = () => {
    open = true
  }
  const onClosed = () => {
    open = false
  }
  const onPointerDown = (event: Event) => {
    if (!open) return
    const host = document.getElementById(CONNECT_HOST_ID)
    if (host && event.target instanceof Node && host.contains(event.target)) return
    closeConnect()
  }
  window.addEventListener(CONNECT_EVENTS.OPENED, onOpened)
  window.addEventListener(CONNECT_EVENTS.CLOSED, onClosed)
  document.addEventListener('pointerdown', onPointerDown, true)
  return () => {
    window.removeEventListener(CONNECT_EVENTS.OPENED, onOpened)
    window.removeEventListener(CONNECT_EVENTS.CLOSED, onClosed)
    document.removeEventListener('pointerdown', onPointerDown, true)
  }
}
