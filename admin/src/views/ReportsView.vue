<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { Reports } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'

const data = ref<Reports | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<Reports>('/reports')
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

function money(n: number): string {
  return '£' + Math.round(n).toLocaleString('en-GB')
}
function pence(n: number): string {
  return '£' + (Math.round(n) / 100).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function hrs(seconds: number): string {
  return (seconds / 3600).toFixed(1) + 'h'
}

const sources = computed(() => {
  if (!data.value) return []
  const entries = Object.entries(data.value.enquiries_by_source)
  const max = Math.max(1, ...entries.map(([, n]) => n))
  return entries
    .sort((a, b) => b[1] - a[1])
    .map(([key, n]) => ({ key: key || 'Unknown', n, pct: (n / max) * 100 }))
})

const pipeline = computed(() => {
  if (!data.value) return []
  const max = Math.max(1, ...Object.values(data.value.pipeline_by_stage).map((s) => s.value))
  return data.value.stages
    .map((s) => {
      const row = data.value!.pipeline_by_stage[s.key] ?? { count: 0, value: 0 }
      return { ...s, count: row.count, value: row.value, pct: (row.value / max) * 100 }
    })
    .filter((s) => s.key !== 'won' && s.key !== 'lost')
})

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Insight" title="Reports" sub="A straight read on how the studio is doing." />

    <DataState :loading="loading" :error="error" :rows="3" @retry="load">
      <div v-if="data">
        <div class="kpis">
          <div class="kpi">
            <span class="kpi__label">Open opportunities</span>
            <span class="kpi__value num">{{ data.open_opportunities }}</span>
          </div>
          <div class="kpi">
            <span class="kpi__label">Open pipeline value</span>
            <span class="kpi__value num">{{ money(data.pipeline_value) }}</span>
          </div>
          <div class="kpi">
            <span class="kpi__label">Enquiry sources</span>
            <span class="kpi__value num">{{ sources.length }}</span>
          </div>
        </div>

        <div class="cols">
          <section class="card card--pad grow">
            <h2 class="sect__h">Pipeline by stage</h2>
            <div v-if="pipeline.length" class="bars">
              <div v-for="s in pipeline" :key="s.key" class="bar">
                <div class="bar__label"><span>{{ s.label }}</span><span class="muted num">{{ s.count }} · {{ money(s.value) }}</span></div>
                <div class="bar__track"><div class="bar__fill" :style="{ width: Math.max(2, s.pct) + '%' }"></div></div>
              </div>
            </div>
            <p v-else class="empty">No open opportunities.</p>
          </section>

          <section class="card card--pad grow">
            <h2 class="sect__h">Enquiries by source</h2>
            <div v-if="sources.length" class="bars">
              <div v-for="s in sources" :key="s.key" class="bar">
                <div class="bar__label"><span>{{ s.key }}</span><span class="muted num">{{ s.n }}</span></div>
                <div class="bar__track"><div class="bar__fill bar__fill--butter" :style="{ width: Math.max(2, s.pct) + '%' }"></div></div>
              </div>
            </div>
            <p v-else class="empty">No enquiries recorded.</p>
          </section>
        </div>

        <div v-if="data.operations" class="ops" data-test="reports-operations">
          <h2 class="sect__h" style="margin-top:24px">Delivery &amp; profitability</h2>
          <div class="kpis">
            <div class="kpi"><span class="kpi__label">Contract value</span><span class="kpi__value num" data-test="ops-contract">{{ pence(data.operations.portfolio.contract_value) }}</span></div>
            <div class="kpi"><span class="kpi__label">Invoiced</span><span class="kpi__value num">{{ pence(data.operations.portfolio.invoiced) }}</span></div>
            <div class="kpi"><span class="kpi__label">Outstanding</span><span class="kpi__value num">{{ pence(data.operations.portfolio.outstanding) }}</span></div>
            <div class="kpi"><span class="kpi__label">Unbilled time</span><span class="kpi__value num" data-test="ops-unbilled">{{ pence(data.operations.portfolio.unbilled_time_value) }}</span></div>
          </div>

          <div class="cols">
            <section class="card card--pad grow">
              <h2 class="sect__h">Projects</h2>
              <table class="optable" data-test="ops-projects">
                <thead><tr><th>Project</th><th class="r">Contract</th><th class="r">Outstanding</th><th class="r">Unbilled time</th></tr></thead>
                <tbody>
                  <tr v-for="p in data.operations.projects" :key="p.uuid">
                    <td class="truncate">{{ p.name }}</td>
                    <td class="r num">{{ pence(p.contract_value) }}</td>
                    <td class="r num">{{ pence(p.outstanding) }}</td>
                    <td class="r num">{{ pence(p.unbilled_time_value) }}</td>
                  </tr>
                  <tr v-if="!data.operations.projects.length"><td colspan="4" class="empty">No active projects.</td></tr>
                </tbody>
              </table>
            </section>

            <section class="card card--pad grow">
              <h2 class="sect__h">Utilisation (30 days)</h2>
              <div v-if="data.operations.utilisation.length" class="bars">
                <div v-for="u in data.operations.utilisation" :key="u.author" class="bar">
                  <div class="bar__label"><span class="truncate">{{ u.author }}</span><span class="muted num">{{ hrs(u.total_seconds) }} · {{ u.billable_percent }}% billable</span></div>
                  <div class="bar__track"><div class="bar__fill" :style="{ width: Math.max(2, u.billable_percent) + '%' }"></div></div>
                </div>
              </div>
              <p v-else class="empty">No time logged in the last 30 days.</p>
            </section>
          </div>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sp-4); margin-bottom: var(--sp-6); }
.kpi { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: var(--sp-4); box-shadow: var(--sh-1); display: grid; gap: 6px; }
.kpi__label { font-size: var(--text-sm); color: var(--ink-3); }
.kpi__value { font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 500; }

.cols { display: flex; gap: var(--sp-5); align-items: flex-start; flex-wrap: wrap; }
.cols .grow { min-width: 280px; }
.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-4); }
.bars { display: grid; gap: var(--sp-4); }
.bar__label { display: flex; justify-content: space-between; font-size: var(--text-sm); margin-bottom: 6px; }
.bar__track { height: 8px; border-radius: var(--r-pill); background: var(--paper-2); overflow: hidden; }
.bar__fill { height: 100%; border-radius: var(--r-pill); background: linear-gradient(90deg, var(--purple), #7a63e6); transition: width var(--dur-4) var(--ease-out); }
.bar__fill--butter { background: linear-gradient(90deg, var(--butter), #ffe08a); }
.empty { color: var(--ink-3); font-size: var(--text-sm); padding: var(--sp-4) 0; text-align: center; }

.optable { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.optable th { text-align: left; color: var(--ink-3); font-weight: 600; font-size: var(--text-xs); padding: 4px 6px; border-bottom: 1px solid var(--line); }
.optable td { padding: 7px 6px; border-bottom: 1px solid var(--line); }
.optable .r { text-align: right; white-space: nowrap; }
@media (max-width: 720px) { .kpis { grid-template-columns: 1fr; } .cols { flex-direction: column; } .cols .grow { width: 100%; } }
</style>
