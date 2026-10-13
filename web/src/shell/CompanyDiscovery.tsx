/**
 * Company discovery from the AICOUNTLY company registry (Manage).
 *
 * "Browse all workspace companies" lists every company in the person's
 * workspaces with their access in Voice. Choosing one they may open switches to
 * it through the same switchCompany the picker uses (the API re-checks it with
 * Manage); a restricted one opens "Request access" instead, never the company.
 * Discovery is not access.
 */

import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Building2 } from 'lucide-react'
import { CompanyDirectoryPicker, RestrictedCompanyDialog } from '../company-access/index.ts'
import type { DirectoryCompany } from '../company-access/index.ts'
import { useVoice } from '../context/VoiceContext'
import type { Company } from '../context/VoiceContext'
import { companyRegistry, VOICE_APP_NAME } from '../services/companyRegistry'
import { Button, Drawer } from '../ui'

export function BrowseCompaniesButton({ label = 'Browse all workspace companies', compact = false, variant = 'ghost' }: {
  label?: string
  compact?: boolean
  variant?: 'default' | 'primary' | 'ghost'
}) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant={variant} size="sm" icon={Building2} onClick={() => setOpen(true)} title={label} aria-label={label}>
        {compact ? undefined : label}
      </Button>
      {open ? <CompanyDirectoryDrawer onClose={() => setOpen(false)} /> : null}
    </>
  )
}

function CompanyDirectoryDrawer({ onClose }: { onClose: () => void }) {
  const { company, openCompany } = useVoice()
  // Portalled: the button sits in the top bar, whose stacking context is not the page's.
  return createPortal(
    <Drawer
      title="Workspace companies"
      subtitle="Every company registered in your Aicountly workspaces. A restricted one already exists — ask its owner for access instead of creating it again."
      onClose={onClose}
    >
      <CompanyDirectoryPicker
        client={companyRegistry}
        appName={VOICE_APP_NAME}
        title={null}
        selectedCmpId={company?.cmp_id ?? null}
        onOpen={(chosen) => {
          onClose()
          openCompany(chosen.cmp_id)
        }}
      />
    </Drawer>,
    document.body,
  )
}

/** A row of our own company list in the registry's shape, for when Manage's summary cannot be read. */
function fromListRow(company: Company): DirectoryCompany {
  return {
    canonical_company_id: company.cmp_id,
    cmp_id: company.cmp_id,
    legal_name: company.name,
    display_name: company.name,
    company_code: '',
    company_status: 'active',
    relationship: 'member',
    workspace: null,
    is_owner: false,
    is_discoverable: true,
    workspace_visibility: null,
    app: 'voice',
    access_status: (company.app_access_status as DirectoryCompany['access_status'] | null | undefined) ?? 'ACCESS_RESTRICTED',
    can_open_in_current_app: false,
    can_request_access: true,
    can_manage_access: false,
    request: null,
    entitlement_version: 0,
  }
}

/** "Company Access Restricted" for a company Manage lists but does not open to this person in Voice. */
export function RestrictedCompanyNotice({ company, onClose }: { company: Company; onClose: () => void }) {
  const [row, setRow] = useState<DirectoryCompany | null>(null)
  useEffect(() => {
    let cancelled = false
    companyRegistry
      .summary(company.cmp_id)
      .then((summary) => {
        if (!cancelled) setRow(summary)
      })
      .catch(() => {
        if (!cancelled) setRow(fromListRow(company))
      })
    return () => {
      cancelled = true
    }
  }, [company])
  if (row === null) return null
  return createPortal(
    <RestrictedCompanyDialog client={companyRegistry} company={row} appName={VOICE_APP_NAME} onClose={onClose} />,
    document.body,
  )
}
