<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { api } from '@/lib/api'
import type { Task, ListResponse } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
watch(() => ui.version.tasks, () => load())

const items = ref<Task[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const view = ref<'open' | 'overdue' | 'all'>('open')
const busyId = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get<ListResponse<Task>>('/tasks')
    items.value = res.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

function isOverdue(t: Task): boolean {
  if (!t.due_date || t.status === 'done' || t.status === 'completed') return false
  const d = new Date(t.due_date.replace(' ', 'T'))
  return !isNaN(d.getTime()) && d.getTime() < Date.now()
}
const shown = computed(() => {
  if (view.value === 'overdue') return items.value.filter(isOverdue)
  if (view.value === 'open') return items.value.filter((t) => t.status !== 'done' && t.status !== 'completed')
  return items.value
})

async function complete(t: Task) {
  if (!canManage.value || busyId.value) return
  busyId.value = t.id
  try {
    await api.post(`/tasks/${t.id}/complete`)
    t.status = 'done'
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not complete the task'
  } finally {
    busyId.value = null
  }
}

function when(iso: string): string {
  if (!iso) return 'No date'
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Work" title="Tasks" sub="What needs doing across the studio.">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="ui.openCreate('task')">New task</button>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist">
      <button class="tab" :class="{ 'tab--active': view === 'open' }" @click="view = 'open'">Open</button>
      <button class="tab" :class="{ 'tab--active': view === 'overdue' }" @click="view = 'overdue'">Overdue</button>
      <button class="tab" :class="{ 'tab--active': view === 'all' }" @click="view = 'all'">All</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length"
               empty-title="Nothing on your list" empty-note="Tasks you create or that Hermes raises will show here."
               @retry="load">
      <div class="card list">
        <div v-for="t in shown" :key="t.id" class="task" :class="{ 'task--done': t.status === 'done' || t.status === 'completed' }">
          <button class="task__check" :disabled="!canManage || t.status === 'done' || t.status === 'completed' || busyId === t.id"
                  :aria-label="`Complete ${t.title}`" @click="complete(t)">
            <svg v-if="t.status === 'done' || t.status === 'completed'" viewBox="0 0 24 24" width="14" height="14"
                 fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
          </button>
          <div class="task__main">
            <p class="task__title">{{ t.title || 'Untitled task' }}</p>
            <p v-if="t.assigned" class="faint task__assigned">{{ t.assigned }}</p>
          </div>
          <span class="task__due" :class="{ 'task__due--over': isOverdue(t) }">{{ when(t.due_date) }}</span>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3);
  border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }

.list { padding: 6px; }
.task { display: flex; align-items: center; gap: var(--sp-3); padding: 11px var(--sp-4); border-radius: var(--r-md); }
.task + .task { border-top: 1px solid var(--line); }
.task__check { flex: none; width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--line-strong);
  display: grid; place-items: center; color: var(--surface); transition: all var(--dur-1); }
.task__check:not(:disabled):hover { border-color: var(--purple); }
.task--done .task__check { background: var(--success); border-color: var(--success); color: #fff; }
.task__main { flex: 1; min-width: 0; }
.task__title { font-size: var(--text-base); font-weight: 500; }
.task--done .task__title { text-decoration: line-through; color: var(--ink-3); }
.task__assigned { font-size: var(--text-xs); margin-top: 1px; }
.task__due { font-size: var(--text-sm); color: var(--ink-3); flex: none; }
.task__due--over { color: var(--danger-ink); font-weight: 600; }
</style>
