<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import type { Operations } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'

const data = ref<Operations | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<Operations>('/operations')
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

@media (max-width: 620px) { .cards { grid-template-columns: 1fr; } }
</style>
