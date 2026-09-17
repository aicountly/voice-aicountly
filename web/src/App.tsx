import { lazy, useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'

import { useAuth } from './auth/AuthProvider'
import { VoiceProvider } from './context/VoiceContext'
import { AppShell } from './shell/AppShell'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'
import { LoadingRows } from './ui'
import './ui/voice-ui.css'

initAnalytics()

// Each dashboard is its own chunk. Somebody who only ever opens Live Operations
// should not download the campaign builder and the flow editor to get there.
const CommandCentre = lazy(() => import('./dashboards/CommandCentre'))
const LiveOperations = lazy(() => import('./dashboards/LiveOperations'))
const Studio = lazy(() => import('./dashboards/Studio'))
const Campaigns = lazy(() => import('./dashboards/Campaigns'))
const Intelligence = lazy(() => import('./dashboards/Intelligence'))
const Network = lazy(() => import('./dashboards/Network'))

const Calls = lazy(() => import('./pages/Calls'))
const Callbacks = lazy(() => import('./pages/Callbacks'))
const Contacts = lazy(() => import('./pages/Contacts'))
const CallFlows = lazy(() => import('./pages/CallFlows'))

// The workspace screens share a module: they are small, and they are almost
// always reached one after another.
const Workspace = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Numbers })))
const Queues = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Queues })))
const Agents = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Agents })))
const Recordings = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Recordings })))
const Integrations = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Integrations })))
const Settings = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Settings })))
const Audit = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Audit })))
const Reports = lazy(() => import('./pages/Workspace').then((module) => ({ default: module.Reports })))

/**
 * Login → the app.
 *
 * The portal callback lands on /auth/callback, which the SPA history fallback
 * serves with this same document; AuthProvider consumes the token at boot.
 */
export default function App() {
  const { status } = useAuth()

  useEffect(() => {
    if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status])

  if (status === 'signed-out') return <SignIn />

  if (status === 'loading') {
    return (
      <main className="vpage" style={{ maxWidth: 640, paddingTop: 80 }}>
        <p className="vmuted">Signing you in…</p>
        <LoadingRows rows={3} height={64} />
      </main>
    )
  }

  return (
    <BrowserRouter>
      <VoiceProvider>
        <RouteTracker />
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<CommandCentre />} />
            <Route path="live" element={<LiveOperations />} />
            <Route path="studio" element={<Studio />} />
            <Route path="campaigns" element={<Campaigns />} />
            <Route path="intelligence" element={<Intelligence />} />
            <Route path="network" element={<Network />} />

            <Route path="calls" element={<Calls />} />
            <Route path="callbacks" element={<Callbacks />} />
            <Route path="contacts" element={<Contacts />} />
            <Route path="agents" element={<Agents />} />
            <Route path="numbers" element={<Workspace />} />
            <Route path="call-flows" element={<CallFlows />} />
            <Route path="queues" element={<Queues />} />
            <Route path="recordings" element={<Recordings />} />
            <Route path="reports" element={<Reports />} />
            <Route path="integrations" element={<Integrations />} />
            <Route path="settings" element={<Settings />} />
            <Route path="audit" element={<Audit />} />

            {/* The portal callback path and anything unknown land on the
                Command Centre rather than a 404 nobody can act on. */}
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </VoiceProvider>
    </BrowserRouter>
  )
}

function RouteTracker() {
  useEffect(() => {
    trackPageView(window.location.pathname, document.title)
  }, [])
  return null
}
