import { createCompanyRegistryClient } from '../company-access/index.ts'
import { ensureSesKey } from '../auth/portal'
import { getManageApiOrigin } from './appLauncher'

/**
 * The AICOUNTLY company registry (Manage /api/v1/*) as Voice sees it.
 *
 * Discovery and Users & Access talk to Manage directly with the person's own
 * ses_key. Whether a company may be opened here is still decided by the Voice
 * API, which asks Manage companyinfo naming Voice on every scoped request.
 */
export const VOICE_APP_KEY = 'voice'
export const VOICE_APP_NAME = 'Aicountly Voice'

export const companyRegistry = createCompanyRegistryClient({
  appKey: VOICE_APP_KEY,
  baseUrl: `${getManageApiOrigin()}/api`,
  getSesKey: () => ensureSesKey(),
})
