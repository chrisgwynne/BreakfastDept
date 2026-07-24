// Typed API contracts shared across the admin app. These mirror the
// /api/breakfast-admin/v1 response shapes (see the PHP AdminApi layer).

export type Role = 'admin' | 'manager' | 'editor' | 'analyst' | 'writer'

export interface SessionUser {
  id: string
  email: string
  name: string
  role: Role
  initials: string
  permissions: string[]
}

export interface Session {
  user: SessionUser
  csrf: string
}

export interface DashboardAttention {
  new_enquiries: number
  overdue_tasks: number
  failed_emails: number
  previews_awaiting: number
  stalled_opportunities: number
}

export interface PipelineStage {
  key: string
  label: string
  count: number
  value: number
}

export interface DashboardMetrics {
  new_leads: number
  open_opportunities: number
  pipeline_value: number
  active_previews: number
  contacts: number
  overdue_tasks: number
}

export interface ActivityItem {
  id: string
  type: string
  title: string
  meta: string
  at: string
}

export interface SystemHealth {
  queue_depth: number
  failed_jobs: number
  mail_provider: string
  production: boolean
  version: string
}

export interface UpcomingEvent {
  id: string
  title: string
  starts_at: string
  event_type: string
  all_day: boolean
}

export interface Dashboard {
  greeting: string
  date: string
  attention: DashboardAttention
  metrics: DashboardMetrics
  pipeline: PipelineStage[]
  recent: ActivityItem[]
  health: SystemHealth | null
  upcoming_events?: UpcomingEvent[]
}

export interface Paged<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export interface StageRef {
  key: string
  label: string
}

// -- CRM entities (mirror AdminApi row mappers) --------------------------

export interface Enquiry {
  id: string
  reference: string
  form_type: string
  status: string
  name: string
  email: string
  company: string
  summary: string
  created_at: string
}

export interface Contact {
  id: string
  name: string
  email: string
  phone: string
  company: string
  status: string
  lead_source: string
}

export interface Company {
  id: string
  name: string
  website: string
  sector: string
  location: string
  contact_count: number
}

export interface Opportunity {
  id: string
  title: string
  stage: string
  value: number
  probability: number
  contact: string
  next_action: string
}

export interface Task {
  id: string
  title: string
  status: string
  due_date: string
  assigned: string
}

export interface Activity {
  id: string
  type: string
  summary: string
  actor: string
  at: string
}

export interface WorkspaceSummary {
  open_opportunities: number
  open_tasks: number
  active_previews: number
  total_invoiced: number
  total_paid: number
  outstanding: number
  upcoming_event: string | null
}

export interface WorkspaceOpportunity { id: string; title: string; stage: string; value: number; probability: number }
export interface WorkspaceTask { id: string; title: string; status: string; due_date: string }
export interface WorkspaceInvoice { id: string; number: string; status: string; total: number; amount_due: number; issue_date: string }
export interface WorkspacePreview { id: string; name: string; slug: string; status: string; views: number }
export interface WorkspaceEmail { id: string; subject: string; status: string; to: string; at: string }

export interface ClientWorkspace {
  company: { id: string; name: string } | null
  summary: WorkspaceSummary
  opportunities: WorkspaceOpportunity[]
  tasks: WorkspaceTask[]
  invoices: WorkspaceInvoice[]
  previews: WorkspacePreview[]
  emails: WorkspaceEmail[]
  events: CalendarEvent[]
}

export interface ContactDetail {
  contact: Contact
  timeline: Activity[]
  workspace?: ClientWorkspace
}

export interface ListResponse<T> {
  items: T[]
  total: number
}

export interface OpportunitiesResponse {
  items: Opportunity[]
  total: number
  stages: StageRef[]
}

// -- Client previews -----------------------------------------------------

export interface Preview {
  id: string
  name: string
  client: string
  slug: string
  status: string
  visibility: string
  password: boolean
  views: number
  last_viewed: string
  expires_at: string
  version_count: number
  url?: string
}

// -- Reports & operations ------------------------------------------------

export interface StageValue {
  count: number
  value: number
}

export interface Reports {
  enquiries_by_source: Record<string, number>
  pipeline_by_stage: Record<string, StageValue>
  stages: StageRef[]
  open_opportunities: number
  pipeline_value: number
}

export interface Operations {
  queue: { pending: number; failed: number }
  mail: { provider: string; recent_failures: number }
  health: SystemHealth
}

// -- Email delivery ------------------------------------------------------

export interface EmailMessage {
  id: string
  to: string
  subject: string
  type: string
  status: string
  created_at: string
}

export interface EmailLog {
  items: EmailMessage[]
  total: number
  provider: string
  failures: number
  can_send: boolean
}

// -- Website overview ----------------------------------------------------

export interface WebsitePage {
  id: string
  title: string
  url: string
  template: string
  status: string
  home: boolean
  children: number
}

export interface WebsiteOverview {
  items: WebsitePage[]
  total: number
  url: string
}

// -- Website content editor ----------------------------------------------

export interface WebsiteListItem {
  id: string
  title: string
  kind: 'site' | 'page'
  template: string
  status: string
  url: string
  home: boolean
}

export interface WebsiteIndex {
  items: WebsiteListItem[]
  total: number
  url: string
}

