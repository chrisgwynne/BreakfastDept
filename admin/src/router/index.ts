import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuth } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
  {
    path: '/',
    component: () => import('@/components/AppShell.vue'),
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
      { path: 'leads', name: 'leads', component: () => import('@/views/LeadsView.vue') },
      { path: 'crm', name: 'crm', component: () => import('@/views/CrmView.vue') },
      { path: 'crm/contacts/:id', name: 'contact', component: () => import('@/views/ContactDetailView.vue') },
      { path: 'pipeline', name: 'pipeline', component: () => import('@/views/PipelineView.vue') },
      { path: 'tasks', name: 'tasks', component: () => import('@/views/TasksView.vue') },
      { path: 'previews', name: 'previews', component: () => import('@/views/PreviewsView.vue') },
      { path: 'email', name: 'email', component: () => import('@/views/EmailView.vue') },
      { path: 'website', name: 'website', component: () => import('@/views/WebsiteView.vue') },
      { path: 'reports', name: 'reports', component: () => import('@/views/ReportsView.vue') },
      { path: 'operations', name: 'operations', component: () => import('@/views/OperationsView.vue'), meta: { perm: 'admin' } },
      { path: 'hermes', name: 'hermes', component: () => import('@/views/HermesView.vue'), meta: { perm: 'hermes.view' } },
      { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory('/breakfast-admin/'),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (!auth.ready) await auth.bootstrap()
  if (to.meta.public) {
    return auth.isAuthed && to.name === 'login' ? { name: 'dashboard' } : true
  }
  if (!auth.isAuthed) return { name: 'login', query: { next: to.fullPath } }
  if (to.meta.perm && !auth.can(String(to.meta.perm))) return { name: 'dashboard' }
  return true
})
