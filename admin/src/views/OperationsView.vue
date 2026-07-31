<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { Operations } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import { portfolio, type CaptureHealth } from '@/lib/portfolio'

const auth = useAuth()
const ui = useUi()
const data = ref<Operations | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const capture = ref<CaptureHealth | null>(null)
function fmtBytes(b: number): string { return b > 1_000_000 ? (b / 1_000_000).toFixed(1) + ' MB' : Math.round(b / 1000) + ' KB' }

// --- Automation ---
interface AutomationRule { uuid: string; name: string; trigger_type: string; threshold_days: number; enabled: number; fire_count: number; last_run_at: string | null; revision: number }
const rules = ref<AutomationRule[]>([])
const triggers = ref<Record<string, string>>({})
const autoBusy = ref('')
const newRule = ref<{ trigger_type: string; name: string; threshold_days: string }>({ trigger_type: '', name: '', threshold_days: '0' })
const canManage = auth.can('admin')
async function loadAutomation() {
  const res = await api.get<{ items: AutomationRule[]; triggers: Record<string, string> }>('/automation')
  rules.value = res.items
  triggers.value = res.triggers
}
async function addRule() {
  if (!newRule.value.trigger_type) return
  autoBusy.value = 'create'
  try {
    await api.post('/automation', { trigger_type: newRule.value.trigger_type, name: newRule.value.name.trim() || undefined, threshold_days: Number(newRule.value.threshold_days) || 0 })
    newRule.value = { trigger_type: '', name: '', threshold_days: '0' }
    await loadAutomation(); ui.toast('Rule added')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not add rule') }
  finally { autoBusy.value = '' }
}
async function toggleRule(r: AutomationRule) {
  autoBusy.value = r.uuid
  try { await api.patch(`/automation/${r.uuid}`, { enabled: !r.enabled, revision: r.revision }); await loadAutomation() }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not update') }
  finally { autoBusy.value = '' }
}
async function deleteRule(r: AutomationRule) {
  autoBusy.value = r.uuid
  try { await api.del(`/automation/${r.uuid}`); await loadAutomation() }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not delete') }
  finally { autoBusy.value = '' }
}
async function runAutomation() {
  autoBusy.value = 'run'
  try {
    const res = await api.post<{ result: { fired: number; rules: number } }>('/automation/run', {})
    await loadAutomation()
    ui.toast(res.result.fired > 0 ? `Fired ${res.result.fired} action(s)` : 'Nothing to do right now')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not run') }
  finally { autoBusy.value = '' }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<Operations>('/operations')
    await loadAutomation()
    capture.value = await portfolio.captureHealth().catch(() => null)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="System" title="Operations" sub="Queue, email and health — the parts you rarely touch." />

    <DataState :loading="loading" :error="error" :rows="3" @retry="load">
      <div v-if="data" class="stack">
        <section>
          <h2 class="sect__h">Queue</h2>
          <div class="cards">
            <div class="stat">
              <span class="stat__label">Pending jobs</span>
              <span class="stat__value num">{{ data.queue.pending }}</span>
            </div>
            <div class="stat" :class="{ 'stat--alert': data.queue.failed > 0 }">
              <span class="stat__label">Failed jobs</span>
              <span class="stat__value num">{{ data.queue.failed }}</span>
            </div>
          </div>
        </section>

        <section>
          <h2 class="sect__h">Email</h2>
          <div class="cards">
            <div class="stat">
              <span class="stat__label">Provider</span>
              <span class="stat__value stat__value--text">{{ data.mail.provider || '—' }}</span>
            </div>
            <div class="stat" :class="{ 'stat--alert': data.mail.recent_failures > 0 }">
              <span class="stat__label">Recent failures</span>
              <span class="stat__value num">{{ data.mail.recent_failures }}</span>
            </div>
          </div>
        </section>

        <section v-if="capture" data-test="capture-health">
          <h2 class="sect__h">Screenshot capture</h2>
          <dl class="kv">
            <div><dt>Driver</dt><dd>{{ capture.driver }}<span v-if="!capture.configured" class="muted"> (not configured)</span></dd></div>
            <div><dt>Browser</dt><dd>{{ capture.browserAvailable === null ? '—' : capture.browserAvailable ? 'available' : 'not found' }}</dd></div>
            <div><dt>Last capture</dt><dd>{{ capture.lastSuccessful ? new Date(capture.lastSuccessful).toLocaleString() : 'never' }}</dd></div>
            <div><dt>Approved hosts</dt><dd>{{ capture.approvedHostCount }}</dd></div>
            <div><dt>Screenshots</dt><dd>{{ capture.screenshotCount }}</dd></div>
            <div><dt>Storage</dt><dd>{{ fmtBytes(capture.storageBytes) }}</dd></div>
          </dl>
        </section>

        <section>
          <h2 class="sect__h">Environment</h2>
          <div class="card card--pad meta">
            <div class="meta__row"><span class="meta__k">Mode</span>
              <span class="pill" :class="data.health.production ? 'pill--success' : 'pill--neutral'">
                {{ data.health.production ? 'Production' : 'Development' }}</span></div>
            <div class="meta__row"><span class="meta__k">Mail provider</span><span>{{ data.health.mail_provider || '—' }}</span></div>
            <div class="meta__row"><span class="meta__k">Queue depth</span><span class="num">{{ data.health.queue_depth }}</span></div>
            <div class="meta__row"><span class="meta__k">Build version</span><span class="num">{{ data.health.version }}</span></div>
          </div>
        </section>

        <section data-test="automation">
          <div class="sect__head">
            <h2 class="sect__h">Automation</h2>
            <button v-if="canManage" class="btn btn--sm btn--primary" data-test="automation-run" :disabled="autoBusy === 'run'" @click="runAutomation">Run now</button>
          </div>
          <p class="sect__sub">Rules run on a schedule (or on demand) and raise a follow-up task when something needs chasing — an overdue invoice, a stalled onboarding, an approved change left unapplied.</p>

          <form v-if="canManage" class="autoform" data-test="automation-form" @submit.prevent="addRule">
            <select class="input input--sm" v-model="newRule.trigger_type" data-test="automation-trigger">
              <option value="">Choose a trigger…</option>
              <option v-for="(label, key) in triggers" :key="key" :value="key">{{ label }}</option>
            </select>
            <input class="input input--sm" v-model="newRule.name" data-test="automation-name" placeholder="Rule name (optional)" />
            <input class="input input--sm" v-model="newRule.threshold_days" type="number" min="0" data-test="automation-days" placeholder="Grace days" style="width:110px" />
            <button class="btn btn--sm btn--primary" type="submit" data-test="automation-add" :disabled="!newRule.trigger_type || autoBusy === 'create'">Add rule</button>
          </form>

          <div class="card list">
            <div v-for="r in rules" :key="r.uuid" class="rulerow" :data-test="'rule-' + (r.enabled ? 'on' : 'off')">
              <span class="rule__name">{{ r.name }}</span>
              <span class="fmeta faint">{{ triggers[r.trigger_type] || r.trigger_type }}<span v-if="r.threshold_days"> · {{ r.threshold_days }}d grace</span> · fired {{ r.fire_count }}×</span>
              <span class="factions" v-if="canManage">
                <button class="btn btn--sm" :data-test="'rule-toggle'" :disabled="autoBusy === r.uuid" @click="toggleRule(r)">{{ r.enabled ? 'Disable' : 'Enable' }}</button>
                <button class="btn btn--sm" data-test="rule-delete" :disabled="autoBusy === r.uuid" @click="deleteRule(r)">Delete</button>
              </span>
            </div>
            <p v-if="!rules.length" class="empty">No automation rules yet.</p>
          </div>
        </section>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: var(--sp-6); }
.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sp-4); }
.stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: var(--sp-4); box-shadow: var(--sh-1); display: grid; gap: 6px; }
.stat--alert { border-color: var(--danger); background: var(--danger-soft); }
.stat__label { font-size: var(--text-sm); color: var(--ink-3); }
.stat--alert .stat__label { color: var(--danger-ink); }
.stat__value { font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 500; }
.stat__value--text { font-size: var(--text-lg); }

