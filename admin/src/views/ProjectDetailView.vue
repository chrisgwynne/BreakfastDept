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

const tab = ref<'overview' | 'milestones' | 'board' | 'onboarding' | 'files' | 'changes' | 'access'>('overview')
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
async function switchTab(t: 'overview' | 'milestones' | 'board' | 'onboarding' | 'files' | 'changes' | 'access') {
  tab.value = t
  if (t === 'milestones') await loadMilestones()
  if (t === 'board') await loadBoard()
  if (t === 'onboarding') await loadOnboarding()
  if (t === 'files') await loadFiles()
  if (t === 'changes') await loadChangeRequests()
  if (t === 'access') await loadPortalAccess()
}

// --- Client portal access ---
interface PortalIdentity { uuid: string; email: string; display_name: string; status: string; last_login_at: string | null }
const portalIdentities = ref<PortalIdentity[]>([])
const portalEmail = ref('')
const portalName = ref('')
const portalBusy = ref('')
const portalLink = ref('')
async function loadPortalAccess() {
  if (!project.value) return
  portalIdentities.value = (await api.get<{ items: PortalIdentity[] }>(`/portal?project=${project.value.id}`)).items
}
async function inviteClient() {
  if (!project.value || !portalEmail.value.trim()) return
  portalBusy.value = 'invite'
  try {
    const res = await api.post<{ url: string }>('/portal/invite', { project_uuid: project.value.id, email: portalEmail.value.trim(), display_name: portalName.value.trim(), role: 'viewer' })
    portalLink.value = res.url
    portalEmail.value = ''; portalName.value = ''
    await loadPortalAccess(); ui.toast('Client invited — sign-in link ready')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not invite client') }
  finally { portalBusy.value = '' }
}
async function revokeClient(idn: PortalIdentity) {
  if (!project.value) return
  portalBusy.value = idn.uuid
  try { await api.post(`/portal/${idn.uuid}/revoke`, { entity_type: 'project', entity_uuid: project.value.id }); await loadPortalAccess(); ui.toast('Access revoked') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not revoke') }
  finally { portalBusy.value = '' }
}
async function newClientLink(idn: PortalIdentity) {
  portalBusy.value = idn.uuid
  try { portalLink.value = (await api.post<{ url: string }>(`/portal/${idn.uuid}/magic-link`, {})).url; ui.toast('Fresh sign-in link ready') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not mint link') }
  finally { portalBusy.value = '' }
}

// --- Change requests ---
interface CrItem { kind: string; description: string; quantity: number; unit_price: number; line_total: number; estimate_hours: number }
interface ChangeRequest {
  uuid: string; number: string; title: string; status: string; total: number; subtotal: number; tax_total: number
  applied: boolean; document_status: string; has_document: boolean; download_url: string; public_url: string
  description: string; items: CrItem[]; revision: number
  acceptances: { decision: string; name: string; approved_total: number; created_at: string }[]
}
const changeRequests = ref<ChangeRequest[]>([])
const crBusy = ref('')
const crDraft = ref<{ title: string; description: string; items: { description: string; unit_price: string; estimate_hours: string; kind: string }[] }>({ title: '', description: '', items: [{ description: '', unit_price: '', estimate_hours: '', kind: 'fixed' }] })
const crExpanded = ref<string | null>(null)
async function loadChangeRequests() {
  if (!project.value) return
  changeRequests.value = (await api.get<{ items: ChangeRequest[] }>(`/change-requests?project=${project.value.id}`)).items
}
function crAddRow() { crDraft.value.items.push({ description: '', unit_price: '', estimate_hours: '', kind: 'fixed' }) }
function crMoney(n: number): string { return '£' + n.toFixed(2) }
async function createChangeRequest() {
  if (!project.value || !crDraft.value.title.trim()) return
  crBusy.value = 'create'
  try {
    const items = crDraft.value.items
      .filter((i) => i.description.trim() || i.unit_price)
      .map((i) => ({ description: i.description.trim(), unit_price: Number(i.unit_price) || 0, quantity: 1, kind: i.kind, estimate_hours: Number(i.estimate_hours) || 0 }))
    await api.post('/change-requests', { project_uuid: project.value.id, title: crDraft.value.title.trim(), description: crDraft.value.description.trim(), items })
    crDraft.value = { title: '', description: '', items: [{ description: '', unit_price: '', estimate_hours: '', kind: 'fixed' }] }
    await loadChangeRequests(); ui.toast('Change request drafted')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not create change request') }
  finally { crBusy.value = '' }
}
async function sendChangeRequest(cr: ChangeRequest) {
  crBusy.value = cr.uuid
  try {
    const res = await api.post<{ url: string }>(`/change-requests/${cr.uuid}/send`, {})
    await loadChangeRequests(); ui.toast('Sent — client link ready')
    crExpanded.value = cr.uuid
    void res
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not send') }
  finally { crBusy.value = '' }
}
async function recordDecision(cr: ChangeRequest, decision: string) {
  crBusy.value = cr.uuid
  try { await api.post(`/change-requests/${cr.uuid}/decision`, { decision }); await loadChangeRequests(); ui.toast('Decision recorded') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not record decision') }
  finally { crBusy.value = '' }
}
async function applyChangeRequest(cr: ChangeRequest) {
  if (!confirm(`Apply ${cr.number} to the project? This adds ${crMoney(cr.total)} of approved variations, generates tasks and drafts an invoice.`)) return
  crBusy.value = cr.uuid
  try {
    const res = await api.post<{ result: { tasks: number; invoice: string | null } }>(`/change-requests/${cr.uuid}/apply`, {})
    await loadChangeRequests(); await reloadProject()
    ui.toast(`Applied: ${res.result.tasks} task(s)${res.result.invoice ? ', invoice drafted' : ''}`)
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not apply') }
  finally { crBusy.value = '' }
}

// --- Files ---
interface ProjectFile { id: string; display_name: string; category: string; current_version: number; immutable: boolean; reference_count: number; extension: string; byte_size: number; thumb_state: string }
const files = ref<ProjectFile[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
async function loadFiles() {
  if (!project.value) return
  files.value = (await api.get<{ items: ProjectFile[] }>(`/files?project=${project.value.id}`)).items
}
function pickFile() { fileInput.value?.click() }
async function onFilePicked(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !project.value) return
  uploading.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('project_uuid', project.value.id)
    form.append('entity_type', 'project')
    form.append('entity_uuid', project.value.id)
    form.append('display_name', file.name)
    await api.upload('/files', form)
    ui.toast('File uploaded')
    await loadFiles()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Upload failed') }
  finally { uploading.value = false; input.value = '' }
}
function fileDownload(f: ProjectFile): string { return `/breakfast-admin/files/${f.id}/download` }
async function archiveFile(f: ProjectFile) {
  try { await api.post(`/files/${f.id}/archive`, {}); await loadFiles(); ui.toast('Archived') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not archive') }
}
function fileSize(n: number): string { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB' }

// --- Onboarding ---
interface OnbReview { uuid: string; question_key: string; target: string; existing_value: string; submitted_value: string; decision: string }
interface OnbInstance { uuid: string; status: string; readiness: { ready: boolean; blockers: string[]; percent: number }; reviews: OnbReview[] }
interface OnbTemplate { uuid: string; name: string; current_version: number }
const onbInstances = ref<OnbInstance[]>([])
const onbTemplates = ref<OnbTemplate[]>([])
const onbTemplate = ref('')
const onbBusy = ref('')
const onbLink = ref('')
async function loadOnboarding() {
  if (!project.value) return
  onbInstances.value = (await api.get<{ items: OnbInstance[] }>(`/projects/${project.value.id}/onboarding`)).items
  if (!onbTemplates.value.length) {
    onbTemplates.value = (await api.get<{ items: OnbTemplate[] }>('/onboarding-templates')).items.filter((t) => t.current_version > 0)
  }
}
async function createOnboarding() {
  if (!project.value || !onbTemplate.value) return
  onbBusy.value = 'create'
  try { await api.post(`/projects/${project.value.id}/onboarding`, { template_uuid: onbTemplate.value }); await loadOnboarding(); ui.toast('Onboarding created') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not create onboarding') }
  finally { onbBusy.value = '' }
}
async function inviteOnboarding(inst: OnbInstance) {
  onbBusy.value = inst.uuid
  const email = window.prompt('Client email for the onboarding invitation?') || ''
  if (!email.trim()) { onbBusy.value = ''; return }
  try {
    const res = await api.post<{ url: string }>(`/onboarding/${inst.uuid}/invite`, { email: email.trim(), expires_days: 14 })
    onbLink.value = res.url
    await loadOnboarding(); ui.toast('Invitation sent')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not send invite') }
  finally { onbBusy.value = '' }
}
async function decideMapping(review: OnbReview, decision: string) {
  try { await api.post('/onboarding/mapping/decide', { review: review.uuid, decision }); await loadOnboarding() }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not decide') }
}
async function onbAction(inst: OnbInstance, action: string) {
  onbBusy.value = inst.uuid + action
  try { await api.post(`/onboarding/${inst.uuid}/${action}`, {}); await loadOnboarding(); await reloadProject(); ui.toast('Done') }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Action failed') }
  finally { onbBusy.value = '' }
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

// Apply a template (generates milestones + tasks with resolved dates).
type Template = { uuid: string; name: string; current_version: number }
const templates = ref<Template[]>([])
const chosenTemplate = ref('')
const applying = ref(false)
async function loadTemplates() {
  if (templates.value.length) return
  templates.value = (await api.get<{ items: Template[] }>('/project-templates')).items.filter((t) => t.current_version > 0)
}
async function applyTemplate() {
  if (!project.value || !chosenTemplate.value || applying.value) return
  applying.value = true
  try {
    const res = await api.post<{ applied: { milestones: number; tasks: number } }>(`/projects/${project.value.id}/apply-template`, { template_uuid: chosenTemplate.value })
    ui.toast(`Applied: ${res.applied.milestones} milestones, ${res.applied.tasks} tasks`)
    await reloadProject()
    if (tab.value === 'milestones') await loadMilestones()
    if (tab.value === 'board') await loadBoard()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not apply the template') }
  finally { applying.value = false }
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
          <button class="tab" :class="{ 'tab--active': tab === 'onboarding' }" role="tab" @click="switchTab('onboarding')">Onboarding</button>
          <button class="tab" :class="{ 'tab--active': tab === 'files' }" role="tab" @click="switchTab('files')">Files</button>
          <button class="tab" :class="{ 'tab--active': tab === 'changes' }" role="tab" @click="switchTab('changes')">Change requests</button>
          <button class="tab" :class="{ 'tab--active': tab === 'access' }" role="tab" @click="switchTab('access')">Client access</button>
        </div>

        <!-- Files -->
        <section v-if="tab === 'files'" class="card card--pad" data-test="project-files">
          <div v-if="canManage" class="quickadd">
            <input ref="fileInput" type="file" hidden data-test="file-input" @change="onFilePicked" />
            <button class="btn btn--sm btn--primary" data-test="file-upload" :disabled="uploading" @click="pickFile">{{ uploading ? 'Uploading…' : 'Upload file' }}</button>
          </div>
          <div class="card list">
            <div v-for="f in files" :key="f.id" class="frow" :data-test="'file-' + f.category">
              <img v-if="f.thumb_state === 'ready'" class="fthumb" :src="`/breakfast-admin/files/${f.id}/thumb`" alt="" />
              <span v-else class="fthumb fthumb--none">{{ f.extension.toUpperCase() }}</span>
              <span class="fname truncate">{{ f.display_name }}<span v-if="f.immutable" class="chip">locked</span></span>
              <span class="fmeta faint">v{{ f.current_version }} · {{ fileSize(f.byte_size) }}<span v-if="f.reference_count"> · {{ f.reference_count }} link(s)</span></span>
              <span class="factions">
                <a class="pdflink" :href="fileDownload(f)" target="_blank" rel="noopener" data-test="file-download">Download</a>
                <button v-if="canManage && !f.immutable" class="btn btn--sm" data-test="file-archive" @click="archiveFile(f)">Archive</button>
              </span>
            </div>
            <p v-if="!files.length" class="faint" style="padding:var(--sp-3)">No files yet.</p>
          </div>
        </section>

        <!-- Onboarding -->
        <section v-if="tab === 'onboarding'" class="card card--pad" data-test="project-onboarding">
          <div v-if="canManage" class="quickadd">
            <select class="input" v-model="onbTemplate" data-test="onboarding-template">
              <option value="">Choose an onboarding template…</option>
              <option v-for="t in onbTemplates" :key="t.uuid" :value="t.uuid">{{ t.name }}</option>
            </select>
            <button class="btn btn--sm btn--primary" data-test="onboarding-create" :disabled="!onbTemplate || onbBusy === 'create'" @click="createOnboarding">Create</button>
          </div>
          <div v-if="onbLink" class="paylink"><input class="input mono" :value="onbLink" readonly @focus="($event.target as HTMLInputElement).select()" /></div>
          <div v-for="inst in onbInstances" :key="inst.uuid" class="onb">
            <div class="onb__head">
              <StatusPill :status="inst.status" />
              <span class="faint">{{ inst.readiness.percent }}% complete</span>
              <span v-if="!inst.readiness.ready" class="chip chip--warn">{{ inst.readiness.blockers.join('; ') }}</span>
              <span class="onb__actions" v-if="canManage">
                <button v-if="['draft','ready','viewed','invited','in_progress','needs_clarification'].includes(inst.status)" class="btn btn--sm" data-test="onboarding-invite" :disabled="onbBusy === inst.uuid" @click="inviteOnboarding(inst)">Send invite</button>
                <button v-if="inst.status === 'submitted'" class="btn btn--sm" @click="onbAction(inst, 'review')">Start review</button>
                <button v-if="['submitted','under_review'].includes(inst.status)" class="btn btn--sm btn--primary" data-test="onboarding-complete" :disabled="!inst.readiness.ready" @click="onbAction(inst, 'complete')">Complete</button>
              </span>
            </div>
            <div v-if="inst.reviews.filter((r) => r.decision === 'pending').length" class="onb__reviews">
              <p class="label">Mapping conflicts — review before overwriting</p>
              <div v-for="r in inst.reviews.filter((x) => x.decision === 'pending')" :key="r.uuid" class="onb__review" :data-test="'mapping-' + r.target">
                <span class="truncate">{{ r.target }}: <s>{{ r.existing_value }}</s> → {{ r.submitted_value }}</span>
                <span class="onb__decide">
                  <button class="btn btn--sm" data-test="mapping-accept" @click="decideMapping(r, 'accepted')">Accept</button>
                  <button class="btn btn--sm" @click="decideMapping(r, 'rejected')">Reject</button>
                </span>
              </div>
            </div>
          </div>
          <p v-if="!onbInstances.length" class="faint">No onboarding started yet.</p>
        </section>

        <!-- Change requests -->
        <section v-if="tab === 'changes'" class="card card--pad" data-test="project-change-requests">
          <form v-if="canManage" class="crnew" data-test="cr-new" @submit.prevent="createChangeRequest">
            <input class="input" v-model="crDraft.title" data-test="cr-title" placeholder="Change request title (e.g. Add a bilingual blog)" />
            <textarea class="input" v-model="crDraft.description" data-test="cr-description" placeholder="What is changing and why?"></textarea>
            <div v-for="(row, i) in crDraft.items" :key="i" class="crnew__item">
              <input class="input" v-model="row.description" :data-test="'cr-item-desc-' + i" placeholder="Line item" />
              <input class="input input--sm" v-model="row.unit_price" :data-test="'cr-item-price-' + i" type="number" step="0.01" min="0" placeholder="£" />
              <select class="input input--sm" v-model="row.kind">
                <option value="fixed">Fixed</option>
                <option value="optional">Optional</option>
              </select>
            </div>
            <div class="crnew__actions">
              <button type="button" class="btn btn--sm" data-test="cr-add-row" @click="crAddRow">Add line</button>
              <button type="submit" class="btn btn--sm btn--primary" data-test="cr-create" :disabled="!crDraft.title.trim() || crBusy === 'create'">Create draft</button>
            </div>
          </form>

          <div class="card list">
            <div v-for="cr in changeRequests" :key="cr.uuid" class="crrow" :data-test="'cr-' + cr.status">
              <div class="crrow__head" @click="crExpanded = crExpanded === cr.uuid ? null : cr.uuid">
                <StatusPill :status="cr.status" />
                <span class="crrow__title truncate">{{ cr.number || 'Draft' }} · {{ cr.title }}</span>
                <span class="crrow__total">{{ crMoney(cr.total) }}</span>
                <span v-if="cr.applied" class="chip">applied</span>
              </div>
              <div v-if="crExpanded === cr.uuid" class="crrow__body">
                <p v-if="cr.description" class="faint">{{ cr.description }}</p>
                <table class="crtable">
                  <tr v-for="(it, k) in cr.items" :key="k">
                    <td>{{ it.description }}<span v-if="it.kind === 'optional'" class="faint"> (optional)</span></td>
                    <td class="crtable__amt">{{ crMoney(it.line_total) }}</td>
                  </tr>
                </table>
                <p v-if="cr.acceptances.length" class="faint" data-test="cr-acceptance">
                  {{ cr.acceptances[0].decision }} by {{ cr.acceptances[0].name }} · {{ crMoney(cr.acceptances[0].approved_total) }}
                </p>
                <div v-if="cr.public_url" class="paylink"><input class="input mono" :value="cr.public_url" readonly data-test="cr-public-url" @focus="($event.target as HTMLInputElement).select()" /></div>
                <div class="crrow__actions" v-if="canManage">
                  <button v-if="['draft','internal_review','ready'].includes(cr.status)" class="btn btn--sm btn--primary" data-test="cr-send" :disabled="crBusy === cr.uuid" @click="sendChangeRequest(cr)">Send to client</button>
                  <button v-if="['sent','viewed'].includes(cr.status)" class="btn btn--sm" data-test="cr-record-approve" :disabled="crBusy === cr.uuid" @click="recordDecision(cr, 'approved')">Record approval</button>
                  <button v-if="cr.status === 'approved' && !cr.applied && auth.can('admin')" class="btn btn--sm btn--primary" data-test="cr-apply" :disabled="crBusy === cr.uuid" @click="applyChangeRequest(cr)">Apply to project</button>
                  <a v-if="cr.has_document" class="pdflink" :href="cr.download_url" target="_blank" rel="noopener" data-test="cr-download">Download PDF</a>
                </div>
              </div>
            </div>
            <p v-if="!changeRequests.length" class="faint" style="padding:var(--sp-3)">No change requests yet.</p>
          </div>
        </section>

        <!-- Client portal access -->
        <section v-if="tab === 'access'" class="card card--pad" data-test="project-access">
          <p class="faint" style="margin-top:0">Give a client passwordless access to this project’s portal. They sign in with a single-use email link — no password, and they only ever see projects you grant.</p>
          <form v-if="canManage" class="quickadd" data-test="access-invite" @submit.prevent="inviteClient">
            <input class="input" v-model="portalEmail" data-test="access-email" type="email" placeholder="client@example.com" />
            <input class="input" v-model="portalName" data-test="access-name" placeholder="Client name (optional)" />
            <button class="btn btn--sm btn--primary" type="submit" data-test="access-add" :disabled="!portalEmail.trim() || portalBusy === 'invite'">Invite</button>
          </form>
          <div v-if="portalLink" class="paylink"><input class="input mono" :value="portalLink" readonly data-test="access-link" @focus="($event.target as HTMLInputElement).select()" /></div>
          <div class="card list">
            <div v-for="idn in portalIdentities" :key="idn.uuid" class="frow" data-test="access-row">
              <span class="fname truncate">{{ idn.display_name || idn.email }}<span class="chip">{{ idn.status }}</span></span>
              <span class="fmeta faint">{{ idn.last_login_at ? 'last in ' + idn.last_login_at.slice(0, 10) : 'never signed in' }}</span>
              <span class="factions" v-if="canManage">
                <button class="btn btn--sm" data-test="access-link-btn" :disabled="portalBusy === idn.uuid" @click="newClientLink(idn)">Sign-in link</button>
                <button class="btn btn--sm" data-test="access-revoke" :disabled="portalBusy === idn.uuid" @click="revokeClient(idn)">Revoke</button>
              </span>
            </div>
            <p v-if="!portalIdentities.length" class="faint" style="padding:var(--sp-3)">No client access yet.</p>
          </div>
        </section>

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

            <div v-if="canManage && !project.template_uuid" class="applytpl">
              <label class="sr">Template</label>
              <select class="input input--sm" v-model="chosenTemplate" data-test="template-select" @focus="loadTemplates">
                <option value="">Apply a template…</option>
                <option v-for="t in templates" :key="t.uuid" :value="t.uuid">{{ t.name }}</option>
              </select>
              <button class="btn btn--sm" data-test="apply-template" :disabled="!chosenTemplate || applying" @click="applyTemplate">{{ applying ? 'Applying…' : 'Apply' }}</button>
            </div>

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
.applytpl { display: flex; gap: var(--sp-2); align-items: center; margin-top: var(--sp-4); padding-top: var(--sp-4); border-top: 1px solid var(--line); }
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
.paylink { margin: var(--sp-2) 0; } .paylink .input { width: 100%; font-size: var(--text-xs); }
.onb { border-top: 1px solid var(--line); padding: var(--sp-3) 0; display: grid; gap: var(--sp-2); }
.onb__head { display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap; }
.onb__actions { margin-left: auto; display: flex; gap: var(--sp-2); }
.onb__reviews { background: var(--paper-2); border-radius: var(--r-md); padding: var(--sp-2) var(--sp-3); display: grid; gap: 6px; }
.onb__review { display: flex; justify-content: space-between; gap: var(--sp-2); font-size: var(--text-sm); align-items: center; }
.onb__decide { display: inline-flex; gap: 6px; }
.frow { display: grid; grid-template-columns: 40px 1fr auto auto; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); }
.frow + .frow { border-top: 1px solid var(--line); }
.fthumb { width: 40px; height: 40px; border-radius: var(--r-sm); object-fit: cover; background: var(--paper-2); display: grid; place-items: center; font-size: 10px; color: var(--ink-3); font-weight: 700; }
.fname { font-size: var(--text-sm); display: flex; align-items: center; gap: 6px; }
.fmeta { font-size: var(--text-xs); white-space: nowrap; }
.factions { display: inline-flex; gap: var(--sp-2); align-items: center; }
.chip { font-size: 10px; padding: 1px 6px; border-radius: var(--r-pill); background: var(--paper-2); color: var(--ink-3); }
.crnew { display: flex; flex-direction: column; gap: var(--sp-2); margin-bottom: var(--sp-3); }
.crnew__item { display: grid; grid-template-columns: 1fr 120px 120px; gap: var(--sp-2); }
.crnew__actions { display: flex; gap: var(--sp-2); justify-content: flex-end; }
.crrow + .crrow { border-top: 1px solid var(--line); }
.crrow__head { display: flex; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); cursor: pointer; }
.crrow__title { flex: 1; font-size: var(--text-sm); }
.crrow__total { font-weight: 700; white-space: nowrap; }
.crrow__body { padding: 0 var(--sp-3) var(--sp-3); display: flex; flex-direction: column; gap: var(--sp-2); }
.crrow__actions { display: flex; gap: var(--sp-2); align-items: center; flex-wrap: wrap; }
.crtable { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.crtable td { padding: 4px 0; border-bottom: 1px solid var(--line); }
.crtable__amt { text-align: right; white-space: nowrap; }
@media (max-width: 820px) { .cols { grid-template-columns: 1fr; } .grid2 { grid-template-columns: 1fr; } .crnew__item { grid-template-columns: 1fr; } }
</style>
