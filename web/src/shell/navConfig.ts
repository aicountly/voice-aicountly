/**
 * The left navigation.
 *
 * Two groups, separated. The first six are the dashboards — the same six the
 * backend serves at /v1/dashboards/{view} — and everything below the separator
 * is the product's working surfaces.
 *
 * `permission` hides an item somebody cannot use. That is a COURTESY: the
 * backend asserts every permission before the query, so hiding a link saves a
 * wasted click and nothing more.
 *
 * `capability` hides an item this deployment cannot do at all. Different thing:
 * an AI Voice Studio with no AI configured is not a permissions problem, it is
 * a screen about something this deployment does not have.
 *
 * A small business with three people and one number sees the dashboards, Calls,
 * Callbacks, Contacts and Recordings — and none of the provider, queue or
 * access administration, because none of it applies to them.
 */

import {
  Activity, BarChart3, Blocks, ClipboardList, Contact, FileAudio,
  Gauge, Hash, LayoutDashboard, ListChecks, Megaphone, MessageSquareText,
  Network, PhoneCall, Radio, Settings as SettingsIcon, ShieldCheck,
  Sparkles, Users, Workflow,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  exact?: boolean
  permission?: string
  capability?: string
  /** Renders a divider above this item. */
  separator?: boolean
  /** Hidden from the simplified view a small business gets by default. */
  advanced?: boolean
}

export const NAV: NavItem[] = [
  // Dashboards
  { to: '/', label: 'Command Centre', icon: LayoutDashboard, exact: true, permission: 'voice.dashboard.view' },
  { to: '/live', label: 'Live Operations', icon: Radio, permission: 'voice.dashboard.view' },
  { to: '/studio', label: 'AI Voice Studio', icon: Sparkles, permission: 'voice.ai.view' },
  { to: '/campaigns', label: 'Campaigns & Growth', icon: Megaphone, permission: 'voice.campaigns.view' },
  { to: '/intelligence', label: 'Conversation Intelligence', icon: MessageSquareText, permission: 'voice.dashboard.view' },
  { to: '/network', label: 'Network & Usage', icon: Network, permission: 'voice.dashboard.view' },

  // Workspace
  { to: '/calls', label: 'Calls', icon: PhoneCall, separator: true, permission: 'voice.call.view' },
  { to: '/callbacks', label: 'Callbacks', icon: ListChecks, permission: 'voice.callbacks.view' },
  { to: '/contacts', label: 'Contacts', icon: Contact, permission: 'voice.call.view' },
  { to: '/agents', label: 'Agents & Teams', icon: Users, permission: 'voice.call.view', advanced: true },
  { to: '/numbers', label: 'Numbers', icon: Hash, permission: 'voice.call.view', advanced: true },
  { to: '/call-flows', label: 'Call Flows', icon: Workflow, permission: 'voice.call.view', advanced: true },
  { to: '/queues', label: 'Queues & Ring Groups', icon: Activity, permission: 'voice.call.view', advanced: true },
  { to: '/recordings', label: 'Recordings & Voicemail', icon: FileAudio, permission: 'voice.recordings.listen' },
  { to: '/reports', label: 'Reports', icon: BarChart3, permission: 'voice.reports.view', advanced: true },
  { to: '/integrations', label: 'Integrations', icon: Blocks, permission: 'voice.dashboard.view', advanced: true },
  { to: '/settings', label: 'Settings', icon: SettingsIcon, permission: 'voice.dashboard.view' },
  { to: '/audit', label: 'Audit', icon: ShieldCheck, permission: 'voice.audit.view', advanced: true },
]

/** Nav items this user can actually use. */
export function visibleNav(
  can: (permission: string) => boolean,
  capabilities: Record<string, boolean>,
  showAdvanced: boolean,
): NavItem[] {
  return NAV.filter((item) => {
    if (item.permission && !can(item.permission)) return false
    if (item.capability && !capabilities[item.capability]) return false
    if (item.advanced && !showAdvanced) return false
    return true
  })
}

export { Gauge, ClipboardList }
