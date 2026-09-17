/**
 * The shapes the Voice API answers with.
 *
 * Mirrors what the PHP controllers present. Two things are deliberate and
 * repeated throughout:
 *
 *  1. THERE IS NO CONTACT NAME ON A CALL. `contact_ref` is a handle into
 *     Aicountly Contacts, resolved live by whoever displays the row. A `name`
 *     field here would be a copy, and a copy is what goes stale.
 *
 *  2. STATE CARRIES ITS OWN UNCERTAINTY. `state_is_stale` and
 *     `presence_is_stale` exist so a screen can say "we cannot tell" instead of
 *     showing a call that ended ten minutes ago as connected.
 */

/**
 * The list and item envelopes every endpoint answers with.
 *
 * Defined in services/api.ts, which owns the transport. Re-exported here so a
 * page has one import for everything it reads off the wire.
 */
export type { ItemResponse, ListMeta, ListResponse } from './api'

export type CallState =
  | 'initiated' | 'queued' | 'ringing' | 'answered' | 'held' | 'transferring'
  | 'completed' | 'busy' | 'unanswered' | 'cancelled' | 'failed' | 'unknown'

export type CallOutcome =
  | 'ai_completed' | 'human_completed' | 'handover_completed'
  | 'abandoned' | 'voicemail' | 'failed' | 'no_answer'

export interface Call {
  call_id: number
  call_uuid: string
  direction: 'inbound' | 'outbound' | 'internal'
  origin: string
  remote_e164: string | null
  remote_masked: string | null
  local_e164: string | null
  /** Aicountly Contacts' id. The name is read from Contacts, never stored here. */
  contact_ref: string | null
  crm_lead_ref: string | null
  campaign_id: number | null
  queue_id: number | null
  queue_name?: string | null
  owner_agent_id: number | null
  agent_uuid?: string | null
  agent_extension?: string | null
  handled_by: 'ai' | 'human' | 'both' | 'unassigned'
  state: CallState
  /** What the provider last told us, even when `state` reads 'unknown'. */
  last_known_state: CallState
  state_is_stale: boolean
  state_reason: string | null
  outcome: CallOutcome | null
  abandoned: boolean
  recording_state: 'none' | 'recording' | 'stored' | 'refused' | 'failed'
  consent_state: 'not_applicable' | 'announced' | 'granted' | 'refused'
  language: string | null
  initiated_at: string
  answered_at: string | null
  ended_at: string | null
  queued_seconds: number
  talk_seconds: number
  total_seconds: number
  disposition_id: number | null
  disposition?: string | null
  disposition_category?: string | null
  disposition_note: string | null
  waiting_seconds?: number
}

export interface TranscriptSegment {
  segment_id: number
  sequence_no: number
  speaker: 'caller' | 'agent' | 'ai' | 'unknown'
  speaker_label: string | null
  started_ms: number
  ended_ms: number | null
  /** False while the recogniser is still revising this line. */
  is_final: boolean
  language: string | null
  text: string
  translated_text: string | null
  translated_to: string | null
  /** null means the recogniser reported none — not that it was certain. */
  confidence: number | null
  redacted: boolean
}

export interface Metric {
  id: string
  label: string
  value: number | null
  unit: 'count' | 'percent' | 'currency' | 'seconds' | 'ms' | 'score'
  previous: number | null
  change_pct: number | null
  direction: 'up_is_good' | 'down_is_good' | 'neutral'
  note: string | null
  status: 'unavailable' | null
  unavailable_reason: string | null
  drilldown: { route: string; params: Record<string, string> } | null
}

export interface DashboardEnvelope<P = Record<string, unknown>> {
  view: string
  period: { from: string; to: string; preset: string; label: string; [k: string]: unknown }
  currency: string
  timezone: string
  metrics: Metric[]
  panels: P
  freshness: { generated_at: string; sources: Array<{ name: string; kind: string }> }
  definitions: Record<string, string>
}

export interface AttentionItem {
  id: string
  priority: 'high' | 'medium' | 'low'
  title: string
  why: string
  source: string
  action: string
  link: { route: string; params: Record<string, string> }
}

