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

export interface Dashboard {
  greeting: string
  date: string
  attention: DashboardAttention
  metrics: DashboardMetrics
  pipeline: PipelineStage[]
  recent: ActivityItem[]
  health: SystemHealth | null
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

export interface ContactDetail {
  contact: Contact
  timeline: Activity[]
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
