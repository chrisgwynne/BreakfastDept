<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import type { ContactDetail } from '@/lib/types'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const route = useRoute()
const router = useRouter()
const ui = useUi()
const auth = useAuth()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))

const tab = ref<'activity' | 'opportunities' | 'invoices' | 'previews' | 'tasks' | 'calendar' | 'emails'>('activity')
const ws = computed(() => data.value?.workspace ?? null)
const tabs = computed(() => [
  { key: 'activity' as const, label: 'Activity', count: 0 },
  { key: 'opportunities' as const, label: 'Deals', count: ws.value?.opportunities.length ?? 0 },
  { key: 'invoices' as const, label: 'Invoices', count: ws.value?.invoices.length ?? 0 },
  { key: 'previews' as const, label: 'Previews', count: ws.value?.previews.length ?? 0 },
  { key: 'tasks' as const, label: 'Tasks', count: ws.value?.tasks.length ?? 0 },
  { key: 'calendar' as const, label: 'Calendar', count: ws.value?.events.length ?? 0 },
  { key: 'emails' as const, label: 'Emails', count: ws.value?.emails.length ?? 0 },
])
function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function scheduleEvent() {
  if (!data.value) return
  router.push({ path: '/calendar', query: { new: '1', contact: data.value.contact.id } })
}

function emailContact() {
  if (!data.value) return
  ui.openCreate('email', { to: data.value.contact.email, contact_uuid: data.value.contact.id })
}
const data = ref<ContactDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

// edit
const editing = ref(false)
const saving = ref(false)
const editErr = ref('')
const fieldErrors = reactive<Record<string, string>>({})
const form = reactive({ display_name: '', email: '', phone: '', company: '', source: '' })
const archiving = ref(false)

function openEdit() {
  if (!data.value) return
  const c = data.value.contact
  Object.assign(form, { display_name: c.name || '', email: c.email || '', phone: c.phone || '', company: c.company || '', source: c.lead_source || '' })
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
  editErr.value = ''
  editing.value = true
}

async function saveEdit() {
  if (!data.value || saving.value) return
  saving.value = true
  editErr.value = ''
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
  try {
    await api.patch(`/contacts/${data.value.contact.id}`, { ...form })
    editing.value = false
    ui.toast('Contact updated')
    await load()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) Object.assign(fieldErrors, e.fields)
    editErr.value = e instanceof ApiError ? e.message : 'Could not save changes.'
  } finally {
    saving.value = false
  }
}

