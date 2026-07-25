<script setup lang="ts">
import { onMounted, ref, computed, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { PortfolioListItem, PortfolioCounts } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const router = useRouter()
const canManage = computed(() => auth.can('portfolio.manage') || auth.can('admin'))
const canHomepage = computed(() => auth.can('portfolio.homepage') || auth.can('admin'))

const items = ref<PortfolioListItem[]>([])
const counts = ref<PortfolioCounts>({})
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref('')

// Status tabs mirror the server state machine. "all" excludes archived.
const TABS: Array<[string, string]> = [
  ['', 'All'],
  ['draft', 'Drafts'],
  ['internal_review', 'In review'],
  ['changes_requested', 'Changes'],
  ['ready', 'Ready'],
  ['scheduled', 'Scheduled'],
  ['published', 'Published'],
  ['unpublished', 'Unlisted'],
  ['archived', 'Archived'],
]

const shown = computed(() =>
  filter.value ? items.value.filter((p) => p.status === filter.value) : items.value,
)

function countFor(key: string): number {
  if (key === '') return counts.value.all ?? 0
  return counts.value[key] ?? 0
}

function when(d: string | null): string {
  if (!d) return '—'
  const t = new Date(d)
  return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

async function load() {
  loading.value = true
  error.value = null
  try {
    // Archived rows are only fetched when that tab is active, so the default
    // list stays focused on live editorial work.
    const q = filter.value === 'archived' ? '?include_archived=1' : ''
    const res = await api.get<{ items: PortfolioListItem[]; counts: PortfolioCounts }>('/portfolio' + q)
    items.value = res.items
    counts.value = res.counts
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Error'
  } finally {
    loading.value = false
  }
}

function selectTab(key: string) {
  filter.value = key
  // Archived needs a server round-trip (it's excluded by default).
  if (key === 'archived') load()
}

// ---- Create -----------------------------------------------------------------
const createOpen = ref(false)
const saving = ref(false)
const createErr = ref('')
const draft = reactive<{ internal_name: string; display_title: string; client_display_name: string }>({
  internal_name: '',
  display_title: '',
  client_display_name: '',
})

function newRecord() {
  Object.assign(draft, { internal_name: '', display_title: '', client_display_name: '' })
  createErr.value = ''
  createOpen.value = true
}

async function create() {
  if (saving.value || !draft.internal_name.trim()) return
  saving.value = true
  createErr.value = ''
  try {
    const rec = await api.post<{ uuid: string }>('/portfolio', {
      internal_name: draft.internal_name.trim(),
      display_title: draft.display_title.trim() || undefined,
      client_display_name: draft.client_display_name.trim() || undefined,
    })
    createOpen.value = false
    ui.toast('Case study created')
    router.push({ name: 'portfolio-record', params: { id: rec.uuid } })
  } catch (e) {
    createErr.value = e instanceof ApiError ? e.message : 'Could not create the case study.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="Showcase"
      title="Portfolio"
      sub="Real client work — from first draft to published case study."
    >
      <template #actions>
        <RouterLink v-if="canHomepage" class="btn btn--sm" :to="{ name: 'portfolio-homepage' }">
          Homepage selection
        </RouterLink>
        <button v-if="canManage" class="btn btn--sm btn--primary" data-test="portfolio-new" @click="newRecord">
          <NavIcon name="plus" /> New case study
        </button>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist" data-test="portfolio-tabs">
      <button
        v-for="[k, l] in TABS"
        :key="k"
        class="tab"
        :class="{ 'tab--active': filter === k }"
        role="tab"
        @click="selectTab(k)"
      >
        {{ l }}<span v-if="countFor(k)" class="tab__count">{{ countFor(k) }}</span>
      </button>
    </div>

    <DataState
      :loading="loading"
      :error="error"
      :empty="!shown.length"
      empty-title="No case studies here yet"
      empty-note="Create one from scratch, or import your existing project write-ups from the command line."
      @retry="load"
    >
      <div class="card list" data-test="portfolio-list">
        <button
          v-for="p in shown"
          :key="p.uuid"
          class="row"
          :data-test="'portfolio-row-' + p.slug"
          @click="router.push({ name: 'portfolio-record', params: { id: p.uuid } })"
        >
          <span class="row__name truncate">
            {{ p.display_title || p.internal_name || 'Untitled' }}
            <span v-if="p.homepage_position !== null" class="chip">Homepage</span>
          </span>
          <span class="row__client truncate faint">{{ p.client_display_name || '—' }}</span>
          <StatusPill :status="p.status" />
          <span class="row__date faint">{{ when(p.updated_at) }}</span>
        </button>
      </div>
    </DataState>

    <Sheet :open="createOpen" eyebrow="Showcase" title="New case study" @close="createOpen = false">
      <div class="field">
        <label class="label">Internal name</label>
        <input
          class="input"
          v-model="draft.internal_name"
          data-test="portfolio-internal-name"
          placeholder="e.g. Passo café rebuild (internal reference)"
        />
        <p class="hint">Only ever seen inside the studio — never published.</p>
      </div>
      <div class="field">
        <label class="label">Public project title (optional)</label>
        <input class="input" v-model="draft.display_title" placeholder="e.g. A faster site for a busy café" />
      </div>
      <div class="field">
        <label class="label">Client display name (optional)</label>
        <input class="input" v-model="draft.client_display_name" placeholder="e.g. Passo Coffee" />
      </div>
      <p v-if="createErr" class="cf__err" role="alert">{{ createErr }}</p>
      <template #footer>
        <button class="btn btn--sm" @click="createOpen = false">Cancel</button>
        <button
          class="btn btn--primary btn--sm"
          data-test="portfolio-create"
          :disabled="saving || !draft.internal_name.trim()"
          @click="create"
        >
          {{ saving ? 'Creating…' : 'Create case study' }}
        </button>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; display: inline-flex; align-items: center; gap: 6px; }
.tab:hover { color: var(--ink); }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.tab__count { background: var(--paper-2); color: var(--ink-3); font-size: 11px; font-weight: 700; border-radius: var(--r-pill); padding: 0 6px; min-width: 16px; text-align: center; }
.tab--active .tab__count { background: var(--purple-soft); color: var(--purple-ink); }
.list { padding: 6px; }
.row { display: grid; grid-template-columns: 1fr 200px auto 120px; align-items: center; gap: var(--sp-3); width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; }
.row:hover { background: var(--surface-2); }
.row + .row { border-top: 1px solid var(--line); }
.row__name { font-size: var(--text-base); min-width: 0; display: flex; align-items: center; gap: 8px; }
.row__client { font-size: var(--text-sm); min-width: 0; }
.row__date { font-size: var(--text-sm); white-space: nowrap; }
.chip { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; background: var(--butter); color: var(--ink); border-radius: var(--r-pill); padding: 1px 7px; }
.hint { font-size: var(--text-xs); color: var(--ink-3); margin-top: 4px; }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); margin-top: var(--sp-2); }
@media (max-width: 720px) { .row { grid-template-columns: 1fr auto; } .row__client, .row__date { display: none; } }
</style>
