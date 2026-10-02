/**
 * The contact directory — read LIVE from Aicountly Contacts.
 *
 * Voice has no contacts table. Every row here came from Contacts on this
 * request, with the signed-in user's own session, so their Contacts permissions
 * apply.
 *
 * When Contacts is unreachable this page says so. It does not show a stale
 * list, because there is no stale list to show.
 */

import { useState } from 'react'
import { ExternalLink, Search } from 'lucide-react'

import { useVoice } from '../context/VoiceContext'
import { useApi } from '../hooks/useApi'
import { useUrlState } from '../hooks/useUrlState'
import { api } from '../services/api'
import { PageHeader } from '../shell/AppShell'
import {
  Badge, Button, Card, EmptyState, Notice, PanelState, Row, formatDateTime, formatDuration,
} from '../ui'

/** Voice's view of a COMPANY contact (server: ContactsClient::view). */
interface DirectoryEntry {
  id: string
  display_name: string
  organization: string
  phones: Array<{ value: string; e164: string | null; label: string | null }>
  emails: Array<{ value: string; label: string | null }>
  state: 'active' | 'archived' | 'merged' | string
}

function primaryNumber(entry: DirectoryEntry): string | undefined {
  const phone = entry.phones.find((p) => p.e164) ?? entry.phones[0]
  return phone ? (phone.e164 ?? phone.value) : undefined
}

export default function Contacts() {
  const { company, branchId, timezone } = useVoice()
  const [filters, setFilters] = useUrlState({ q: '' })
  const [term, setTerm] = useState(filters.q)
  const [selected, setSelected] = useState<string | null>(null)

  const results = useApi<{ data: DirectoryEntry[] }>(
    (signal) => api.get('v1/contacts', { q: filters.q }, signal),
    [company?.cmp_id, branchId, filters.q],
    { enabled: filters.q.trim().length > 0 },
  )

  return (
    <>
      <PageHeader
        title="Contacts"
        subtitle="This company's directory, read live from Aicountly Contacts. Voice keeps no copy."
      />

      <Card>
        <form
          className="vsplit"
          onSubmit={(event) => {
            event.preventDefault()
            setFilters({ q: term })
          }}
          role="search"
        >
          <div style={{ flex: 1, position: 'relative', minWidth: 220 }}>
            <Search
              size={15}
              aria-hidden="true"
              style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--muted)' }}
            />
            <input
              className="vinput"
              style={{ paddingLeft: 36 }}
              value={term}
              onChange={(event) => setTerm(event.target.value)}
              placeholder="Name, number or email…"
              aria-label="Search contacts"
            />
          </div>
          <Button type="submit" variant="primary">Search</Button>
        </form>
      </Card>

      <div style={{ marginTop: 20 }} className="vgrid vgrid--two">
        <Card title="Results" subtitle="From Aicountly Contacts, on this request.">
          {filters.q.trim() === '' ? (
            <EmptyState title="Search the directory" body="Contacts are searched where they live — nothing is stored here." />
          ) : (
            <PanelState
              state={results}
              what="the contact directory"
              isEmpty={(data) => rows(data).length === 0}
              empty={<EmptyState title="Nobody matched" body="Try a different spelling or a phone number." />}
            >
              {(data) =>
                rows(data).map((entry) => (
                  <Row
                    key={entry.id}
                    title={entry.display_name || 'Unnamed contact'}
                    detail={primaryNumber(entry) ?? entry.emails[0]?.value ?? (entry.organization || undefined)}
                    trailing={<Badge tone="neutral">{entry.state === 'archived' ? 'Archived' : 'Company contact'}</Badge>}
                    onClick={() => setSelected(entry.id)}
                  />
                ))
              }
            </PanelState>
          )}
        </Card>

        {selected ? <ContactDetail contactRef={selected} timezone={timezone} /> : (
          <Card title="Calling history">
            <EmptyState title="Pick a contact" body="Voice’s own calls to that contact appear here." />
          </Card>
        )}
      </div>
    </>
  )
}

function ContactDetail({ contactRef, timezone }: { contactRef: string; timezone: string }) {
  const { company, branchId } = useVoice()

  const state = useApi<{
    data: {
      contact: DirectoryEntry
      calling_history: Array<{
        call_id: number
        direction: string
        initiated_at: string
        talk_seconds: number
        outcome: string | null
        remote_masked: string | null
      }>
    }
  }>(
    (signal) => api.get(`v1/contacts/${encodeURIComponent(contactRef)}`, undefined, signal),
    [company?.cmp_id, branchId, contactRef],
  )

  return (
    <Card title="Contact">
      <PanelState state={state} what="this contact">
        {(data) => (
          <div className="vstack vstack--tight">
            <div>
              <h3 style={{ margin: 0 }}>{data.data.contact.display_name || 'Unnamed contact'}</h3>
              <p className="vmuted vsmall" style={{ margin: '4px 0 0' }}>
                {primaryNumber(data.data.contact) ?? 'No number on file'}
              </p>
            </div>

            <Notice tone="info">
              <p>
                These details live in Aicountly Contacts and are shown as they are there right now. Edit them in
                Contacts.
              </p>
            </Notice>

            <section>
              <h3>Calling history</h3>
              <p className="vmuted vsmall" style={{ marginTop: 0 }}>
                Voice’s own record of calls to and from this contact.
              </p>
              {data.data.calling_history.length === 0 ? (
                <p className="vmuted vsmall" style={{ margin: 0 }}>No calls yet.</p>
              ) : (
                data.data.calling_history.map((call) => (
                  <Row
                    key={call.call_id}
                    title={`${call.direction === 'inbound' ? 'Inbound' : 'Outbound'} · ${formatDuration(call.talk_seconds)}`}
                    detail={formatDateTime(call.initiated_at, timezone)}
                    trailing={call.outcome ? <Badge tone="neutral">{call.outcome.replace(/_/g, ' ')}</Badge> : null}
                  />
                ))
              )}
            </section>
          </div>
        )}
      </PanelState>
    </Card>
  )
}

function rows(payload: { data: DirectoryEntry[] }): DirectoryEntry[] {
  return Array.isArray(payload.data) ? payload.data : []
}

export { ExternalLink }
