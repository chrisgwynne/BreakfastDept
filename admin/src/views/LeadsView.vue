<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { api } from '@/lib/api'
import type { Enquiry, ListResponse } from '@/lib/types'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const ui = useUi()
const auth = useAuth()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
const items = ref<Enquiry[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref<string>('')
const selected = ref<Enquiry | null>(null)
// Reload real data whenever a lead is created anywhere in the app.
watch(() => ui.version.leads, () => load())

const FILTERS = [
  { key: '', label: 'All' },
  { key: 'new', label: 'New' },
  { key: 'triaged', label: 'Triaged' },
  { key: 'converted', label: 'Converted' },
  { key: 'spam', label: 'Spam' },
]

const shown = computed(() =>
  filter.value ? items.value.filter((e) => e.status === filter.value) : items.value)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get<ListResponse<Enquiry>>('/enquiries')
    items.value = res.items
    total.value = res.total
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

function when(iso: string): string {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Inbox" title="Leads" :sub="`${total} enquir${total === 1 ? 'y' : 'ies'} received`">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="ui.openCreate('lead')"><NavIcon name="plus" /> New lead</button>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist">
      <button v-for="f in FILTERS" :key="f.key" class="tab" :class="{ 'tab--active': filter === f.key }"
              role="tab" :aria-selected="filter === f.key" @click="filter = f.key">{{ f.label }}</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length"
               empty-title="No enquiries here" empty-note="New leads from the website land in this inbox."
               @retry="load">
      <div class="card list">
        <button v-for="e in shown" :key="e.id" class="lead" @click="selected = e">
          <div class="lead__main">
            <div class="lead__row">
              <strong class="lead__name">{{ e.name || 'Unknown sender' }}</strong>
              <StatusPill :status="e.status" />
            </div>
            <p class="lead__summary truncate">{{ e.summary || e.form_type || 'Enquiry' }}</p>
          </div>
          <div class="lead__meta">
            <span class="lead__company truncate">{{ e.company || e.email }}</span>
            <span class="faint">{{ when(e.created_at) }}</span>
          </div>
        </button>
      </div>
    </DataState>

    <Sheet :open="!!selected" :eyebrow="selected?.reference || 'Enquiry'" :title="selected?.name || 'Enquiry'"
           @close="selected = null">
      <template v-if="selected">
        <div class="detail">
          <div class="detail__row"><span class="detail__k">Status</span><StatusPill :status="selected.status" /></div>
          <div class="detail__row"><span class="detail__k">Form</span><span>{{ selected.form_type || '—' }}</span></div>
          <div class="detail__row"><span class="detail__k">Email</span>
            <a v-if="selected.email" :href="`mailto:${selected.email}`">{{ selected.email }}</a><span v-else>—</span></div>
          <div class="detail__row"><span class="detail__k">Company</span><span>{{ selected.company || '—' }}</span></div>
          <div class="detail__row"><span class="detail__k">Received</span><span>{{ when(selected.created_at) }}</span></div>
        </div>
        <div class="detail__block">
          <p class="detail__k">Summary</p>
          <p class="detail__body">{{ selected.summary || 'No summary captured.' }}</p>
        </div>
      </template>
      <template #footer>
        <a v-if="selected?.email" class="btn btn--primary btn--sm" :href="`mailto:${selected.email}`">Reply by email</a>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3);
  border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }

.list { padding: 6px; }
.lead { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-4); width: 100%;
  padding: var(--sp-3) var(--sp-4); border-radius: var(--r-md); text-align: left; transition: background var(--dur-1); }
.lead:hover { background: var(--surface-2); }
.lead + .lead { border-top: 1px solid var(--line); }
.lead__main { min-width: 0; flex: 1; }
.lead__row { display: flex; align-items: center; gap: 10px; margin-bottom: 3px; }
.lead__name { font-size: var(--text-base); }
.lead__summary { color: var(--ink-3); font-size: var(--text-sm); max-width: 52ch; }
.lead__meta { display: grid; justify-items: end; gap: 3px; text-align: right; min-width: 0; }
.lead__company { font-size: var(--text-sm); color: var(--ink-2); max-width: 22ch; }

.detail { display: grid; gap: var(--sp-1); margin-bottom: var(--sp-6); }
.detail__row { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); padding: 9px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.detail__k { color: var(--ink-3); }
.detail__block { display: grid; gap: 6px; }
.detail__body { font-size: var(--text-base); line-height: 1.6; color: var(--ink-2); white-space: pre-wrap; }
</style>
