<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { Proposal, ProposalListItem, ProposalItem, Contract } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))

const items = ref<ProposalListItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref('')

const editorOpen = ref(false)
const saving = ref(false)
const draft = reactive<{ title: string; client_name: string; client_email: string; introduction: string; recommended_solution: string; scope: string; deliverables: string; timeline: string; payment_schedule: string; terms: string; deposit_amount: string; items: ProposalItem[] }>({
  title: '', client_name: '', client_email: '', introduction: '', recommended_solution: '', scope: '', deliverables: '', timeline: '', payment_schedule: '', terms: '', deposit_amount: '', items: [emptyItem()],
})

const detail = ref<Proposal | null>(null)
const busy = ref('')

const FILTERS = [['', 'All'], ['draft', 'Draft'], ['sent', 'Sent'], ['viewed', 'Viewed'], ['accepted', 'Accepted'], ['rejected', 'Rejected']]
const shown = computed(() => filter.value ? items.value.filter((i) => i.status === filter.value) : items.value)

function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function when(d: string): string { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) }
function emptyItem(): ProposalItem { return { kind: 'fixed', description: '', quantity: '1', unit_price: '', tax_rate: '' } }

async function load() {
  loading.value = true
  error.value = null
  try { items.value = (await api.get<{ items: ProposalListItem[] }>('/proposals')).items }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unknown error' }
  finally { loading.value = false }
}

function newProposal() {
  Object.assign(draft, { title: '', client_name: '', client_email: '', introduction: '', recommended_solution: '', scope: '', deliverables: '', timeline: '', payment_schedule: '', terms: '', deposit_amount: '', items: [emptyItem()] })
  editorOpen.value = true
}
function addLine() { draft.items.push(emptyItem()) }
function removeLine(i: number) { draft.items.splice(i, 1) }