.meta { display: grid; }
.meta__row { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); padding: 11px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.meta__row:last-child { border-bottom: none; }
.meta__k { color: var(--ink-3); }
.pill { display: inline-flex; padding: 3px 10px; border-radius: var(--r-pill); font-size: var(--text-xs); font-weight: 600; }
.pill--success { background: var(--success-soft); color: var(--success-ink); }
.pill--neutral { background: var(--paper-2); color: var(--ink-2); }

.sect__head { display: flex; align-items: center; justify-content: space-between; }
.sect__sub { color: var(--ink-3); font-size: var(--text-sm); margin: 0 0 var(--sp-3); }
.autoform { display: flex; gap: var(--sp-2); align-items: center; flex-wrap: wrap; margin-bottom: var(--sp-3); }
.rulerow { display: flex; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); }
.rulerow + .rulerow { border-top: 1px solid var(--line); }
.rule__name { font-weight: 650; font-size: var(--text-sm); min-width: 180px; }
.fmeta { flex: 1; font-size: var(--text-xs); }
.factions { display: inline-flex; gap: var(--sp-2); }
.empty { color: var(--ink-3); font-size: var(--text-sm); padding: var(--sp-3); text-align: center; }
@media (max-width: 620px) { .cards { grid-template-columns: 1fr; } }
</style>
