<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import type { WebsiteOverview } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import NavIcon from '@/components/NavIcon.vue'

const data = ref<WebsiteOverview | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<WebsiteOverview>('/website')
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
    <PageHeader eyebrow="Content" title="Website" sub="The live public site — see what’s published and open any page.">
      <template #actions>
        <a v-if="data" class="btn btn--sm" :href="data.url" target="_blank" rel="noopener">
          <NavIcon name="globe" /> View site</a>
      </template>
    </PageHeader>

    <DataState :loading="loading" :error="error" :empty="!!data && !data.items.length"
               empty-title="No pages found" empty-note="Published pages of your website will be listed here."
               @retry="load">
      <div v-if="data" class="card list">
        <a v-for="p in data.items" :key="p.id" class="page" :href="p.url" target="_blank" rel="noopener">
          <span class="page__icon"><NavIcon :name="p.home ? 'grid' : 'globe'" /></span>
          <span class="page__main">
            <strong class="truncate">{{ p.title || p.id }}<span v-if="p.home" class="page__badge">Home</span></strong>
            <span class="faint truncate">/{{ p.id }} · {{ p.template }}<span v-if="p.children"> · {{ p.children }} sub-page{{ p.children === 1 ? '' : 's' }}</span></span>
          </span>
          <StatusPill :status="p.status" />
          <NavIcon name="arrow" class="page__go" />
        </a>
      </div>
    </DataState>

    <p class="note">Deep content edits are made in the content system. This overview keeps the whole public
      site one click away while you work.</p>
  </div>
</template>

<style scoped>
.list { padding: 6px; }
.page { display: grid; grid-template-columns: 38px 1fr auto auto; align-items: center; gap: var(--sp-3);
  padding: 12px var(--sp-4); border-radius: var(--r-md); transition: background var(--dur-1); }
.page:hover { background: var(--surface-2); text-decoration: none; }
.page + .page { border-top: 1px solid var(--line); }
.page__icon { width: 34px; height: 34px; border-radius: var(--r-sm); background: var(--paper-2); color: var(--ink-3); display: grid; place-items: center; }
.page__icon svg { width: 17px; height: 17px; }
.page__main { display: grid; min-width: 0; }
.page__main strong { font-size: var(--text-base); display: flex; align-items: center; gap: 8px; }
.page__badge { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
  background: var(--butter-soft); color: var(--on-butter); padding: 1px 6px; border-radius: var(--r-pill); }
.page__main span:last-child { font-size: var(--text-sm); }
.page__go { width: 15px; height: 15px; color: var(--ink-4); }
.note { font-size: var(--text-sm); color: var(--ink-3); line-height: 1.6; margin-top: var(--sp-5); max-width: 60ch; }
</style>
