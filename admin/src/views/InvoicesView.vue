<script setup lang="ts">
import { onMounted, ref, computed, reactive } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { Invoice, InvoiceListItem, InvoiceSettings } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('invoices.manage') || auth.can('admin'))
const canSend = computed(() => auth.can('email.send') || auth.can('admin'))

const items = ref<InvoiceListItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref('')

// editor
const editorOpen = ref(false)
const saving = ref(false)
const editErr = ref('')
type Line = { description: string; quantity: string; unit_price: string; tax_rate: string; discount: string }
const draft = reactive<{ bill_to_name: string; bill_to_email: string; bill_to_address: string; project: string; notes: string; terms: string; items: Line[] }>({
  bill_to_name: '', bill_to_email: '', bill_to_address: '', project: '', notes: '', terms: '', items: [],
})

// detail
const detail = ref<Invoice | null>(null)
const busy = ref('')

// settings
const settingsOpen = ref(false)
const settings = ref<InvoiceSettings | null>(null)
const savingSettings = ref(false)

const shown = computed(() => filter.value ? items.value.filter((i) => i.status === filter.value) : items.value)
const outstanding = computed(() => items.value.filter((i) => !['paid', 'void', 'draft'].includes(i.status)).reduce((s, i) => s + i.amount_due, 0))

function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function when(d: string): string { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) }

async function load() {
  loading.value = true; error.value = null
  try { items.value = (await api.get<{ items: InvoiceListItem[] }>('/invoices')).items }
  catch (e) { error.value = e instanceof Error ? e.message : 'Error' }
  finally { loading.value = false }
}

// --- editor ---
function newInvoice() {
  Object.assign(draft, { bill_to_name: '', bill_to_email: '', bill_to_address: '', project: '', notes: '', terms: '', items: [emptyLine()] })
  editErr.value = ''
  editorOpen.value = true
}
function emptyLine(): Line { return { description: '', quantity: '1', unit_price: '', tax_rate: '', discount: '' } }
function addLine() { draft.items.push(emptyLine()) }
function removeLine(i: number) { draft.items.splice(i, 1) }
function lineTotal(l: Line): number {
  const q = parseFloat(l.quantity) || 0, u = parseFloat(l.unit_price) || 0, d = parseFloat(l.discount) || 0, t = parseFloat(l.tax_rate) || 0
  const sub = q * u * (1 - d / 100)
  return sub * (1 + t / 100)
}
const draftSubtotal = computed(() => draft.items.reduce((s, l) => { const q = parseFloat(l.quantity) || 0, u = parseFloat(l.unit_price) || 0, d = parseFloat(l.discount) || 0; return s + q * u * (1 - d / 100) }, 0))
const draftTotal = computed(() => draft.items.reduce((s, l) => s + lineTotal(l), 0))

async function saveDraft() {
  if (saving.value) return
  saving.value = true; editErr.value = ''
  try {
    const payload = {
      bill_to_name: draft.bill_to_name, bill_to_email: draft.bill_to_email, bill_to_address: draft.bill_to_address,
      project: draft.project, notes: draft.notes, terms: draft.terms,
      items: draft.items.filter((l) => l.description.trim() || l.unit_price).map((l) => ({
        description: l.description, quantity: l.quantity || '1', unit_price: l.unit_price || '0', tax_rate: l.tax_rate || '0', discount: l.discount || '0',
      })),
    }
    const res = await api.post<{ invoice: Invoice }>('/invoices', payload)
    editorOpen.value = false
    ui.toast('Invoice draft saved')
    await load()
    openDetail(res.invoice.id)
  } catch (e) { editErr.value = e instanceof ApiError ? e.message : 'Could not save the draft.' }
  finally { saving.value = false }
}

