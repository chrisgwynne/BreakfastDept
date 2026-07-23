<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import type { Contact, Company, ListResponse } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'

const router = useRouter()
const tab = ref<'contacts' | 'companies'>('contacts')
const search = ref('')

const contacts = ref<Contact[]>([])
const companies = ref<Company[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [c, co] = await Promise.all([
      api.get<ListResponse<Contact>>('/contacts'),
      api.get<ListResponse<Company>>('/companies'),
    ])
    contacts.value = c.items
    companies.value = co.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

const filteredContacts = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return contacts.value
  return contacts.value.filter((c) => (c.name + c.email + c.company).toLowerCase().includes(q))
})
const filteredCompanies = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return companies.value
  return companies.value.filter((c) => (c.name + c.sector + c.location).toLowerCase().includes(q))
})

function initials(name: string): string {
  const p = name.trim().split(/\s+/)
  return ((p[0]?.[0] ?? '') + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() || '·'
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Relationships" title="CRM" sub="People and companies you work with.">
      <template #actions>
        <div class="search">
          <input v-model="search" class="input search__input" type="search" placeholder="Search…" />
        </div>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist">
      <button class="tab" :class="{ 'tab--active': tab === 'contacts' }" @click="tab = 'contacts'">
        Contacts <span class="tab__n">{{ contacts.length }}</span></button>
      <button class="tab" :class="{ 'tab--active': tab === 'companies' }" @click="tab = 'companies'">
        Companies <span class="tab__n">{{ companies.length }}</span></button>
    </div>

    <!-- Contacts -->
    <DataState v-if="tab === 'contacts'" :loading="loading" :error="error" :empty="!filteredContacts.length"
               empty-title="No contacts yet" empty-note="People are added when enquiries come in or you create them."
               @retry="load">
      <div class="card list">
        <button v-for="c in filteredContacts" :key="c.id" class="rowitem"
                @click="router.push(`/crm/contacts/${c.id}`)">
          <span class="avatar">{{ initials(c.name) }}</span>
          <span class="rowitem__main">
            <strong class="truncate">{{ c.name || c.email || 'Unnamed contact' }}</strong>
            <span class="faint truncate">{{ c.email || '—' }}</span>
          </span>
          <span class="rowitem__col truncate">{{ c.company || '—' }}</span>
          <span class="rowitem__col"><StatusPill v-if="c.status" :status="c.status" /></span>
        </button>
      </div>
    </DataState>

    <!-- Companies -->
    <DataState v-else :loading="loading" :error="error" :empty="!filteredCompanies.length"
               empty-title="No companies yet" empty-note="Companies group your contacts and opportunities."
               @retry="load">
      <div class="card list">
        <div v-for="c in filteredCompanies" :key="c.id" class="rowitem rowitem--static">
          <span class="avatar avatar--sq">{{ initials(c.name) }}</span>
          <span class="rowitem__main">
            <strong class="truncate">{{ c.name || 'Unnamed company' }}</strong>
            <a v-if="c.website" class="faint truncate" :href="c.website" target="_blank" rel="noopener">{{ c.website }}</a>
            <span v-else class="faint">{{ c.sector || '—' }}</span>
          </span>
          <span class="rowitem__col truncate">{{ c.location || '—' }}</span>
          <span class="rowitem__col faint num">{{ c.contact_count }} contact{{ c.contact_count === 1 ? '' : 's' }}</span>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.search__input { height: 36px; width: 220px; }
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); }
.tab { display: flex; align-items: center; gap: 7px; padding: 8px var(--sp-3); font-size: var(--text-sm);
  font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.tab__n { font-size: var(--text-xs); background: var(--paper-2); color: var(--ink-3); padding: 1px 7px; border-radius: var(--r-pill); }

.list { padding: 6px; }
.rowitem { display: grid; grid-template-columns: 36px 1fr 1fr auto; align-items: center; gap: var(--sp-3);
  width: 100%; padding: 10px var(--sp-4); border-radius: var(--r-md); text-align: left; transition: background var(--dur-1); }
.rowitem:not(.rowitem--static):hover { background: var(--surface-2); cursor: pointer; }
.rowitem + .rowitem { border-top: 1px solid var(--line); }
.avatar { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%;
  background: var(--purple-soft); color: var(--purple-ink); font-size: var(--text-xs); font-weight: 700; }
.avatar--sq { border-radius: var(--r-sm); background: var(--butter-soft); color: var(--on-butter); }
.rowitem__main { display: grid; min-width: 0; }
.rowitem__main strong { font-size: var(--text-base); }
.rowitem__col { font-size: var(--text-sm); color: var(--ink-2); min-width: 0; }
@media (max-width: 720px) { .rowitem { grid-template-columns: 32px 1fr auto; } .rowitem__col:nth-child(3) { display: none; } }
</style>
