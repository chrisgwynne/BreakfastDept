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