export interface QueueSummary {
  queue_id: number
  name: string
  kind?: string
  strategy?: string
  waiting: number
  in_call: number
  longest_wait: number
  members?: number
  max_waiting?: number
}

export interface AgentSummary {
  agent_id: number
  /** The portal owns the name; this is the handle to resolve it. */
  user_uuid: string
  extension: string | null
  voice_role: string
  skills: string[]
  languages: string[]
  presence: 'available' | 'busy' | 'wrap_up' | 'away' | 'offline' | 'unknown'
  presence_is_stale: boolean
  last_known_presence: string
  presence_since: string | null
  active_calls: number
}

export interface CallControls {
  available: string[]
  reasons: Record<string, 'provider_unsupported' | 'permission_required' | 'disabled_by_policy'>
  provider: string
  browser_calling: boolean
}

export interface Callback {
  callback_id: number
  source_call_id: number | null
  campaign_id: number | null
  contact_ref: string | null
  e164: string
  e164_masked: string
  reason: string
  priority: 'high' | 'normal' | 'low'
  due_at: string | null
  assigned_agent_id: number | null
  queue_id: number | null
  status: 'open' | 'scheduled' | 'in_progress' | 'completed' | 'cancelled' | 'failed'
  attempts: number
  max_attempts: number
  last_attempt_at: string | null
  /** Calendar's id. The event's time is read from Calendar, not stored here. */
  calendar_event_ref: string | null
  created_at: string
}

export interface Campaign {
  campaign_id: number
  name: string
  description: string
  mode: string
  status: 'draft' | 'ready' | 'scheduled' | 'running' | 'paused' | 'completed' | 'cancelled' | 'failed'
  status_reason: string | null
  audience?: number
  attempted?: number
  connected?: number
  progress?: number | null
  ready?: boolean
  blocking?: string[]
  readiness?: { ready: boolean; checks: ReadinessCheck[] }
  outcomes?: CampaignFunnel
  timezone: string
  window_start_min: number
  window_end_min: number
  window_days: number[]
  max_concurrent: number
  calls_per_minute: number
  max_attempts: number
  budget_minor: number
  spent_minor: number
  approved_at: string | null
  started_at: string | null
  created_at: string
}

export interface ReadinessCheck {
  key: string
  status: 'pass' | 'error' | 'warn'
  message: string
  note?: string
  in_window_now?: boolean
}

export interface CampaignFunnel {
  attempted: number
  connected: number
  skipped: number
  qualified: number
  confirmed: number
  denominator: string
  definitions: Record<string, string>
  rates: Record<string, number>
}

export interface AiAgent {
  ai_agent_id: number
  name: string
  role: string
  description: string
  status: 'draft' | 'tested' | 'published' | 'paused' | 'archived'
  persona?: Record<string, unknown>
  languages?: string[]
  action_permissions?: Record<string, string>
  flow_id?: number | null
  draft_version_no?: number | null
  published_version_no?: number | null
  published_at?: string | null
  validation?: { valid: boolean; errors: FlowIssue[]; warnings: FlowIssue[] }
  tests?: AiTestSummary
}

export interface AiTestSummary {
  runs: number
  runs_passed: number
  runs_failed: number
  last_run_at: string | null
  passed: number
  failed: number
  never_tested: boolean
  latest: { scenario: string; status: string; checks: AiCheck[]; started_at: string } | null
}

export interface AiCheck {
  key: string
  label: string
  status: 'passed' | 'failed' | 'not_applicable'
  detail: string
}

export interface FlowIssue {
  code: string
  node: string
  message: string
}

export interface FlowValidation {
  valid: boolean
  errors: FlowIssue[]
  warnings: FlowIssue[]
  checked: number
}

export interface FlowNode {
  type: string
  label?: string
  next?: string
  branches?: Record<string, string>
  timeout?: string
  on_failure?: string
  on_no_match?: string
  timeout_seconds?: number
  max_attempts?: number
  destination?: string
  action?: string
  [key: string]: unknown
}

export interface FlowDefinition {
  entry: string
  nodes: Record<string, FlowNode>
}

