<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { EmailLog } from '@/lib/types'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'

const ui = useUi()
const auth = useAuth()
const canSend = computed(() => auth.can('email.send') || auth.can('admin'))

const data = ref<EmailLog | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<EmailLog>('/email')
  } catch (e) {
    // A 403 here means the account can’t view delivery — show that plainly.
    error.value = e instanceof ApiError ? e.message : (e instanceof Error ? e.message : 'Unknown error')
  } finally {
    loading.value = false
  }
}

function when(iso: string): string {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? iso : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

watch(() => ui.version.emails, () => load())
onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Messages" title="Email" sub="Every message the studio has sent, and whether it landed.">
      <template #actions>
        <button v-if="canSend" class="btn btn--sm btn--primary" @click="ui.openCreate('email')">Compose</button>
      </template>
    </PageHeader>

    <div v-if="data" class="bar">
      <div class="bar__stat"><span class="faint">Provider</span><strong>{{ data.provider || '—' }}</strong></div>
      <div class="bar__stat" :class="{ 'bar__stat--alert': data.failures > 0 }">
        <span class="faint">Recent failures</span><strong class="num">{{ data.failures }}</strong></div>
    </div>

    <DataState :loading="loading" :error="error" :empty="!!data && !data.items.length"
               empty-title="No email sent yet" empty-note="Delivery of enquiry replies and CRM email will be tracked here."
               @retry="load">
      <div v-if="data" class="card list">
        <div v-for="m in data.items" :key="m.id" class="msg">
          <div class="msg__main">
            <p class="msg__subject truncate">{{ m.subject || '(no subject)' }}</p>
            <p class="faint msg__to truncate">to {{ m.to || 'unknown' }} · {{ m.type || 'email' }}</p>
          </div>
          <StatusPill :status="m.status" />
          <span class="msg__date faint">{{ when(m.created_at) }}</span>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.bar { display: flex; gap: var(--sp-4); margin-bottom: var(--sp-4); }
.bar__stat { display: grid; gap: 2px; padding: var(--sp-3) var(--sp-4); background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--sh-1); }
.bar__stat strong { font-size: var(--text-lg); font-family: var(--font-display); font-weight: 500; }
.bar__stat--alert { border-color: var(--danger); }
.bar__stat--alert strong { color: var(--danger-ink); }

.list { padding: 6px; }
.msg { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: var(--sp-3); padding: 11px var(--sp-4); border-radius: var(--r-md); }
.msg + .msg { border-top: 1px solid var(--line); }
.msg__main { min-width: 0; }
.msg__subject { font-size: var(--text-base); font-weight: 500; }
.msg__to { font-size: var(--text-xs); margin-top: 1px; }
.msg__date { font-size: var(--text-sm); white-space: nowrap; }
@media (max-width: 640px) { .msg { grid-template-columns: 1fr auto; } .msg__date { display: none; } }
</style>
