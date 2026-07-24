<script setup lang="ts">
import { onMounted, ref, reactive, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import type { Contact, Company, ListResponse } from '@/lib/types'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'

const router = useRouter()
const ui = useUi()
const auth = useAuth()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
watch(() => [ui.version.contacts, ui.version.companies], () => load())
const tab = ref<'contacts' | 'companies'>('contacts')
const search = ref('')
const showArchived = ref(false)

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
      api.get<ListResponse<Company>>('/companies' + (showArchived.value ? '?archived=1' : '')),
    ])
    contacts.value = c.items
    companies.value = co.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}
watch(showArchived, () => load())

// --- company detail: edit + archive/restore ---
const company = ref<Company | null>(null)
const cForm = reactive({ name: '', website: '', sector: '', location: '', notes: '' })
const cBusy = ref('')
function openCompany(c: Company) {
  company.value = c
  Object.assign(cForm, { name: c.name, website: c.website, sector: c.sector, location: c.location, notes: c.notes ?? '' })
}
async function saveCompany() {
  if (!company.value || cBusy.value) return
  cBusy.value = 'save'
  try {
    const res = await api.patch<{ company: Company }>(`/companies/${company.value.id}`, { ...cForm })
    company.value = res.company
    ui.toast('Company updated')
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not save the company') }
  finally { cBusy.value = '' }
}
async function archiveCompany() {
  if (!company.value || cBusy.value) return
  const warn = company.value.contact_count > 0
    ? `This company still has ${company.value.contact_count} linked contact${company.value.contact_count === 1 ? '' : 's'}. Archiving keeps them, but hides the company. Continue?`
    : 'Archive this company? It will be hidden from the active list but kept and restorable.'
  if (!window.confirm(warn)) return
  cBusy.value = 'archive'
  try {
    await api.post(`/companies/${company.value.id}/archive`, {})
    ui.toast('Company archived')
    company.value = null
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not archive the company') }
  finally { cBusy.value = '' }
}
async function restoreCompany() {
  if (!company.value || cBusy.value) return
  cBusy.value = 'restore'
  try {
    await api.post(`/companies/${company.value.id}/restore`, {})
    ui.toast('Company restored')
    company.value = null
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not restore the company') }
  finally { cBusy.value = '' }
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
        <button v-if="canManage" class="btn btn--sm" @click="ui.openCreate('company')">New company</button>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="ui.openCreate('contact')">New contact</button>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist">
      <button class="tab" :class="{ 'tab--active': tab === 'contacts' }" @click="tab = 'contacts'">
        Contacts <span class="tab__n">{{ contacts.length }}</span></button>
      <button class="tab" :class="{ 'tab--active': tab === 'companies' }" @click="tab = 'companies'">
        Companies <span class="tab__n">{{ companies.length }}</span></button>
      <label v-if="tab === 'companies'" class="arch"><input type="checkbox" v-model="showArchived" /> Show archived</label>
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
        <button v-for="c in filteredCompanies" :key="c.id" class="rowitem" @click="openCompany(c)">
          <span class="avatar avatar--sq">{{ initials(c.name) }}</span>
          <span class="rowitem__main">
            <strong class="truncate">{{ c.name || 'Unnamed company' }}<span v-if="c.archived" class="archtag">Archived</span></strong>
            <span class="faint truncate">{{ c.website || c.sector || '—' }}</span>
          </span>
          <span class="rowitem__col truncate">{{ c.location || '—' }}</span>
          <span class="rowitem__col faint num">{{ c.contact_count }} contact{{ c.contact_count === 1 ? '' : 's' }}</span>
        </button>
      </div>
    </DataState>

    <!-- Company detail: edit + archive/restore -->
    <Sheet :open="!!company" eyebrow="Company" :title="company?.name || 'Company'" @close="company = null">
      <template v-if="company">
        <div v-if="company.archived" class="pill-arch">This company is archived.</div>
        <div class="field"><label class="label">Name</label><input class="input" v-model="cForm.name" :disabled="!canManage" /></div>
        <div class="field"><label class="label">Website</label><input class="input" v-model="cForm.website" :disabled="!canManage" /></div>
        <div class="cf__row">
          <div class="field"><label class="label">Sector</label><input class="input" v-model="cForm.sector" :disabled="!canManage" /></div>
          <div class="field"><label class="label">Location</label><input class="input" v-model="cForm.location" :disabled="!canManage" /></div>
        </div>
        <div class="field"><label class="label">Notes</label><textarea class="textarea" v-model="cForm.notes" rows="3" :disabled="!canManage"></textarea></div>
        <p class="faint sm">{{ company.contact_count }} linked contact{{ company.contact_count === 1 ? '' : 's' }}.</p>
      </template>
      <template #footer v-if="canManage && company">
        <button v-if="company.archived" class="btn btn--sm" :disabled="cBusy === 'restore'" @click="restoreCompany">Restore</button>
        <button v-else class="btn btn--sm btn--danger" :disabled="cBusy === 'archive'" @click="archiveCompany">Archive</button>
        <button class="btn btn--sm btn--primary" :disabled="cBusy === 'save'" @click="saveCompany">{{ cBusy === 'save' ? 'Saving…' : 'Save changes' }}</button>
      </template>
    </Sheet>
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
.arch { margin-left: auto; display: flex; align-items: center; gap: 6px; font-size: var(--text-sm); color: var(--ink-3); }
.archtag { margin-left: 8px; font-size: var(--text-xs); font-weight: 600; color: var(--ink-3); background: var(--paper-2); padding: 1px 7px; border-radius: var(--r-pill); }
.pill-arch { font-size: var(--text-sm); color: var(--ink-2); background: var(--paper-2); border: 1px solid var(--line); border-radius: var(--r-md); padding: 8px var(--sp-3); margin-bottom: var(--sp-3); }
.sm { font-size: var(--text-sm); margin-top: var(--sp-2); }
@media (max-width: 720px) { .rowitem { grid-template-columns: 32px 1fr auto; } .rowitem__col:nth-child(3) { display: none; } }
</style>
