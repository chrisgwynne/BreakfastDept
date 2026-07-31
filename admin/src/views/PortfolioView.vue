<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { portfolio, type PortfolioStub } from '@/lib/portfolio'
import { ApiError } from '@/lib/api'

const router = useRouter()
const items = ref<PortfolioStub[]>([])
const loading = ref(true)
const error = ref('')
const creating = ref(false)
const newTitle = ref('')

async function load() {
  loading.value = true
  try {
    items.value = await portfolio.list()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not load the portfolio.'
  } finally {
    loading.value = false
  }
}

async function create(starter = true) {
  if (!newTitle.value.trim()) return
  creating.value = true
  try {
    // Make beautiful the default: a new case study starts from a rich editorial
    // composition unless the owner deliberately chooses a blank page.
    const cs = await portfolio.create(newTitle.value.trim(), { starter })
    router.push({ name: 'portfolio-edit', params: { id: cs.slug } })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not create the case study.'
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="view">
    <header class="view__head">
      <h1>Portfolio</h1>
      <p class="muted">Art-directed case studies. Each one can look genuinely bespoke.</p>
    </header>

    <form class="create" @submit.prevent="create(true)">
      <input v-model="newTitle" placeholder="New case study title…" data-test="pf-new-title" aria-label="New case study title" />
      <button class="btn btn--primary" :disabled="creating" data-test="pf-create">Start from a composition</button>
      <button type="button" class="btn" :disabled="creating" data-test="pf-create-blank" @click="create(false)">Start blank</button>
    </form>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading…</p>

    <ul v-else class="list" data-test="pf-list">
      <li v-for="it in items" :key="it.id" class="row">
        <RouterLink :to="{ name: 'portfolio-edit', params: { id: it.slug } }" class="row__link" :data-test="`pf-item-${it.slug}`">
          <strong>{{ it.title }}</strong>
          <span class="tag">{{ it.status }}</span>
          <span v-if="it.hasChanges" class="tag tag--warn">draft changes</span>
        </RouterLink>
      </li>
      <li v-if="!items.length" class="muted">No case studies yet.</li>
    </ul>
  </section>
</template>

<style scoped>
.view { max-width: 900px; }
.view__head { margin-bottom: var(--sp-4); }
.create { display: flex; gap: var(--sp-2); margin-bottom: var(--sp-4); }
.create input { flex: 1; padding: 9px 12px; border: 1px solid var(--line); border-radius: var(--r-sm); }
.list { display: grid; gap: var(--sp-2); }
.row__link { display: flex; align-items: center; gap: var(--sp-2); padding: 12px 14px; border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--surface); }
.row__link:hover { box-shadow: var(--sh-1); text-decoration: none; }
.tag { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: var(--r-pill); background: var(--surface-2); color: var(--ink-3); }
.tag--warn { background: var(--butter); color: var(--ink); }
.muted { color: var(--ink-3); }
.error { color: var(--danger, #dc2626); }
</style>