export interface Commitment {
  commitment_id: number
  call_id: number
  party: 'business' | 'caller'
  description: string
  owner_agent_id: number | null
  owner_uuid?: string | null
  owner_hint: string | null
  due_at: string | null
  due_text: string | null
  /** 'needs_clarification' when the call never settled a date. Never guessed. */
  due_state: 'set' | 'needs_clarification'
  evidence: number[]
  confidence: number | null
  status: 'suggested' | 'confirmed' | 'rejected' | 'completed'
  external_system: string | null
  external_task_ref: string | null
  created_at: string
}

export interface ExternalOperation {
  operation_id: number
  target_app: string
  operation: string
  status: 'pending' | 'succeeded' | 'failed' | 'unknown' | 'reconciled' | 'abandoned'
  external_ref: string | null
  /** True ONLY when the owning product acknowledged it. */
  confirmed: boolean
  message: string
  created_at: string
  updated_at: string
}

export interface ProviderConnection {
  connection_id: number
  provider: string
  adapter: string
  label: string
  adapter_label: string
  role: 'primary' | 'backup'
  is_active: boolean
  max_concurrent: number
  capabilities: Record<string, boolean>
  /** What is configured — never any part of a value. */
  credentials: { configured: boolean; fields: string[] }
  status: string
  status_detail: string | null
  status_checked_at: string | null
  last_tested_at: string | null
  latency_ms?: number | null
}

export interface IntegrationStatus {
  app: string
  label: string
  purpose?: string
  status: 'not_configured' | 'configured' | 'connected' | 'degraded' | 'unavailable' | 'forbidden'
  reason: string | null
  checked_at: string | null
  last_ok_at: string | null
  can_test: boolean
  confirmed?: number | null
  operations?: Record<string, number>
  note?: string | null
}

export interface UsageBreakdown {
  period: { start: string; end: string }
  currency: string
  categories: Array<{
    category: string
    estimated_minor: number
    confirmed_minor: number
    quantity: number
  }>
  /** Two totals, never summed: an estimate plus a confirmation double-counts. */
  totals: { estimated_minor: number; confirmed_minor: number }
  note: string | null
}

export interface BudgetStatus {
  policy_id: number
  scope: string
  period: string
  limit_minor: number
  spent_minor: number
  currency: string
  percent: number
  warn_percent: number
  on_exceed: 'warn' | 'block'
  state: 'no_limit' | 'ok' | 'warning' | 'exceeded'
}

export interface VoiceNumber {
  number_id: number
  e164: string
  masked: string
  label: string
  country?: string
  number_type: string
  team_id?: number | null
  team_name: string | null
  inbound_flow_id?: number | null
  flow_name: string | null
  connection_id?: number | null
  connection_label?: string | null
  recording_policy: string
  routing_status: string
  status_detail: string | null
  is_active: boolean
}

export interface VoiceSettings {
  timezone: string
  currency: string
  recording_policy: 'never' | 'always' | 'on_consent'
  recording_disclosure: boolean
  ai_disclosure: boolean
  ai_disclosure_text: string
  recording_retention_days: number
  transcript_retention_days: number
  calling_window_start_min: number
  calling_window_end_min: number
  calling_window_days: number[]
  supervisor_monitoring: boolean
  campaign_approval_required: boolean
  max_concurrent_calls: number
  wrap_up_seconds: number
  configured: boolean
  can_edit?: boolean
  policy_note?: string
}

export interface AccessInfo {
  catalog: Record<string, Record<string, string>>
  granted: string[]
  grantable: string[]
  profiles: Array<{ profile_id: number; name: string; description: string; permissions: string[]; is_active: boolean }>
  note: string
}

export interface AuditEvent {
  audit_id: number
  occurred_at: string
  actor_uuid: string | null
  actor_kind: string
  actor_app: string | null
  action: string
  entity_type: string | null
  entity_id: string | null
  detail: Record<string, unknown>
}

export interface Recording {
  recording_uuid: string
  call_id: number
  kind: string
  media_type: string
  duration_seconds: number
  status: 'pending' | 'available' | 'failed' | 'deleted'
  legal_hold: boolean
  delete_after: string | null
  created_at: string
}

export interface PlaybackGrant {
  url: string
  expires_at: string
  media_type: string
  duration_seconds: number
}
