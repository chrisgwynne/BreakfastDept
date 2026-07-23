<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import type { Preview } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const items = ref<Preview[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const selected = ref<Preview | null>(null)
const detailLoading = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get<{ items: Preview[] }>('/previews')
    items.value = res.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

async function open(p: Preview) {
  selected.value = p
  detailLoading.value = true
  try {
    const res = await api.get<{ preview: Preview }>(`/previews/${p.id}`)
    selected.value = res.preview
  } catch {
    /* keep the list-level data if the detail fetch fails */
  } finally {
    detailLoading.value = false
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
    <PageHeader eyebrow="Studio" title="Client Previews" sub="Private, shareable previews of client work." />

    <DataState :loading="loading" :error="error" :empty="!items.length"
               empty-title="No previews yet" empty-note="Published client previews appear here with their share links and view counts."
               @retry="load">
      <div class="grid">
        <button v-for="p in items" :key="p.id" class="prev" @click="open(p)">
          <div class="prev__top">
            <span class="prev__thumb" aria-hidden="true"><NavIcon name="window" /></span>
            <StatusPill :status="p.status" />
          </div>
          <p class="prev__name truncate">{{ p.name || 'Untitled preview' }}</p>
          <p class="prev__client truncate">{{ p.client || '—' }}</p>
          <div class="prev__stats">
            <span class="num">{{ p.views }} view{{ p.views === 1 ? '' : 's' }}</span>
            <span v-if="p.password" class="prev__lock" title="Password protected"><NavIcon name="cog" /> locked</span>
          </div>
        </button>
      </div>
    </DataState>

    <Sheet :open="!!selected" eyebrow="Preview" :title="selected?.name || 'Preview'" @close="selected = null">
      <template v-if="selected">
        <div class="detail">
          <div class="detail__row"><span class="detail__k">Status</span><StatusPill :status="selected.status" /></div>
          <div class="detail__row"><span class="detail__k">Client</span><span>{{ selected.client || '—' }}</span></div>
          <div class="detail__row"><span class="detail__k">Visibility</span><span>{{ selected.visibility || '—' }}</span></div>
          <div class="detail__row"><span class="detail__k">Password</span><span>{{ selected.password ? 'Protected' : 'Open link' }}</span></div>
          <div class="detail__row"><span class="detail__k">Views</span><span class="num">{{ selected.views }}</span></div>
          <div class="detail__row"><span class="detail__k">Last viewed</span><span>{{ when(selected.last_viewed) }}</span></div>
          <div class="detail__row"><span class="detail__k">Expires</span><span>{{ when(selected.expires_at) }}</span></div>
          <div class="detail__row"><span class="detail__k">Versions</span><span class="num">{{ selected.version_count }}</span></div>
        </div>
        <div v-if="selected.url" class="detail__link">
          <p class="detail__k">Share link</p>
          <a :href="selected.url" target="_blank" rel="noopener" class="detail__url truncate">{{ selected.url }}</a>
        </div>
        <p v-else-if="detailLoading" class="faint">Loading link…</p>
      </template>
      <template #footer>
        <a v-if="selected?.url" class="btn btn--primary btn--sm" :href="selected.url" target="_blank" rel="noopener">Open preview</a>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--sp-4); }
.prev { display: grid; gap: 6px; padding: var(--sp-4); text-align: left; background: var(--surface);
  border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--sh-1);
  transition: transform var(--dur-1) var(--ease-out), box-shadow var(--dur-2); }
.prev:hover { transform: translateY(-2px); box-shadow: var(--sh-2); }
.prev__top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.prev__thumb { width: 34px; height: 34px; border-radius: var(--r-sm); background: var(--paper-2); color: var(--ink-3); display: grid; place-items: center; }
.prev__thumb svg { width: 17px; height: 17px; }
.prev__name { font-size: var(--text-base); font-weight: 600; }
.prev__client { font-size: var(--text-sm); color: var(--ink-3); }
.prev__stats { display: flex; align-items: center; justify-content: space-between; margin-top: var(--sp-2); font-size: var(--text-xs); color: var(--ink-3); }
.prev__lock { display: inline-flex; align-items: center; gap: 4px; }
.prev__lock svg { width: 12px; height: 12px; }

.detail { display: grid; gap: 0; margin-bottom: var(--sp-5); }
.detail__row { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); padding: 9px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.detail__k { color: var(--ink-3); }
.detail__link { display: grid; gap: 6px; }
.detail__url { font-family: var(--font-mono); font-size: var(--text-xs); color: var(--purple-ink);
  background: var(--purple-soft); padding: 10px var(--sp-3); border-radius: var(--r-sm); display: block; }
</style>
