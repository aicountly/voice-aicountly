/**
 * Users & Access, native in Voice.
 *
 * Who may OPEN this company in Voice, from the one AICOUNTLY company registry in
 * Manage: the verified company owner grants, changes and revokes it here;
 * everyone else sees their own access and can ask for it. No redirect to
 * Manage. What people may DO inside Voice stays with the permission profiles on
 * the Access screen, which remain authoritative.
 */

import { Link } from 'react-router-dom'
import { CompanyUsersAccessPanel, MyWorkspacesPanel } from '../company-access/index.ts'
import { useVoice } from '../context/VoiceContext'
import { companyRegistry, VOICE_APP_NAME } from '../services/companyRegistry'
import { PageHeader } from '../shell/AppShell'
import { Card, Notice } from '../ui'

export function UsersAccess() {
  const { company } = useVoice()

  return (
    <>
      <PageHeader title="Users & Access" subtitle="Who may open this company in Voice. Seeing a company in your workspace is not access to it." />
      <div className="vstack">
        <Notice tone="info" title="Company access and Voice permissions are separate">
          <p style={{ margin: 0 }}>
            Only the company owner can grant or revoke access to the company. What each person may do inside Voice is set by
            their permission profile on the <Link to="/access">Access</Link> screen.
          </p>
        </Notice>

        {company ? (
          <Card>
            <CompanyUsersAccessPanel key={company.cmp_id} client={companyRegistry} cmpId={company.cmp_id} appName={VOICE_APP_NAME} />
          </Card>
        ) : null}

        <Card title="My workspaces" subtitle="Accept an invitation to discover a workspace's companies; leave one you no longer belong to.">
          <MyWorkspacesPanel client={companyRegistry} />
        </Card>
      </div>
    </>
  )
}