// --- detail + actions ---
async function openDetail(id: string) {
  try { detail.value = (await api.get<{ invoice: Invoice }>(`/invoices/${id}`)).invoice } catch { /* ignore */ }
}
async function act(action: string, body?: unknown) {
  if (!detail.value || busy.value) return
  busy.value = action
  try {
    const res = await api.post<{ invoice: Invoice; status?: string }>(`/invoices/${detail.value.id}/${action}`, body)
    detail.value = res.invoice
    ui.toast(action === 'issue' ? 'Invoice issued' : action === 'send' ? 'Invoice emailed (queued)' : action === 'void' ? 'Invoice voided' : 'Payment recorded')
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Action failed') }
  finally { busy.value = '' }
}
const payAmount = ref('')
const payMethod = ref('')
function recordPayment() {
  if (!payAmount.value) return
  act('payment', { amount: payAmount.value, method: payMethod.value }).then(() => { payAmount.value = ''; payMethod.value = '' })
}
function sendInvoice() {
  if (!detail.value) return
  act('send', { to: detail.value.bill_to_email })
}
function previewUrl(inv: Invoice): string {
  return inv.public_url || `/invoice/preview/${inv.id}`
}

// --- settings ---
async function openSettings() {
  settingsOpen.value = true
  if (!settings.value) settings.value = (await api.get<{ settings: InvoiceSettings }>('/invoices/settings')).settings
}
async function saveSettings() {
  if (!settings.value || savingSettings.value) return
  savingSettings.value = true
  try {
    settings.value = (await api.patch<{ settings: InvoiceSettings }>('/invoices/settings', settings.value)).settings
    ui.toast('Invoice settings saved')
    settingsOpen.value = false
  } catch { ui.toast('Could not save settings') }
  finally { savingSettings.value = false }
}

const FILTERS = [['', 'All'], ['draft', 'Draft'], ['issued', 'Issued'], ['sent', 'Sent'], ['partial', 'Part paid'], ['paid', 'Paid']]
onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Money" title="Invoices" :sub="outstanding > 0 ? money(outstanding) + ' outstanding' : 'Create, issue and track invoices.'">
      <template #actions>
        <button class="btn btn--sm" @click="openSettings"><NavIcon name="cog" /> Settings</button>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="newInvoice"><NavIcon name="plus" /> New invoice</button>
      </template>
    </PageHeader>

    <div class="tabs">
      <button v-for="[k, l] in FILTERS" :key="k" class="tab" :class="{ 'tab--active': filter === k }" @click="filter = String(k)">{{ l }}</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length"
               empty-title="No invoices yet" empty-note="Create your first invoice to bill a client." @retry="load">
      <div class="card list">
        <button v-for="inv in shown" :key="inv.id" class="row" @click="openDetail(inv.id)">
          <span class="row__num mono">{{ inv.number || 'Draft' }}</span>
          <span class="row__client truncate">{{ inv.client || '—' }}<span v-if="inv.project" class="faint"> · {{ inv.project }}</span></span>
          <StatusPill :status="inv.overdue ? 'overdue' : inv.status" />
          <span class="row__due">{{ inv.status === 'paid' ? money(inv.total) : money(inv.amount_due) }}</span>
          <span class="row__date faint">{{ when(inv.issue_date || inv.created_at) }}</span>
        </button>
      </div>
    </DataState>

    <!-- Editor -->
    <Sheet :open="editorOpen" eyebrow="Invoice" title="New invoice" @close="editorOpen = false">
      <div class="field"><label class="label">Bill to (name)</label><input class="input" v-model="draft.bill_to_name" placeholder="Client or company" /></div>
      <div class="cf__row">
        <div class="field"><label class="label">Client email</label><input class="input" v-model="draft.bill_to_email" type="email" /></div>
        <div class="field"><label class="label">Project</label><input class="input" v-model="draft.project" /></div>
      </div>
      <div class="field"><label class="label">Billing address</label><textarea class="textarea" v-model="draft.bill_to_address" rows="2"></textarea></div>

      <p class="label" style="margin-top:var(--sp-2)">Line items</p>
      <div class="lines">
        <div v-for="(l, i) in draft.items" :key="i" class="line">
          <input class="input line__desc" v-model="l.description" placeholder="Description" />
          <input class="input line__n" v-model="l.quantity" placeholder="Qty" inputmode="decimal" />
          <input class="input line__n" v-model="l.unit_price" placeholder="Unit £" inputmode="decimal" />
          <input class="input line__n" v-model="l.tax_rate" placeholder="VAT%" inputmode="decimal" />
          <span class="line__total mono">{{ money(lineTotal(l)) }}</span>
          <button class="iconbtn line__rm" @click="removeLine(i)" aria-label="Remove line">×</button>
        </div>
      </div>
      <button class="btn btn--sm" @click="addLine">+ Add line</button>

      <div class="draft-totals">
        <div><span>Subtotal</span><span class="mono">{{ money(draftSubtotal) }}</span></div>
        <div class="draft-totals__grand"><span>Total</span><span class="mono">{{ money(draftTotal) }}</span></div>
      </div>

      <div class="cf__row">
        <div class="field"><label class="label">Notes</label><textarea class="textarea" v-model="draft.notes" rows="2"></textarea></div>
        <div class="field"><label class="label">Terms</label><textarea class="textarea" v-model="draft.terms" rows="2"></textarea></div>
      </div>
      <p v-if="editErr" class="cf__err" role="alert">{{ editErr }}</p>

      <template #footer>
        <button class="btn btn--sm" @click="editorOpen = false">Cancel</button>
        <button class="btn btn--primary btn--sm" :disabled="saving" @click="saveDraft">{{ saving ? 'Saving…' : 'Save draft' }}</button>
      </template>
    </Sheet>

    <!-- Detail -->
    <Sheet :open="!!detail" :eyebrow="detail?.number || 'Draft'" :title="detail?.client || 'Invoice'" @close="detail = null">
      <template v-if="detail">
        <div class="drow"><span class="dk">Status</span><StatusPill :status="detail.overdue ? 'overdue' : detail.status" /></div>
        <div class="drow"><span class="dk">Total</span><strong>{{ money(detail.total) }}</strong></div>
        <div class="drow"><span class="dk">Paid</span><span>{{ money(detail.amount_paid) }}</span></div>
        <div class="drow"><span class="dk">Due</span><strong>{{ money(detail.amount_due) }}</strong></div>
        <div class="drow"><span class="dk">Issued / due</span><span>{{ when(detail.issue_date) }} · {{ when(detail.due_date) }}</span></div>

        <div class="ditems">
          <div v-for="(it, i) in detail.items" :key="i" class="ditem">
            <span class="truncate">{{ it.description }}</span><span class="mono">{{ money(it.line_total) }}</span>
          </div>
        </div>

        <div class="dactions">
          <a class="btn btn--sm" :href="previewUrl(detail)" target="_blank" rel="noopener"><NavIcon name="window" /> View / PDF</a>
          <button v-if="canManage && detail.status === 'draft'" class="btn btn--sm btn--primary" :disabled="busy === 'issue'" @click="act('issue')">Issue</button>
          <button v-if="canSend && detail.status !== 'draft' && detail.status !== 'void'" class="btn btn--sm" :disabled="busy === 'send'" @click="sendInvoice">Email to client</button>
          <button v-if="canManage && !['draft', 'void', 'paid'].includes(detail.status)" class="btn btn--sm btn--danger" :disabled="busy === 'void'" @click="act('void')">Void</button>
        </div>

        <div v-if="canManage && !['draft', 'void', 'paid'].includes(detail.status)" class="paybox">
          <p class="label">Record a payment</p>
          <div class="cf__row">
            <input class="input" v-model="payAmount" placeholder="Amount £" inputmode="decimal" />
            <input class="input" v-model="payMethod" placeholder="Method (bank, card…)" />
          </div>
          <button class="btn btn--sm btn--primary" :disabled="busy === 'payment' || !payAmount" @click="recordPayment">Record payment</button>
        </div>

        <div v-if="detail.payments.length" class="dpays">
          <p class="label">Payments</p>
          <div v-for="(p, i) in detail.payments" :key="i" class="dpay"><span>{{ when(p.paid_on) }} · {{ p.method || 'payment' }}</span><span class="mono">{{ money(p.amount) }}</span></div>
        </div>
      </template>
    </Sheet>

    <!-- Settings -->
    <Sheet :open="settingsOpen" eyebrow="Invoicing" title="Invoice settings" @close="settingsOpen = false">
      <template v-if="settings">
        <div class="field"><label class="label">Business legal name</label><input class="input" v-model="settings.company_legal_name" /></div>
        <div class="field"><label class="label">Business address</label><textarea class="textarea" v-model="settings.company_address" rows="3"></textarea></div>
        <div class="field"><label class="label">Payment details (shown on invoice)</label><textarea class="textarea" v-model="settings.payment_details" rows="2" placeholder="Sort code / account, or payment link"></textarea></div>
        <label class="vat"><input type="checkbox" v-model="settings.vat_registered" /> VAT registered</label>
        <div v-if="settings.vat_registered" class="cf__row">
          <div class="field"><label class="label">VAT number</label><input class="input" v-model="settings.vat_number" /></div>
          <div class="field"><label class="label">Default VAT %</label><input class="input" v-model.number="settings.default_vat_rate" type="number" /></div>
        </div>
        <div class="cf__row">
          <div class="field"><label class="label">Invoice prefix</label><input class="input mono" v-model="settings.invoice_prefix" /></div>
          <div class="field"><label class="label">Payment terms (days)</label><input class="input" v-model.number="settings.default_terms_days" type="number" /></div>
        </div>
      </template>
      <template #footer>
        <button class="btn btn--sm" @click="settingsOpen = false">Cancel</button>
        <button class="btn btn--primary btn--sm" :disabled="savingSettings" @click="saveSettings">Save settings</button>
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
.row { display: grid; grid-template-columns: 130px 1fr auto auto auto; align-items: center; gap: var(--sp-3); width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; }
.row:hover { background: var(--surface-2); } .row + .row { border-top: 1px solid var(--line); }
.row__num { font-size: var(--text-sm); font-weight: 600; }
.row__client { font-size: var(--text-base); min-width: 0; }
.row__due { font-weight: 600; white-space: nowrap; } .row__date { font-size: var(--text-sm); white-space: nowrap; }

.cf__row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); margin-top: var(--sp-2); }
.lines { display: grid; gap: 6px; margin: 6px 0 var(--sp-3); }
.line { display: grid; grid-template-columns: 1fr 56px 72px 60px auto 24px; gap: 6px; align-items: center; }
.line .input { padding: 6px 8px; font-size: var(--text-sm); }
.line__total { text-align: right; font-size: var(--text-sm); white-space: nowrap; }
.line__rm { width: 24px; height: 24px; color: var(--ink-3); }
.draft-totals { display: grid; gap: 4px; margin: var(--sp-3) 0; padding: var(--sp-3); background: var(--paper-2); border-radius: var(--r-md); }
.draft-totals div { display: flex; justify-content: space-between; font-size: var(--text-sm); }
.draft-totals__grand { font-weight: 700; font-size: var(--text-base); }

.drow { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.dk { color: var(--ink-3); }
.ditems { margin: var(--sp-3) 0; display: grid; gap: 4px; }
.ditem { display: flex; justify-content: space-between; font-size: var(--text-sm); gap: var(--sp-3); }
.dactions { display: flex; flex-wrap: wrap; gap: var(--sp-2); margin: var(--sp-4) 0; }
.paybox { background: var(--paper-2); border-radius: var(--r-md); padding: var(--sp-3); display: grid; gap: var(--sp-2); margin-bottom: var(--sp-4); }
.dpays { display: grid; gap: 4px; } .dpay { display: flex; justify-content: space-between; font-size: var(--text-sm); }
.vat { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); margin: var(--sp-2) 0; }
@media (max-width: 620px) { .row { grid-template-columns: 1fr auto auto; } .row__num, .row__date { display: none; } .cf__row { grid-template-columns: 1fr; } }
</style>
