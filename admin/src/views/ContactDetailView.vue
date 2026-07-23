<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/lib/api'
import type { ContactDetail } from '@/lib/types'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import NavIcon from '@/components/NavIcon.vue'

const route = useRoute()
const router = useRouter()
const data = ref<ContactDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<ContactDetail>(`/contacts/${route.params.id}`)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

function initials(name: string): string {
  const p = (name || '').trim().split(/\s+/)
  return ((p[0]?.[0] ?? '') + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() || '·'
}
function when(iso: string): string {
  if (!iso) return ''
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? iso : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>

<template>
  <div>
    <button class="back" @click="router.back()"><NavIcon name="arrow" /> Back</button>

    <DataState :loading="loading" :error="error" :rows="4" @retry="load">
      <div v-if="data" class="grid">
        <!-- Identity -->
        <div class="card card--pad ident">
          <span class="ident__avatar">{{ initials(data.contact.name) }}</span>
          <h1 class="ident__name">{{ data.contact.name || data.contact.email || 'Contact' }}</h1>
          <StatusPill v-if="data.contact.status" :status="data.contact.status" />
          <div class="ident__facts">
            <div class="fact"><span class="fact__k">Email</span>
              <a v-if="data.contact.email" :href="`mailto:${data.contact.email}`">{{ data.contact.email }}</a><span v-else>—</span></div>
            <div class="fact"><span class="fact__k">Phone</span><span>{{ data.contact.phone || '—' }}</span></div>
            <div class="fact"><span class="fact__k">Company</span><span>{{ data.contact.company || '—' }}</span></div>
            <div class="fact"><span class="fact__k">Source</span><span>{{ data.contact.lead_source || '—' }}</span></div>
          </div>
          <a v-if="data.contact.email" class="btn btn--primary btn--sm btn--block" :href="`mailto:${data.contact.email}`">Email {{ data.contact.name.split(' ')[0] || 'contact' }}</a>
        </div>

        <!-- Timeline -->
        <div>
          <h2 class="sect__h">Activity</h2>
          <div class="card card--pad">
            <ul v-if="data.timeline.length" class="tl">
              <li v-for="a in data.timeline" :key="a.id" class="tl__item">
                <span class="tl__dot"></span>
                <div class="tl__body">
                  <p class="tl__summary">{{ a.summary || a.type }}</p>
                  <p class="faint tl__meta">{{ a.actor || 'system' }} · {{ when(a.at) }}</p>
                </div>
              </li>
            </ul>
            <p v-else class="empty">No activity recorded yet.</p>
          </div>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-sm); color: var(--ink-3);
  margin-bottom: var(--sp-4); font-weight: 550; }
.back:hover { color: var(--ink); }
.back svg { width: 15px; height: 15px; transform: rotate(180deg); }

.grid { display: grid; grid-template-columns: 320px 1fr; gap: var(--sp-6); align-items: start; }
.ident { display: grid; justify-items: start; gap: var(--sp-3); }
.ident__avatar { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 50%;
  background: var(--purple-soft); color: var(--purple-ink); font-size: var(--text-lg); font-weight: 700; }
.ident__name { font-family: var(--font-display); font-size: var(--text-xl); font-weight: 500; }
.ident__facts { display: grid; gap: 0; width: 100%; margin: var(--sp-2) 0; }
.fact { display: flex; justify-content: space-between; gap: var(--sp-3); padding: 9px 0; border-top: 1px solid var(--line); font-size: var(--text-sm); }
.fact__k { color: var(--ink-3); }

.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.tl { list-style: none; display: grid; gap: var(--sp-4); }
.tl__item { display: grid; grid-template-columns: 14px 1fr; gap: 12px; }
.tl__dot { width: 9px; height: 9px; border-radius: 50%; background: var(--butter); margin-top: 5px; box-shadow: 0 0 0 3px var(--butter-soft); }
.tl__summary { font-size: var(--text-sm); font-weight: 500; }
.tl__meta { font-size: var(--text-xs); margin-top: 2px; }
.empty { color: var(--ink-3); font-size: var(--text-sm); padding: var(--sp-4) 0; text-align: center; }

@media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
</style>
