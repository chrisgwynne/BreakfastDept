<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import DataState from '@/components/DataState.vue'

const router = useRouter()
const loading = ref(true)
const error = ref<string | null>(null)

interface InboxItem { project_uuid: string; project_name: string; label: string; at: string; kind?: string; total?: number }
type Groups = Record<string, InboxItem[]>
const items = ref<Groups>({})
const summary = ref<Record<string, number>>({})

const GROUPS: { key: string; title: string; tab: string }[] = [
  { key: 'messages', title: 'Client messages', tab: 'access' },
  { key: 'feedback', title: 'Feedback & sign-offs', tab: 'access' },
  { key: 'change_requests', title: 'Approved changes to apply', tab: 'changes' },
  { key: 'onboarding', title: 'Onboarding to review', tab: 'onboarding' },
  { key: 'retainers', title: 'Retainers due to bill', tab: 'retainers' },
]

const visibleGroups = computed(() => GROUPS.filter((g) => (items.value[g.key] || []).length))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [s, i] = await Promise.all([
      api.get<{ summary: Record<string, number> }>('/inbox'),
      api.get<{ items: Groups }>('/inbox/items'),
    ])
    summary.value = s.summary
    items.value = i.items
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not load the inbox'
  } finally {
    loading.value = false
  }
}
function openItem(it: InboxItem, tab: string) {
  router.push({ name: 'project', params: { id: it.project_uuid }, query: { tab } })
}
onMounted(load)
</script>

<template>
  <div class="view">
    <header class="vhead">
      <div>
        <h1 class="vhead__title">Inbox</h1>
        <p class="vhead__sub">Everything that needs your attention, drawn live from your projects.</p>
      </div>
      <button class="btn btn--sm" data-test="inbox-refresh" @click="load">Refresh</button>
    </header>

    <DataState :loading="loading" :error="error" :empty="!loading && !error && (summary.total || 0) === 0" empty-text="You’re all caught up — nothing needs action.">
      <div class="groups" data-test="inbox-groups">
        <section v-for="g in visibleGroups" :key="g.key" class="card card--pad group" :data-test="'inbox-group-' + g.key">
          <h2 class="group__title">{{ g.title }} <span class="count">{{ (items[g.key] || []).length }}</span></h2>
          <button v-for="(it, idx) in items[g.key] || []" :key="idx" class="item" :data-test="'inbox-item-' + g.key" @click="openItem(it, g.tab)">
            <span class="item__proj">{{ it.project_name }}</span>
            <span class="item__label truncate">{{ it.label }}</span>
            <span class="item__at faint">{{ (it.at || '').slice(0, 10) }}</span>
          </button>
        </section>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.view { padding: var(--sp-4); max-width: 860px; margin: 0 auto; }
.vhead { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: var(--sp-4); }
.vhead__title { font-size: var(--text-2xl); margin: 0; }
.vhead__sub { color: var(--ink-3); margin: 4px 0 0; font-size: var(--text-sm); }
.groups { display: flex; flex-direction: column; gap: var(--sp-3); }
.group__title { font-size: var(--text-base); margin: 0 0 var(--sp-2); display: flex; align-items: center; gap: 8px; }
.count { font-size: var(--text-xs); background: var(--butter); color: var(--ink); border-radius: var(--r-pill); padding: 1px 8px; font-weight: 700; }
.item { display: flex; align-items: center; gap: var(--sp-3); width: 100%; text-align: left; background: transparent; border: none; border-top: 1px solid var(--line); padding: 10px 2px; cursor: pointer; font: inherit; }
.item:hover { background: var(--paper-2); }
.item__proj { font-weight: 650; font-size: var(--text-sm); min-width: 140px; }
.item__label { flex: 1; font-size: var(--text-sm); }
.item__at { font-size: var(--text-xs); white-space: nowrap; }
</style>