async function saveDraft() {
  if (saving.value) return
  saving.value = true
  try {
    const res = await api.post<{ proposal: Proposal }>('/proposals', { ...draft })
    ui.toast('Proposal created')
    editorOpen.value = false
    await load()
    detail.value = res.proposal
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not save the proposal') }
  finally { saving.value = false }
}

async function openDetail(id: string) {
  try { detail.value = (await api.get<{ proposal: Proposal }>(`/proposals/${id}`)).proposal } catch { /* ignore */ }
}
async function act(action: string, body?: unknown) {
  if (!detail.value || busy.value) return
  busy.value = action
  try {
    const res = await api.post<{ proposal: Proposal }>(`/proposals/${detail.value.id}/${action}`, body)
    detail.value = res.proposal
    ui.toast(action === 'send' ? 'Proposal sent' : action === 'withdraw' ? 'Proposal withdrawn' : action === 'convert' ? 'Converted' : 'Done')
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Action failed') }
  finally { busy.value = '' }
}
function copyLink() {
  if (!detail.value?.public_url) return
  navigator.clipboard?.writeText(detail.value.public_url)
  ui.toast('Client link copied')
}
function downloadHref(): string { return detail.value ? `/breakfast-admin/proposals/${detail.value.id}/download` : '#' }
function triggerDownload() {
  const a = document.createElement('a'); a.href = downloadHref(); document.body.appendChild(a); a.click(); a.remove()
}
async function convert() {
  await act('convert', { steps: ['won', 'deposit'] })
}
const router = useRouter()
async function createContract() {
  if (!detail.value || busy.value) return
  busy.value = 'contract'
  try {
    const res = await api.post<{ contract: Contract }>('/contracts', { proposal_uuid: detail.value.id })
    ui.toast('Contract drafted')
    detail.value = null
    router.push('/contracts').then(() => openContract(res.contract.id))
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not create the contract') }
  finally { busy.value = '' }
}
function openContract(id: string) {
  // Deep-link so the Contracts view can be extended to auto-open; for now just navigate.
  void id
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Commercial" title="Proposals" sub="Create, send and win work.">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="newProposal"><NavIcon name="plus" /> New proposal</button>
      </template>
    </PageHeader>

    <div class="tabs">
      <button v-for="[k, l] in FILTERS" :key="k" class="tab" :class="{ 'tab--active': filter === k }" @click="filter = String(k)">{{ l }}</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length" empty-title="No proposals yet"
               empty-note="Create a proposal to start winning work." @retry="load">
      <div class="card list">
        <button v-for="p in shown" :key="p.id" class="row" @click="openDetail(p.id)">
          <span class="row__num mono">{{ p.number || 'Draft' }}</span>
          <span class="row__title truncate">{{ p.title || 'Untitled' }}<span class="faint"> · {{ p.client || '—' }}</span></span>
          <StatusPill :status="p.status" />
          <span class="row__amt mono">{{ money(p.total) }}</span>
          <span class="row__date faint">{{ when(p.sent_at || p.created_at) }}</span>
        </button>
      </div>
    </DataState>

    <!-- Create -->
    <Sheet :open="editorOpen" eyebrow="New" title="New proposal" @close="editorOpen = false">
      <div class="field"><label class="label">Title</label><input class="input" v-model="draft.title" placeholder="Website design & build" /></div>
      <div class="cf__row">
        <div class="field"><label class="label">Client name</label><input class="input" v-model="draft.client_name" /></div>
        <div class="field"><label class="label">Client email</label><input class="input" v-model="draft.client_email" type="email" /></div>
      </div>
      <div class="field"><label class="label">Introduction</label><textarea class="textarea" v-model="draft.introduction" rows="2"></textarea></div>
      <div class="field"><label class="label">Recommended solution</label><textarea class="textarea" v-model="draft.recommended_solution" rows="2"></textarea></div>
      <div class="field"><label class="label">Scope</label><textarea class="textarea" v-model="draft.scope" rows="2"></textarea></div>

      <p class="label">Items</p>
      <div v-for="(l, i) in draft.items" :key="i" class="line">
        <select class="input line__kind" v-model="l.kind"><option value="fixed">Fixed</option><option value="optional">Optional</option><option value="recurring">Recurring</option></select>
        <input class="input" v-model="l.description" placeholder="Description" />
        <input class="input line__n" v-model="l.quantity" placeholder="Qty" inputmode="decimal" />
        <input class="input line__n" v-model="l.unit_price" placeholder="Unit £" inputmode="decimal" />
        <input class="input line__n" v-model="l.tax_rate" placeholder="VAT %" inputmode="decimal" />
        <button class="iconbtn" @click="removeLine(i)" aria-label="Remove">×</button>
      </div>
      <button class="btn btn--sm" @click="addLine">+ Add item</button>

      <div class="cf__row" style="margin-top:12px;">
        <div class="field"><label class="label">Deposit (£)</label><input class="input" v-model="draft.deposit_amount" inputmode="decimal" /></div>
        <div class="field"><label class="label">Payment schedule</label><input class="input" v-model="draft.payment_schedule" placeholder="50% deposit, 50% launch" /></div>
      </div>

      <template #footer>
        <button class="btn btn--sm" @click="editorOpen = false">Cancel</button>
        <button class="btn btn--primary btn--sm" :disabled="saving" @click="saveDraft">{{ saving ? 'Saving…' : 'Create proposal' }}</button>
      </template>
    </Sheet>

    <!-- Detail -->
    <Sheet :open="!!detail" :eyebrow="detail?.number || 'Draft'" :title="detail?.title || 'Proposal'" @close="detail = null">
      <template v-if="detail">
        <div class="drow"><span class="dk">Status</span><StatusPill :status="detail.status" /></div>
        <div class="drow"><span class="dk">Client</span><span>{{ detail.client }}</span></div>
        <div class="drow"><span class="dk">Total</span><strong>{{ money(detail.total) }}</strong></div>
        <div v-if="detail.deposit_amount" class="drow"><span class="dk">Deposit</span><span>{{ money(detail.deposit_amount) }}</span></div>
        <div v-if="detail.recurring_total" class="drow"><span class="dk">Recurring</span><span>{{ money(detail.recurring_total) }}/period</span></div>

        <div class="ditems">
          <div v-for="(it, i) in detail.items" :key="i" class="ditem">
            <span class="truncate">{{ it.kind !== 'fixed' ? '(' + it.kind + ') ' : '' }}{{ it.description }}</span><span class="mono">{{ money(it.line_total || 0) }}</span>
          </div>
        </div>

        <div v-if="detail.acceptances.length" class="accept">
          <p class="label">Accepted</p>
          <div v-for="(a, i) in detail.acceptances" :key="i" class="dpay">
            <span>{{ a.name }} · {{ when(a.created_at) }}</span><span class="mono">{{ money(a.total) }}</span>
          </div>
          <p class="faint sm">Document hash {{ detail.acceptances[0].hash.slice(0, 16) }}…</p>
        </div>

        <div class="dactions">
          <a v-if="detail.pdf_ready" class="btn btn--sm" :href="downloadHref()" @click.prevent="triggerDownload"><NavIcon name="window" /> Download PDF</a>
          <button v-if="detail.public_url" class="btn btn--sm" @click="copyLink"><NavIcon name="globe" /> Copy client link</button>
          <button v-if="canManage && ['draft','ready'].includes(detail.status)" class="btn btn--sm btn--primary" :disabled="busy === 'send'" @click="act('send')">Send</button>
          <button v-if="canManage && detail.status === 'accepted'" class="btn btn--sm btn--primary" :disabled="busy === 'convert'" @click="convert">Convert (won + deposit)</button>
          <button v-if="canManage && detail.status === 'accepted'" class="btn btn--sm" :disabled="busy === 'contract'" @click="createContract">Create contract</button>
          <button v-if="canManage && ['sent','viewed','ready'].includes(detail.status)" class="btn btn--sm" :disabled="busy === 'withdraw'" @click="act('withdraw')">Withdraw</button>
          <button v-if="canManage && ['sent','viewed','accepted','rejected'].includes(detail.status)" class="btn btn--sm" :disabled="busy === 'supersede'" @click="act('supersede')">New version</button>
        </div>

        <div v-if="detail.events.length" class="dpays">
          <p class="label">History</p>
          <div v-for="(ev, i) in detail.events.slice(0, 8)" :key="i" class="dpay"><span>{{ ev.detail }}</span><span class="faint">{{ when(ev.created_at) }}</span></div>
        </div>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); } .tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.mono { font-family: var(--font-mono); font-size: 0.92em; }
.list { padding: 6px; }
.row { display: grid; grid-template-columns: 120px 1fr auto auto auto; align-items: center; gap: var(--sp-3); width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; }
.row:hover { background: var(--surface-2); } .row + .row { border-top: 1px solid var(--line); }
.row__amt { text-align: right; } .row__date { font-size: var(--text-sm); }
.line { display: grid; grid-template-columns: 110px 1fr 70px 80px 70px auto; gap: 6px; margin-bottom: 6px; }
.line__n { text-align: right; }
.drow { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.dk { color: var(--ink-3); }
.ditems { margin: var(--sp-3) 0; display: grid; gap: 4px; }
.ditem { display: flex; justify-content: space-between; font-size: var(--text-sm); gap: var(--sp-3); }
.dactions { display: flex; flex-wrap: wrap; gap: var(--sp-2); margin: var(--sp-4) 0; }
.accept { background: var(--paper-2); border-radius: var(--r-md); padding: var(--sp-3); margin: var(--sp-3) 0; }
.dpays, .accept { display: grid; gap: 4px; } .dpay { display: flex; justify-content: space-between; font-size: var(--text-sm); gap: var(--sp-3); }
.sm { font-size: var(--text-xs); margin-top: 4px; }
@media (max-width: 620px) { .row { grid-template-columns: 1fr auto auto; } .row__num, .row__date { display: none; } .line { grid-template-columns: 1fr 1fr; } .cf__row { grid-template-columns: 1fr; } }
</style>