export interface WebsiteFieldOption {
  value: string
  label: string
}

export interface WebsiteField {
  name: string
  label: string
  type: string
  help: string
  width: string
  editable: boolean
  required?: boolean
  value?: string | boolean
  options?: WebsiteFieldOption[]
}

export interface WebsiteEditor {
  id: string
  title: string
  kind: 'site' | 'page'
  template: string
  status: string
  url: string
  has_changes: boolean
  can_unpublish: boolean
  fields: WebsiteField[]
  readonly: WebsiteField[]
}

// -- Invoicing -----------------------------------------------------------

export interface InvoiceItem {
  description: string
  quantity: number
  unit_price: number
  tax_rate: number
  discount: number
  line_total: number
}

export interface InvoicePayment {
  amount: number
  paid_on: string
  method: string
  reference: string
}

export interface InvoiceEvent {
  type: string
  detail: string
  at: string
}

export interface InvoiceListItem {
  id: string
  number: string
  status: string
  client: string
  project: string
  currency: string
  total: number
  amount_due: number
  issue_date: string
  due_date: string
  overdue: boolean
  created_at: string
}

export interface Invoice extends InvoiceListItem {
  contact_uuid: string
  company_uuid: string
  bill_to_email: string
  bill_to_address: string
  notes: string
  terms: string
  subtotal: number
  tax_total: number
  amount_paid: number
  seller_name: string
  payment_details: string
  public_url: string
  items: InvoiceItem[]
  payments: InvoicePayment[]
  events: InvoiceEvent[]
}

export interface InvoiceSettings {
  company_legal_name: string
  company_address: string
  company_email: string
  payment_details: string
  vat_registered: boolean
  vat_number: string
  default_vat_rate: number
  currency: string
  invoice_prefix: string
  default_terms_days: number
  default_notes: string
}

// -- Calendar ------------------------------------------------------------

export interface CalendarEvent {
  id: string
  title: string
  description: string
  starts_at: string
  ends_at: string
  all_day: boolean
  timezone: string
  location: string
  meeting_link: string
  event_type: string
  status: string
  contact_uuid: string
  company_uuid: string
  opportunity_uuid: string
  recurrence: string
  recurrence_interval: number
  recurrence_until: string
  recurrence_count: number
  reminder_minutes: number
  occurrence_date: string
  recurring: boolean
}

// -- Brevo integration settings ------------------------------------------

export interface BrevoConfig {
  enabled: boolean
  sender_name: string
  sender_email: string
  reply_to_name: string
  reply_to_email: string
  base_url: string
  marketing_list_id: string
  track_opens: boolean
  track_clicks: boolean
  contact_sync: boolean
}

export interface BrevoOverview {
  configured: boolean
  source: 'settings' | 'env' | 'none'
  key_hint: string | null
  config: BrevoConfig
  last_success: string
  last_failure: string
  env_managed: boolean
}

export interface BrevoTestStep {
  key: string
  label: string
  ok: boolean
  detail: string
}

export interface BrevoTestResult {
  ok: boolean
  steps: BrevoTestStep[]
}

// -- Hermes integration --------------------------------------------------

export type HermesStatus = 'healthy' | 'attention' | 'degraded' | 'misconfigured' | 'disabled'

export interface HermesActivityEntry {
  id: string
  at: string
  credential: string
  scope: string
  method: string
  endpoint: string
  result: string
  status: number
  request_id: string
  target_type?: string
  target_uuid?: string
  metadata?: Record<string, unknown>
}

export interface HermesOverview {
  enabled: boolean
  configured: boolean
  credential_count: number
  scope_count: number
  endpoint: string
  replay_window: number
  production: boolean
  status: HermesStatus
  last_success: string | null
  last_failure: string | null
  requests_24h: number
  failures_24h: number
  recent: HermesActivityEntry[]
}

export interface HermesCredential {
  id: string
  scopes: string[]
  scope_count: number
  managed: string
  status: string
  last_used: string | null
  last_result: string | null
  request_count: number
}

export interface HermesScope {
  scope: string
  label: string
  description: string
  risk: 'low' | 'medium' | 'high'
  kind: 'read' | 'write' | 'draft'
  granted_to: string[]
}

export interface HermesScopeGroup {
  domain: string
  scopes: HermesScope[]
}

export interface HermesScopes {
  groups: HermesScopeGroup[]
  total: number
}

export interface HermesHealth {
  status: HermesStatus
  enabled: boolean
  configured: boolean
  credentials: number
  audit_storage: boolean
  nonce_storage: boolean
  queue_pending: number
  queue_failed: number
  replay_window: number
  last_success: string | null
  last_failure: string | null
  requests_24h: number
  failures_24h: number
}

export interface HermesSettingRow {
  key: string
  label: string
  value: string
  note: string
}

export interface HermesSettings {
  deployment: HermesSettingRow[]
}

export interface HermesTestStep {
  key: string
  label: string
  ok: boolean
  detail: string
}

export interface HermesTestResult {
  ok: boolean
  credential: string
  steps: HermesTestStep[]
}

export interface HermesGeneratedCredential {
  id: string
  scopes: string[]
  rotating: boolean
  env_line: string
  instructions: string
}
