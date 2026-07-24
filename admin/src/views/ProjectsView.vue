<script setup lang="ts">
import { onMounted, ref, computed, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { ProjectListItem } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const router = useRouter()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))

const items = ref<ProjectListItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref('')

const editorOpen = ref(false)
const saving = ref(false)
const editErr = ref('')
const draft = reactive<{ name: string; project_type: string; owner: string; target_date: string; quoted_value: string; proposal_uuid: string }>({
  name: '', project_type: '', owner: '', target_date: '', quoted_value: '', proposal_uuid: '',
})

const shown = computed(() => (filter.value ? items.value.filter((p) => p.status === filter.value) : items.value))

function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }
function when(d: string): string { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) }

async function load() {
  loading.value = true; error.value = null
  try { items.value = (await api.get<{ items: ProjectListItem[] }>('/projects')).items }
  catch (e) { error.value = e instanceof Error ? e.message : 'Error' }
  finally { loading.value = false }
}

function newProject() {
  Object.assign(draft, { name: '', project_type: '', owner: '', target_date: '', quoted_value: '', proposal_uuid: '' })
  editErr.value = ''
  editorOpen.value = true
}

async function save() {
  if (saving.value || !draft.name.trim()) return
  saving.value = true; editErr.value = ''
  try {
    const payload: Record<string, unknown> = {
      name: draft.name, project_type: draft.project_type, owner: draft.owner || undefined,
      target_date: draft.target_date || undefined, quoted_value: draft.quoted_value || '0',
    }
    if (draft.proposal_uuid.trim()) payload.proposal_uuid = draft.proposal_uuid.trim()
    const res = await api.post<{ project: { id: string } }>('/projects', payload)
    editorOpen.value = false
    ui.toast('Project created')
    router.push({ name: 'project', params: { id: res.project.id } })
  } catch (e) { editErr.value = e instanceof ApiError ? e.message : 'Could not create the project.' }
  finally { saving.value = false }
}

const FILTERS = [['', 'All'], ['planning', 'Planning'], ['onboarding', 'Onboarding'], ['active', 'Active'], ['review', 'Review'], ['ready_to_launch', 'Ready'], ['completed', 'Completed']]
onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Delivery" title="Projects" sub="The delivery workspace for every paid engagement.">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="newProject"><NavIcon name="plus" /> New project</button>
      </template>
    </PageHeader>

    <div class="tabs">
      <button v-for="[k, l] in FILTERS" :key="k" class="tab" :class="{ 'tab--active': filter === k }" @click="filter = String(k)">{{ l }}</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length"
               empty-title="No projects yet" empty-note="Create a project from an accepted proposal, a signed contract, or manually." @retry="load">
      <div class="card list">
        <button v-for="p in shown" :key="p.id" class="row" @click="router.push({ name: 'project', params: { id: p.id } })">
          <span class="row__num mono">{{ p.number }}</span>
          <span class="row__name truncate">{{ p.name }}</span>
          <StatusPill :status="p.status" />
          <span class="row__health" :class="'health--' + p.health">{{ p.health.replace('_', ' ') }}</span>
          <span class="row__val">{{ money(p.quoted_value) }}</span>
          <span class="row__date faint">{{ when(p.target_date) }}</span>
        </button>
      </div>
    </DataState>

    <Sheet :open="editorOpen" eyebrow="Delivery" title="New project" @close="editorOpen = false">
      <div class="field"><label class="label">Project name</label><input class="input" v-model="draft.name" placeholder="e.g. Roberts Cafe website" /></div>
      <div class="cf__row">
        <div class="field"><label class="label">Type</label><input class="input" v-model="draft.project_type" placeholder="brochure, ecommerce…" /></div>
        <div class="field"><label class="label">Owner (email)</label><input class="input" v-model="draft.owner" type="email" /></div>
      </div>
      <div class="cf__row">
        <div class="field"><label class="label">Target date</label><input class="input" v-model="draft.target_date" type="date" /></div>
        <div class="field"><label class="label">Quoted value (£)</label><input class="input" v-model="draft.quoted_value" inputmode="decimal" /></div>
      </div>
      <div class="field"><label class="label">From accepted proposal (optional UUID)</label><input class="input mono" v-model="draft.proposal_uuid" placeholder="Leave blank for a manual project" /></div>
      <p v-if="editErr" class="cf__err" role="alert">{{ editErr }}</p>
      <template #footer>
        <button class="btn btn--sm" @click="editorOpen = false">Cancel</button>
        <button class="btn btn--primary btn--sm" :disabled="saving || !draft.name.trim()" @click="save">{{ saving ? 'Creating…' : 'Create project' }}</button>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); } .tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.mono { font-family: var(--font-mono); font-size: 0.92em; }
.list { padding: 6px; }
.row { display: grid; grid-template-columns: 120px 1fr auto auto auto auto; align-items: center; gap: var(--sp-3); width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; }
.row:hover { background: var(--surface-2); } .row + .row { border-top: 1px solid var(--line); }
.row__num { font-size: var(--text-sm); font-weight: 600; }
.row__name { font-size: var(--text-base); min-width: 0; }
.row__health { font-size: var(--text-xs); text-transform: capitalize; color: var(--ink-3); }
.health--at_risk { color: #9a6a00; } .health--off_track { color: var(--danger-ink); }
.row__val { font-weight: 600; white-space: nowrap; } .row__date { font-size: var(--text-sm); white-space: nowrap; }
.cf__row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); margin-top: var(--sp-2); }
@media (max-width: 720px) { .row { grid-template-columns: 1fr auto auto; } .row__num, .row__health, .row__date { display: none; } .cf__row { grid-template-columns: 1fr; } }
</style>
