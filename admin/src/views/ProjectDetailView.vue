<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { Project } from '@/lib/types'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import NavIcon from '@/components/NavIcon.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))

const project = ref<Project | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref('')

const tab = ref<'overview' | 'milestones' | 'board'>('overview')
type Milestone = { uuid: string; title: string; status: string; progress_percent: number; due_date: string; is_ready: boolean; blocked_by: string[] }
type Task = { uuid: string; title: string; status: string; milestone_uuid: string | null; is_ready: boolean; revision: number }
const milestones = ref<Milestone[]>([])
const board = ref<Record<string, Task[]>>({})
const newMilestone = ref('')
const newTask = ref('')
const BOARD_COLUMNS = ['backlog', 'ready', 'in_progress', 'awaiting_client', 'blocked', 'review', 'completed']

async function loadMilestones() {
  if (!project.value) return
  milestones.value = (await api.get<{ items: Milestone[] }>(`/projects/${project.value.id}/milestones`)).items
}
async function loadBoard() {
  if (!project.value) return
  board.value = (await api.get<{ columns: Record<string, Task[]> }>(`/projects/${project.value.id}/board`)).columns
}
async function switchTab(t: 'overview' | 'milestones' | 'board') {
  tab.value = t
  if (t === 'milestones') await loadMilestones()
  if (t === 'board') await loadBoard()
}
async function addMilestone() {
  if (!project.value || !newMilestone.value.trim()) return
  try {
    await api.post(`/projects/${project.value.id}/milestones`, { title: newMilestone.value.trim() })
    newMilestone.value = ''
    await loadMilestones(); await reloadProject()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not add milestone') }
}
async function setMilestoneStatus(m: Milestone, status: string) {
  try { await api.post(`/milestones/${m.uuid}/status`, { status }); await loadMilestones(); await reloadProject() }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not update milestone') }
}
async function addTask() {
  if (!project.value || !newTask.value.trim()) return
  try {
    await api.post(`/projects/${project.value.id}/tasks`, { title: newTask.value.trim() })
    newTask.value = ''
    await loadBoard(); await reloadProject()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not add task') }
}
async function moveTask(t: Task, status: string) {
  try { await api.post(`/project-tasks/${t.uuid}/move`, { status, revision: t.revision }); await loadBoard(); await reloadProject() }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Move rejected') }
}
async function reloadProject() {
  if (!project.value) return
  project.value = (await api.get<{ project: Project }>(`/projects/${project.value.id}`)).project
}

// Mirrors the server state machine so only valid moves are offered.
const TRANSITIONS: Record<string, string[]> = {
  draft: ['planning', 'onboarding', 'active', 'cancelled'],
  planning: ['onboarding', 'active', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
  onboarding: ['planning', 'active', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
  active: ['review', 'ready_to_launch', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
  review: ['active', 'ready_to_launch', 'awaiting_client', 'blocked', 'cancelled'],
  ready_to_launch: ['active', 'review', 'completed', 'awaiting_client', 'blocked', 'cancelled'],
  awaiting_client: ['planning', 'onboarding', 'active', 'review', 'blocked', 'paused', 'cancelled'],
  blocked: ['planning', 'onboarding', 'active', 'review', 'awaiting_client', 'cancelled'],
  paused: ['planning', 'active', 'awaiting_client', 'cancelled'],
  completed: [],
  cancelled: [],
}
const nextStates = computed(() => (project.value ? TRANSITIONS[project.value.status] ?? [] : []))

function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function when(d: string): string { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) }
function label(s: string): string { return s.replace(/_/g, ' ') }
function hrs(sec: number): string { return sec > 0 ? (sec / 3600).toFixed(1) + 'h' : '—' }

async function load() {
  loading.value = true; error.value = null
  try { project.value = (await api.get<{ project: Project }>(`/projects/${route.params.id}`)).project }
  catch (e) { error.value = e instanceof ApiError ? e.message : 'Could not load the project.' }
  finally { loading.value = false }
}

async function setStatus(to: string) {
  if (!project.value || busy.value) return
  let reason: string | undefined
  if (to === 'cancelled' || to === 'blocked') {
    reason = window.prompt(to === 'cancelled' ? 'Reason for cancelling this project?' : 'What is blocking this project?') || ''
    if (!reason.trim()) return
  }
  busy.value = to
  try {
    project.value = (await api.post<{ project: Project }>(`/projects/${project.value.id}/status`, { status: to, reason })).project
    ui.toast('Status updated')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not change status') }
  finally { busy.value = '' }
}

async function act(action: string) {
  if (!project.value || busy.value) return
  busy.value = action
  try {
    project.value = (await api.post<{ project: Project }>(`/projects/${project.value.id}/${action}`, {})).project
    ui.toast('Done')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Action failed') }
  finally { busy.value = '' }
}

onMounted(load)
</script>

<template>
  <div>
    <button class="back" @click="router.push({ name: 'projects' })"><NavIcon name="chevron-left" /> Projects</button>

    <DataState :loading="loading" :error="error" :empty="false" @retry="load">
      <template v-if="project">
        <header class="phead">
          <div>
            <div class="phead__eyebrow mono">{{ project.number }}</div>
            <h1 class="phead__title">{{ project.name }}</h1>
          </div>
          <div class="phead__badges">
            <StatusPill :status="project.status" />
            <span class="chip" :class="'health--' + project.health">{{ label(project.health) }}</span>
            <span class="chip">{{ project.progress_percent ?? 0 }}% done</span>
          </div>
        </header>

        <div class="tabs" role="tablist">
          <button class="tab" :class="{ 'tab--active': tab === 'overview' }" role="tab" @click="switchTab('overview')">Overview</button>
          <button class="tab" :class="{ 'tab--active': tab === 'milestones' }" role="tab" @click="switchTab('milestones')">Milestones</button>
          <button class="tab" :class="{ 'tab--active': tab === 'board' }" role="tab" @click="switchTab('board')">Board</button>
        </div>

        <!-- Milestones -->
        <section v-if="tab === 'milestones'" class="card card--pad">
          <div v-if="canManage" class="quickadd">
            <input class="input" v-model="newMilestone" data-test="new-milestone" placeholder="New milestone title" @keyup.enter="addMilestone" />
            <button class="btn btn--sm btn--primary" @click="addMilestone">Add</button>
          </div>
          <ul class="mslist">
            <li v-for="m in milestones" :key="m.uuid" class="ms">
              <div class="ms__main">
                <span class="ms__title">{{ m.title }}</span>
                <StatusPill :status="m.status" />
                <span v-if="!m.is_ready" class="chip chip--warn">blocked by dependency</span>
              </div>
              <div class="ms__bar"><span class="ms__fill" :style="{ width: m.progress_percent + '%' }"></span></div>
              <div v-if="canManage" class="ms__actions">
                <label class="sr">Status</label>
                <select class="input input--sm" :value="m.status" @change="setMilestoneStatus(m, ($event.target as HTMLSelectElement).value)">
                  <option v-for="s in ['not_started','active','awaiting_client','blocked','completed','cancelled']" :key="s" :value="s">{{ label(s) }}</option>
                </select>
              </div>
            </li>
            <li v-if="!milestones.length" class="faint">No milestones yet.</li>
          </ul>
        </section>

        <!-- Board -->
        <section v-else-if="tab === 'board'">
          <div v-if="canManage" class="quickadd card card--pad">
            <input class="input" v-model="newTask" data-test="new-task" placeholder="New task title" @keyup.enter="addTask" />
            <button class="btn btn--sm btn--primary" @click="addTask">Add task</button>
          </div>
          <div class="board">
            <div v-for="col in BOARD_COLUMNS" :key="col" class="bcol">
              <h3 class="bcol__h">{{ label(col) }} <span class="faint">{{ (board[col] || []).length }}</span></h3>
              <div v-for="t in board[col] || []" :key="t.uuid" class="tcard" :data-test="'task-' + col">
                <p class="tcard__title">{{ t.title }}</p>
                <span v-if="!t.is_ready" class="chip chip--warn">blocked</span>
                <select v-if="canManage" class="input input--sm" :value="t.status" @change="moveTask(t, ($event.target as HTMLSelectElement).value)" :aria-label="'Move ' + t.title">
                  <option v-for="s in ['backlog','ready','in_progress','awaiting_client','blocked','review','completed','cancelled']" :key="s" :value="s">{{ label(s) }}</option>
                </select>
              </div>
              <p v-if="!(board[col] || []).length" class="bcol__empty faint">—</p>
            </div>
          </div>
        </section>

        <div v-else class="cols">
          <!-- Overview -->
          <section class="card card--pad">
            <h2 class="sect">Overview</h2>
            <div class="grid2">
              <div class="stat"><span class="k">Quoted</span><span class="v">{{ money(project.quoted_value) }}</span></div>
              <div class="stat"><span class="k">Invoiced</span><span class="v">{{ money(project.invoiced_value) }}</span></div>
              <div class="stat"><span class="k">Paid</span><span class="v">{{ money(project.paid_value) }}</span></div>
              <div class="stat"><span class="k">Approved variations</span><span class="v">{{ money(project.approved_variations) }}</span></div>
              <div class="stat"><span class="k">Start</span><span class="v">{{ when(project.start_date) }}</span></div>
              <div class="stat"><span class="k">Target</span><span class="v">{{ when(project.target_date) }}</span></div>
              <div class="stat"><span class="k">Owner</span><span class="v">{{ project.owner || '—' }}</span></div>
              <div class="stat"><span class="k">Awaiting client</span><span class="v">{{ hrs(project.awaiting_seconds) }}</span></div>
            </div>
            <p v-if="project.status === 'blocked' && project.blocked_reason" class="reason">Blocked: {{ project.blocked_reason }}</p>
            <p v-if="project.status === 'cancelled' && project.cancel_reason" class="reason">Cancelled: {{ project.cancel_reason }}</p>
            <p v-if="project.client_summary" class="summary">{{ project.client_summary }}</p>

            <div v-if="canManage" class="actions">
              <button v-for="s in nextStates" :key="s" class="btn btn--sm" :class="{ 'btn--danger': s === 'cancelled', 'btn--primary': s === 'completed' || s === 'active' }" :disabled="busy === s" @click="setStatus(s)">
                {{ label(s) }}
              </button>
              <button v-if="project.status === 'completed'" class="btn btn--sm" :disabled="busy === 'reopen'" @click="act('reopen')">Reopen</button>
              <button v-if="!project.archived" class="btn btn--sm" :disabled="busy === 'archive'" @click="act('archive')">Archive</button>
              <button v-else class="btn btn--sm" :disabled="busy === 'restore'" @click="act('restore')">Restore</button>
            </div>
          </section>

          <!-- Team + links -->
          <section class="card card--pad">
            <h2 class="sect">Team</h2>
            <ul class="team">
              <li v-for="m in project.members" :key="m.user_email" class="member">
                <span class="member__email truncate">{{ m.user_email }}</span>
                <span class="member__role">{{ m.role }}</span>
              </li>
              <li v-if="!project.members.length" class="faint">No team members yet.</li>
            </ul>
            <h2 class="sect" style="margin-top:var(--sp-4)">Linked records</h2>
            <ul class="links">
              <li v-if="project.proposal_uuid">Proposal · <span class="mono">{{ project.proposal_uuid.slice(0, 8) }}</span></li>
              <li v-if="project.contract_uuid">Contract · <span class="mono">{{ project.contract_uuid.slice(0, 8) }}</span></li>
              <li v-if="project.opportunity_uuid">Opportunity · <span class="mono">{{ project.opportunity_uuid.slice(0, 8) }}</span></li>
              <li v-if="!project.proposal_uuid && !project.contract_uuid && !project.opportunity_uuid" class="faint">Manual project (no commercial link).</li>
            </ul>
          </section>
        </div>

        <!-- Activity -->
        <section v-if="tab === 'overview'" class="card card--pad" style="margin-top:var(--sp-5)">
          <h2 class="sect">Activity</h2>
          <ol class="timeline">
            <li v-for="(e, i) in project.events" :key="i" class="tl">
              <span class="tl__dot" aria-hidden="true"></span>
              <div><p class="tl__detail">{{ e.detail }}</p><p class="tl__meta faint">{{ e.actor }} · {{ when(e.created_at) }}</p></div>
            </li>
          </ol>
        </section>
      </template>
    </DataState>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: 4px; font-size: var(--text-sm); color: var(--ink-3); margin-bottom: var(--sp-3); }
.back:hover { color: var(--ink); }
.mono { font-family: var(--font-mono); font-size: 0.9em; }
.phead { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--sp-4); margin-bottom: var(--sp-5); }
.phead__eyebrow { color: var(--ink-3); font-size: var(--text-xs); }
.phead__title { font-size: var(--text-2xl); font-weight: 650; }
.phead__badges { display: flex; gap: var(--sp-2); align-items: center; }
.chip { font-size: var(--text-xs); padding: 2px 10px; border-radius: var(--r-pill); background: var(--paper-2); text-transform: capitalize; }
.health--at_risk { background: #f9ecd9; color: #9a6a00; } .health--off_track { background: var(--danger-soft); color: var(--danger-ink); }
.cols { display: grid; grid-template-columns: 2fr 1fr; gap: var(--sp-5); align-items: start; }
.sect { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.stat { display: flex; flex-direction: column; gap: 2px; }
.stat .k { font-size: var(--text-xs); color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.03em; }
.stat .v { font-size: var(--text-base); font-weight: 600; }
.reason { margin-top: var(--sp-3); font-size: var(--text-sm); color: var(--danger-ink); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); }
.summary { margin-top: var(--sp-3); font-size: var(--text-sm); color: var(--ink-2); line-height: 1.6; }
.actions { display: flex; flex-wrap: wrap; gap: var(--sp-2); margin-top: var(--sp-4); padding-top: var(--sp-4); border-top: 1px solid var(--line); }
.team, .links { list-style: none; display: grid; gap: 6px; }
.member { display: flex; justify-content: space-between; gap: var(--sp-2); font-size: var(--text-sm); }
.member__role { color: var(--ink-3); text-transform: capitalize; }
.links li { font-size: var(--text-sm); }
.timeline { list-style: none; display: grid; gap: var(--sp-3); }
.tl { display: flex; gap: var(--sp-3); }
.tl__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--purple); margin-top: 6px; flex: none; }
.tl__detail { font-size: var(--text-sm); }
.tl__meta { font-size: var(--text-xs); }
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); } .tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.chip--warn { background: #f9ecd9; color: #9a6a00; }
.quickadd { display: flex; gap: var(--sp-2); margin-bottom: var(--sp-3); }
.quickadd .input { flex: 1; }
.input--sm { padding: 4px 8px; font-size: var(--text-sm); }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.mslist { list-style: none; display: grid; gap: var(--sp-3); }
.ms { display: grid; gap: 6px; padding-bottom: var(--sp-3); border-bottom: 1px solid var(--line); }
.ms__main { display: flex; align-items: center; gap: var(--sp-2); }
.ms__title { font-weight: 600; }
.ms__bar { height: 6px; background: var(--paper-2); border-radius: 99px; overflow: hidden; }
.ms__fill { display: block; height: 100%; background: var(--purple); }
.board { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(180px, 1fr); gap: var(--sp-3); overflow-x: auto; padding-bottom: var(--sp-2); }
.bcol { background: var(--paper-2); border-radius: var(--r-md); padding: var(--sp-2); display: grid; gap: var(--sp-2); align-content: start; }
.bcol__h { font-size: var(--text-xs); text-transform: capitalize; color: var(--ink-2); font-weight: 650; }
.tcard { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-sm); padding: var(--sp-2); display: grid; gap: 6px; }
.tcard__title { font-size: var(--text-sm); }
.bcol__empty { text-align: center; font-size: var(--text-xs); }
@media (max-width: 820px) { .cols { grid-template-columns: 1fr; } .grid2 { grid-template-columns: 1fr; } }
</style>
