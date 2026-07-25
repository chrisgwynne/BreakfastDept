<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { PortfolioListItem } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const router = useRouter()
const canHomepage = computed(() => auth.can('portfolio.homepage') || auth.can('admin'))

const MAX = 4

const published = ref<PortfolioListItem[]>([])
const selected = ref<string[]>([]) // ordered uuids
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)

const byId = computed<Record<string, PortfolioListItem>>(() =>
  Object.fromEntries(published.value.map((p) => [p.uuid, p])),
)
const available = computed(() => published.value.filter((p) => !selected.value.includes(p.uuid)))

async function load() {
  loading.value = true
  error.value = null
  try {
    // Only published work can be featured on the public homepage.
    const res = await api.get<{ items: PortfolioListItem[] }>('/portfolio?status=published')
    published.value = res.items
    // Seed the current selection from homepage_position order.
    selected.value = res.items
      .filter((p) => p.homepage_position !== null)
      .sort((a, b) => (a.homepage_position ?? 0) - (b.homepage_position ?? 0))
      .map((p) => p.uuid)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Error'
  } finally {
    loading.value = false
  }
}

function add(uuid: string) {
  if (selected.value.length >= MAX || selected.value.includes(uuid)) return
  selected.value = [...selected.value, uuid]
}
function remove(uuid: string) {
  selected.value = selected.value.filter((u) => u !== uuid)
}
function move(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= selected.value.length) return
  const next = [...selected.value]
  ;[next[i], next[j]] = [next[j], next[i]]
  selected.value = next
}

async function save() {
  if (!canHomepage.value || saving.value) return
  saving.value = true
  try {
    await api.post('/portfolio/homepage', { order: selected.value })
    ui.toast('Homepage selection saved')
    await load()
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not save selection')
  } finally {
    saving.value = false
  }
}

function title(p: PortfolioListItem): string {
  return p.display_title || p.card_title || p.internal_name || 'Untitled'
}

onMounted(load)
</script>

<template>
  <div>
    <button class="btn btn--sm back" @click="router.push({ name: 'portfolio' })">
      <NavIcon name="arrow" style="transform: rotate(180deg)" /> Portfolio
    </button>

    <PageHeader
      eyebrow="Showcase"
      title="Homepage selection"
      :sub="'Choose up to ' + MAX + ' published case studies to feature on the homepage, in order.'"
    />

    <DataState
      :loading="loading"
      :error="error"
      :empty="!published.length"
      empty-title="No published work yet"
      empty-note="Publish a case study first — only published work can be featured on the homepage."
      @retry="load"
    >
      <div class="cols">
        <section class="card card--pad" data-test="homepage-selected">
          <h3 class="colt">Featured ({{ selected.length }}/{{ MAX }})</h3>
          <p v-if="!selected.length" class="faint empty">Nothing selected — the homepage will hide its work section.</p>
          <ol class="sel">
            <li v-for="(uuid, i) in selected" :key="uuid" class="selrow" :data-test="'selected-' + i">
              <span class="selrow__pos">{{ i + 1 }}</span>
              <span class="selrow__name truncate">{{ title(byId[uuid]) }}</span>
              <span class="selrow__ctl">
                <button class="btn btn--xs" :disabled="i === 0" @click="move(i, -1)" aria-label="Move up">↑</button>
                <button class="btn btn--xs" :disabled="i === selected.length - 1" @click="move(i, 1)" aria-label="Move down">↓</button>
                <button class="btn btn--xs" @click="remove(uuid)" aria-label="Remove">✕</button>
              </span>
            </li>
          </ol>
          <div class="colfoot">
            <button class="btn btn--sm btn--primary" data-test="homepage-save" :disabled="!canHomepage || saving" @click="save">
              {{ saving ? 'Saving…' : 'Save selection' }}
            </button>
          </div>
        </section>

        <section class="card card--pad" data-test="homepage-available">
          <h3 class="colt">Available published work</h3>
          <p v-if="!available.length" class="faint empty">All published work is already featured.</p>
          <ul class="avail">
            <li v-for="p in available" :key="p.uuid" class="availrow">
              <span class="availrow__name truncate">{{ title(p) }}</span>
              <button class="btn btn--xs" :disabled="selected.length >= MAX" :data-test="'add-' + p.slug" @click="add(p.uuid)">
                Add
              </button>
            </li>
          </ul>
        </section>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.back { margin-bottom: var(--sp-3); }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); align-items: start; }
.colt { font-size: var(--text-base); font-weight: 650; margin-bottom: var(--sp-3); }
.sel { display: grid; gap: 8px; }
.selrow { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); border: 1px solid var(--line); border-radius: var(--r-md); }
.selrow__pos { width: 24px; height: 24px; display: grid; place-items: center; background: var(--purple-soft); color: var(--purple-ink); border-radius: 50%; font-weight: 700; font-size: var(--text-sm); }
.selrow__name { min-width: 0; }
.selrow__ctl { display: flex; gap: 4px; }
.colfoot { margin-top: var(--sp-4); }
.avail { display: grid; gap: 6px; }
.availrow { display: flex; justify-content: space-between; align-items: center; gap: var(--sp-3); padding: 8px var(--sp-3); border-bottom: 1px solid var(--line); }
.availrow__name { min-width: 0; }
.btn--xs { padding: 4px 8px; font-size: var(--text-xs); }
.empty { padding: var(--sp-2) 0; }
@media (max-width: 720px) { .cols { grid-template-columns: 1fr; } }
</style>
