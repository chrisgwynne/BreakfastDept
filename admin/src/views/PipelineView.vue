<script setup lang="ts">
import { onMounted, ref, reactive, computed, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { Opportunity, OpportunitiesResponse, StageRef } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import Sheet from '@/components/Sheet.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
watch(() => ui.version.deals, () => load())

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

// --- opportunity detail: edit / close / archive / restore ---
const deal = ref<Opportunity | null>(null)
const dForm = reactive({ title: '', value: '', probability: '', next_action: '' })
const dBusy = ref('')
function openDeal(o: Opportunity) {
  deal.value = o
  Object.assign(dForm, { title: o.title, value: String(o.value ?? ''), probability: String(o.probability ?? ''), next_action: o.next_action ?? '' })
}
async function saveDeal() {
  if (!deal.value || dBusy.value) return
  dBusy.value = 'save'
  try {
    await api.patch(`/opportunities/${deal.value.id}`, {
      title: dForm.title,
      value: parseInt(dForm.value || '0', 10) || 0,
      probability: parseInt(dForm.probability || '0', 10) || 0,
      next_action: dForm.next_action,
    })
    flash('Opportunity updated')
    deal.value = null
    await load()
  } catch (e) { flash(e instanceof ApiError ? e.message : 'Could not save the opportunity', true) }
  finally { dBusy.value = '' }
}
async function closeDeal(outcome: 'won' | 'lost' | 'abandoned') {
  if (!deal.value || dBusy.value) return
  let reason = ''
  if (outcome === 'lost') { reason = window.prompt('Reason this deal was lost? (optional)') ?? ''; }
  dBusy.value = outcome
  try {
    await api.post(`/opportunities/${deal.value.id}/close`, { outcome, reason })
    flash(`Marked ${outcome}`)
    deal.value = null
    await load()
  } catch (e) { flash(e instanceof ApiError ? e.message : 'Could not close the opportunity', true) }
  finally { dBusy.value = '' }
}
async function archiveDeal() {
  if (!deal.value || dBusy.value) return
  dBusy.value = 'archive'
  try {
    await api.post(`/opportunities/${deal.value.id}/archive`, {})
    flash('Opportunity archived')
    deal.value = null
    await load()
  } catch (e) { flash(e instanceof ApiError ? e.message : 'Could not archive', true) }
  finally { dBusy.value = '' }
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
                :sub="canManage ? 'Drag a card to move it between stages.' : 'Your opportunities by stage.'">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="ui.openCreate('deal')">New opportunity</button>
      </template>
    </PageHeader>

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
                     @dragstart="onDragStart(o.id)" @dragend="dragId = null" @click="openDeal(o)">
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

    <!-- Opportunity detail: edit + close + archive -->
    <Sheet :open="!!deal" eyebrow="Opportunity" :title="deal?.title || 'Deal'" @close="deal = null">
      <template v-if="deal">
        <div class="field"><label class="label">Title</label><input class="input" v-model="dForm.title" :disabled="!canManage" /></div>
        <div class="cf__row">
          <div class="field"><label class="label">Value (£)</label><input class="input" v-model="dForm.value" inputmode="numeric" :disabled="!canManage" /></div>
          <div class="field"><label class="label">Probability (%)</label><input class="input" v-model="dForm.probability" inputmode="numeric" :disabled="!canManage" /></div>
        </div>
        <div class="field"><label class="label">Next action</label><input class="input" v-model="dForm.next_action" :disabled="!canManage" /></div>
        <p class="faint sm">Stage: {{ stages.find((s) => s.key === deal?.stage)?.label ?? deal.stage }}</p>

        <div v-if="canManage" class="closebar">
          <p class="label">Close this deal</p>
          <div class="closebar__btns">
            <button class="btn btn--sm" :disabled="!!dBusy" @click="closeDeal('won')">Won</button>
            <button class="btn btn--sm" :disabled="!!dBusy" @click="closeDeal('lost')">Lost</button>
            <button class="btn btn--sm" :disabled="!!dBusy" @click="closeDeal('abandoned')">Abandon</button>
          </div>
        </div>
      </template>
      <template #footer v-if="canManage && deal">
        <button class="btn btn--sm btn--danger" :disabled="dBusy === 'archive'" @click="archiveDeal">Archive</button>
        <button class="btn btn--sm btn--primary" :disabled="dBusy === 'save'" @click="saveDeal">{{ dBusy === 'save' ? 'Saving…' : 'Save changes' }}</button>
      </template>
    </Sheet>

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
.deal { cursor: pointer; }
.closebar { margin-top: var(--sp-4); padding-top: var(--sp-3); border-top: 1px solid var(--line); }
.closebar__btns { display: flex; gap: var(--sp-2); margin-top: var(--sp-2); }
.sm { font-size: var(--text-sm); margin-top: var(--sp-2); }

.toast { position: fixed; bottom: var(--sp-6); left: 50%; transform: translateX(-50%); z-index: var(--z-toast);
  background: var(--ink); color: var(--paper); padding: 10px var(--sp-4); border-radius: var(--r-pill);
  font-size: var(--text-sm); font-weight: 500; box-shadow: var(--sh-3); }
</style>