async function archive() {
  if (!data.value || archiving.value) return
  archiving.value = true
  try {
    await api.post(`/contacts/${data.value.contact.id}/archive`)
    ui.toast('Contact archived')
    await load()
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not archive.')
  } finally {
    archiving.value = false
  }
}

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
          <button v-if="data.contact.email && (auth.can('email.send') || auth.can('admin'))"
                  class="btn btn--primary btn--sm btn--block" @click="emailContact">Email {{ data.contact.name.split(' ')[0] || 'contact' }}</button>
          <div v-if="canManage && data.contact.status !== 'archived'" class="ident__actions">
            <button class="btn btn--sm" @click="openEdit"><NavIcon name="cog" /> Edit</button>
            <button class="btn btn--sm" @click="scheduleEvent"><NavIcon name="calendar" /> Schedule</button>
            <button class="btn btn--sm btn--danger" :disabled="archiving" @click="archive">Archive</button>
          </div>
        </div>

        <!-- Workspace -->
        <div class="ws">
          <div v-if="ws" class="summ">
            <div class="summ__box"><span class="summ__n">{{ ws.summary.open_opportunities }}</span><span class="summ__l">Open deals</span></div>
            <div class="summ__box"><span class="summ__n">{{ ws.summary.open_tasks }}</span><span class="summ__l">Open tasks</span></div>
            <div class="summ__box"><span class="summ__n">{{ ws.summary.active_previews }}</span><span class="summ__l">Previews</span></div>
            <div class="summ__box"><span class="summ__n">{{ money(ws.summary.outstanding) }}</span><span class="summ__l">Outstanding</span></div>
          </div>

          <div class="tabs">
            <button v-for="t in tabs" :key="t.key" class="tab" :class="{ 'tab--active': tab === t.key }" @click="tab = t.key">
              {{ t.label }}<span v-if="t.count" class="tab__c">{{ t.count }}</span>
            </button>
          </div>

          <!-- Activity -->
          <div v-if="tab === 'activity'" class="card card--pad">
            <ul v-if="data.timeline.length" class="tl">
              <li v-for="a in data.timeline" :key="a.id" class="tl__item">
                <span class="tl__dot"></span>
                <div class="tl__body"><p class="tl__summary">{{ a.summary || a.type }}</p><p class="faint tl__meta">{{ a.actor || 'system' }} · {{ when(a.at) }}</p></div>
              </li>
            </ul>
            <p v-else class="empty">No activity recorded yet.</p>
          </div>

          <!-- Opportunities -->
          <div v-else-if="tab === 'opportunities'" class="card list">
            <div v-for="o in ws?.opportunities" :key="o.id" class="lrow"><span class="truncate">{{ o.title }}</span><StatusPill :status="o.stage" /><span class="mono">{{ money(o.value / 100) }}</span></div>
            <p v-if="!ws?.opportunities.length" class="empty">No opportunities yet.</p>
          </div>

          <!-- Invoices -->
          <div v-else-if="tab === 'invoices'" class="card list">
            <div v-for="i in ws?.invoices" :key="i.id" class="lrow"><span class="mono">{{ i.number || 'Draft' }}</span><StatusPill :status="i.status" /><span class="mono">{{ money(i.amount_due) }} due</span></div>
            <p v-if="!ws?.invoices.length" class="empty">No invoices yet.</p>
          </div>

          <!-- Previews -->
          <div v-else-if="tab === 'previews'" class="card list">
            <div v-for="p in ws?.previews" :key="p.id" class="lrow"><span class="truncate">{{ p.name }}</span><StatusPill :status="p.status" /><span class="faint">{{ p.views }} views</span></div>
            <p v-if="!ws?.previews.length" class="empty">No previews yet.</p>
          </div>

          <!-- Tasks -->
          <div v-else-if="tab === 'tasks'" class="card list">
            <div v-for="t in ws?.tasks" :key="t.id" class="lrow"><span class="truncate">{{ t.title }}</span><StatusPill :status="t.status" /><span class="faint">{{ t.due_date || '—' }}</span></div>
            <p v-if="!ws?.tasks.length" class="empty">No tasks yet.</p>
          </div>

          <!-- Calendar -->
          <div v-else-if="tab === 'calendar'" class="card list">
            <div v-for="e in ws?.events" :key="e.id + e.occurrence_date" class="lrow"><span class="truncate">{{ e.title }}</span><span class="faint">{{ when(e.starts_at) }}</span></div>
            <p v-if="!ws?.events.length" class="empty">Nothing scheduled.</p>
          </div>

          <!-- Emails -->
          <div v-else-if="tab === 'emails'" class="card list">
            <div v-for="m in ws?.emails" :key="m.id" class="lrow"><span class="truncate">{{ m.subject || '(no subject)' }}</span><StatusPill :status="m.status" /><span class="faint">{{ m.at }}</span></div>
            <p v-if="!ws?.emails.length" class="empty">No emails yet.</p>
          </div>
        </div>
      </div>
    </DataState>

    <Sheet :open="editing" eyebrow="Contact" title="Edit contact" @close="editing = false">
      <div class="field"><label class="label">Name</label><input class="input" v-model="form.display_name" /></div>
      <div class="field"><label class="label">Email</label><input class="input" v-model="form.email" type="email" />
        <p v-if="fieldErrors.email" class="cf__err">{{ fieldErrors.email }}</p></div>
      <div class="field"><label class="label">Phone</label><input class="input" v-model="form.phone" /></div>
      <div class="field"><label class="label">Company</label><input class="input" v-model="form.company" /></div>
      <div class="field"><label class="label">Source</label><input class="input" v-model="form.source" /></div>
      <p v-if="editErr" class="cf__err" role="alert">{{ editErr }}</p>
      <template #footer>
        <button class="btn btn--sm" @click="editing = false">Cancel</button>
        <button class="btn btn--primary btn--sm" :disabled="saving" @click="saveEdit">{{ saving ? 'Saving…' : 'Save changes' }}</button>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.ident__actions { display: flex; flex-wrap: wrap; gap: var(--sp-2); margin-top: var(--sp-2); }
.ident__actions .btn { flex: 1; }
.ws { min-width: 0; }
.summ { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--sp-3); margin-bottom: var(--sp-4); }
.summ__box { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); padding: var(--sp-3); display: grid; gap: 2px; }
.summ__n { font-size: var(--text-lg); font-weight: 700; } .summ__l { font-size: var(--text-xs); color: var(--ink-3); }
.tabs { display: flex; gap: 2px; flex-wrap: wrap; border-bottom: 1px solid var(--line); margin-bottom: var(--sp-3); }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.tab__c { margin-left: 6px; font-size: 11px; background: var(--paper-2); color: var(--ink-3); padding: 0 6px; border-radius: 99px; }
.list { padding: 6px; }
.lrow { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); padding: 10px var(--sp-3); font-size: var(--text-sm); }
.lrow + .lrow { border-top: 1px solid var(--line); }
.lrow .mono { font-family: var(--font-mono); font-size: 0.9em; }
.lrow > span:first-child { flex: 1; min-width: 0; }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); margin-top: var(--sp-2); }
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
