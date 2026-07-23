<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { Opportunity, OpportunitiesResponse, StageRef } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'

const auth = useAuth()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))

const items = ref<Opportunity[]>([])
const stages = ref<StageRef[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const dragId = ref<string | null>(null)
const overStage = ref<string | null>(null)
const saving = ref(false)
const toast = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get<OpportunitiesResponse>('/opportunities')
    items.value = res.items
    stages.value = res.stages
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

const byStage = computed<Record<string, Opportunity[]>>(() => {
  const map: Record<string, Opportunity[]> = {}
  for (const s of stages.value) map[s.key] = []
  for (const o of items.value) (map[o.stage] ??= []).push(o)
  return map
})

function stageValue(key: string): number {
  return (byStage.value[key] ?? []).reduce((sum, o) => sum + (o.value || 0), 0)
}
function money(n: number): string {
  return '£' + Math.round(n).toLocaleString('en-GB')
}

function onDragStart(id: string) {
  if (!canManage.value) return
  dragId.value = id
}
async function onDrop(stageKey: string) {
  overStage.value = null
  const id = dragId.value
  dragId.value = null
  if (!id || !canManage.value) return
  const opp = items.value.find((o) => o.id === id)
  if (!opp || opp.stage === stageKey) return

  const previous = opp.stage
  opp.stage = stageKey // optimistic
  saving.value = true
  try {
    await api.post(`/opportunities/${id}/move`, { stage: stageKey })
    flash('Moved to ' + (stages.value.find((s) => s.key === stageKey)?.label ?? stageKey))
  } catch (e) {
    opp.stage = previous // revert
    flash(e instanceof Error ? e.message : 'Could not move that deal', true)
  } finally {
    saving.value = false
  }
}
let toastTimer: ReturnType<typeof setTimeout> | undefined
function flash(msg: string, isError = false) {
  toast.value = (isError ? '⚠ ' : '') + msg
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => (toast.value = null), 2600)
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Deals" title="Pipeline"
                :sub="canManage ? 'Drag a card to move it between stages.' : 'Your opportunities by stage.'" />

    <DataState :loading="loading" :error="error" :empty="!items.length" :rows="3"
               empty-title="No opportunities yet" empty-note="Convert a lead to start a deal in your pipeline."
               @retry="load">
      <div class="board">
        <section v-for="s in stages" :key="s.key" class="col" :class="{ 'col--over': overStage === s.key }"
                 @dragover.prevent="overStage = s.key" @dragleave="overStage = null" @drop.prevent="onDrop(s.key)">
          <header class="col__head">
            <span class="col__title">{{ s.label }}</span>
            <span class="col__count">{{ (byStage[s.key] || []).length }}</span>
          </header>
          <p class="col__value num">{{ money(stageValue(s.key)) }}</p>
          <div class="col__cards">
            <article v-for="o in byStage[s.key]" :key="o.id" class="deal" :draggable="canManage"
                     :class="{ 'deal--drag': dragId === o.id }"
                     @dragstart="onDragStart(o.id)" @dragend="dragId = null">
              <p class="deal__title truncate">{{ o.title || 'Untitled deal' }}</p>
              <p v-if="o.contact" class="deal__contact truncate">{{ o.contact }}</p>
              <div class="deal__foot">
                <span class="deal__value num">{{ money(o.value) }}</span>
                <span v-if="o.probability" class="deal__prob">{{ o.probability }}%</span>
              </div>
            </article>
            <p v-if="!(byStage[s.key] || []).length" class="col__empty">—</p>
          </div>
        </section>
      </div>
    </DataState>

    <transition name="fade">
      <div v-if="toast" class="toast">{{ toast }}</div>
    </transition>
  </div>
</template>

<style scoped>
.board { display: grid; grid-auto-flow: column; grid-auto-columns: 260px; gap: var(--sp-3);
  overflow-x: auto; padding-bottom: var(--sp-4); }
.col { background: var(--paper-2); border: 1px solid var(--line); border-radius: var(--r-lg); padding: var(--sp-3);
  display: flex; flex-direction: column; gap: var(--sp-2); min-height: 220px; transition: background var(--dur-1), border-color var(--dur-1); }
.col--over { background: var(--purple-soft); border-color: var(--purple); }
.col__head { display: flex; align-items: center; justify-content: space-between; }
.col__title { font-size: var(--text-sm); font-weight: 650; color: var(--ink-2); }
.col__count { font-size: var(--text-xs); background: var(--surface); color: var(--ink-3); padding: 1px 8px; border-radius: var(--r-pill); border: 1px solid var(--line); }
.col__value { font-size: var(--text-xs); color: var(--ink-3); }
.col__cards { display: grid; gap: var(--sp-2); align-content: start; flex: 1; }
.deal { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); padding: var(--sp-3);
  box-shadow: var(--sh-1); cursor: grab; transition: box-shadow var(--dur-1), transform var(--dur-1); }
.deal:hover { box-shadow: var(--sh-2); transform: translateY(-1px); }
.deal--drag { opacity: 0.45; }
.deal__title { font-size: var(--text-sm); font-weight: 600; }
.deal__contact { font-size: var(--text-xs); color: var(--ink-3); margin-top: 2px; }
.deal__foot { display: flex; align-items: center; justify-content: space-between; margin-top: var(--sp-2); }
.deal__value { font-size: var(--text-sm); font-weight: 600; }
.deal__prob { font-size: var(--text-xs); color: var(--purple-ink); background: var(--purple-soft); padding: 1px 7px; border-radius: var(--r-pill); }
.col__empty { text-align: center; color: var(--ink-4); font-size: var(--text-sm); padding: var(--sp-4) 0; }

.toast { position: fixed; bottom: var(--sp-6); left: 50%; transform: translateX(-50%); z-index: var(--z-toast);
  background: var(--ink); color: var(--paper); padding: 10px var(--sp-4); border-radius: var(--r-pill);
  font-size: var(--text-sm); font-weight: 500; box-shadow: var(--sh-3); }
</style>
