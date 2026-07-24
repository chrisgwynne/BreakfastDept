<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { Contract, ContractListItem } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
const isAdmin = computed(() => auth.can('admin'))

const items = ref<ContractListItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const filter = ref('')

const detail = ref<Contract | null>(null)
const busy = ref('')

const FILTERS = [['', 'All'], ['draft', 'Draft'], ['sent', 'Sent'], ['viewed', 'Viewed'], ['signed', 'Signed'], ['declined', 'Declined']]
const shown = computed(() => filter.value ? items.value.filter((i) => i.status === filter.value) : items.value)
const completed = computed(() => !!detail.value?.completed_at)

function money(n: number): string { return '£' + Number(n).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function when(d: string): string { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) }

async function load() {
  loading.value = true
  error.value = null
  try { items.value = (await api.get<{ items: ContractListItem[] }>('/contracts')).items }
  catch (e) { error.value = e instanceof Error ? e.message : 'Unknown error' }
  finally { loading.value = false }
}
async function openDetail(id: string) {
  try { detail.value = (await api.get<{ contract: Contract }>(`/contracts/${id}`)).contract } catch { /* ignore */ }
}
async function act(action: string, body?: unknown) {
  if (!detail.value || busy.value) return
  busy.value = action
  try {
    const res = await api.post<{ contract: Contract }>(`/contracts/${detail.value.id}/${action}`, body)
    detail.value = res.contract
    ui.toast(action === 'send' ? 'Contract sent' : action === 'sign-internal' ? 'Countersigned' : action === 'withdraw' ? 'Withdrawn' : action === 'remind' ? 'Reminder sent' : 'Done')
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Action failed') }
  finally { busy.value = '' }
}
function copyLink() {
  if (!detail.value?.public_url) return
  navigator.clipboard?.writeText(detail.value.public_url)
  ui.toast('Signing link copied')
}
function dl(which: 'unsigned' | 'signed') {
  if (!detail.value) return
  const a = document.createElement('a')
  a.href = `/breakfast-admin/contracts/${detail.value.id}/${which}/download`
  document.body.appendChild(a); a.click(); a.remove()
}
async function voidContract() {
  const reason = window.prompt('Reason for voiding this contract?')
  if (!reason || !reason.trim()) return
  await act('void', { reason })
}
async function supersede() {
  const reason = window.prompt('Reason for a new version?')
  if (!reason || !reason.trim()) return
  await act('supersede', { reason })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Commercial" title="Contracts" sub="Agreements & electronic signature." />

    <div class="tabs">
      <button v-for="[k, l] in FILTERS" :key="k" class="tab" :class="{ 'tab--active': filter === k }" @click="filter = String(k)">{{ l }}</button>
    </div>

    <DataState :loading="loading" :error="error" :empty="!shown.length" empty-title="No contracts yet"
               empty-note="Create a contract from an accepted proposal." @retry="load">
      <div class="card list">
        <button v-for="c in shown" :key="c.id" class="row" @click="openDetail(c.id)">
          <span class="row__num mono">{{ c.number || 'Draft' }}</span>
          <span class="row__title truncate">{{ c.title }}<span class="faint"> · {{ c.client || '—' }}</span></span>
          <StatusPill :status="c.status" />
          <span class="row__amt mono">{{ money(c.value) }}</span>
          <span class="row__date faint">{{ when(c.sent_at || c.created_at) }}</span>
        </button>
      </div>
    </DataState>

    <Sheet :open="!!detail" :eyebrow="detail?.number || 'Draft'" :title="detail?.title || 'Contract'" @close="detail = null">
      <template v-if="detail">
        <div class="drow"><span class="dk">Status</span><StatusPill :status="detail.status" /></div>
        <div class="drow"><span class="dk">Client</span><span>{{ detail.client }}</span></div>
        <div class="drow"><span class="dk">Value</span><strong>{{ money(detail.value) }}</strong></div>

        <div class="parties">
          <p class="label">Signatories</p>
          <div v-for="p in detail.parties" :key="p.id" class="party">
            <span>{{ p.role === 'breakfast' ? 'Breakfast' : 'Client' }} · {{ p.name || '—' }}</span>
            <StatusPill :status="p.status === 'signed' ? 'signed' : 'pending'" />
          </div>
        </div>

        <div class="dactions">
          <a v-if="detail.unsigned_ready" class="btn btn--sm" @click.prevent="dl('unsigned')" href="#"><NavIcon name="window" /> Unsigned PDF</a>
          <a v-if="detail.signed_ready" class="btn btn--sm btn--primary" @click.prevent="dl('signed')" href="#"><NavIcon name="check" /> Signed PDF</a>
          <button v-if="detail.public_url" class="btn btn--sm" @click="copyLink"><NavIcon name="globe" /> Copy signing link</button>
          <button v-if="canManage && ['draft','ready'].includes(detail.status)" class="btn btn--sm btn--primary" :disabled="busy === 'send'" @click="act('send')">Send for signature</button>
          <button v-if="canManage && ['sent','viewed'].includes(detail.status)" class="btn btn--sm" :disabled="busy === 'remind'" @click="act('remind')">Remind</button>
          <button v-if="canManage && detail.status === 'signed' && !completed" class="btn btn--sm btn--primary" :disabled="busy === 'sign-internal'" @click="act('sign-internal')">Countersign</button>
          <button v-if="canManage && ['sent','viewed','ready'].includes(detail.status)" class="btn btn--sm" :disabled="busy === 'withdraw'" @click="act('withdraw')">Withdraw</button>
          <button v-if="canManage && ['sent','viewed','signed'].includes(detail.status)" class="btn btn--sm" @click="supersede">New version</button>
          <button v-if="isAdmin && !['void','draft'].includes(detail.status)" class="btn btn--sm btn--danger" @click="voidContract">Void</button>
        </div>

        <div v-if="completed" class="banner">✓ Fully signed. The signed PDF has been emailed to the client.</div>

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
.drow { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.dk { color: var(--ink-3); }
.parties { margin: var(--sp-3) 0; display: grid; gap: 4px; }
.party { display: flex; justify-content: space-between; align-items: center; font-size: var(--text-sm); }
.dactions { display: flex; flex-wrap: wrap; gap: var(--sp-2); margin: var(--sp-4) 0; }
.banner { background: #d8f3df; color: #1c6b34; padding: 10px var(--sp-3); border-radius: var(--r-md); font-size: var(--text-sm); font-weight: 600; }
.dpays { display: grid; gap: 4px; margin-top: var(--sp-3); } .dpay { display: flex; justify-content: space-between; font-size: var(--text-sm); gap: var(--sp-3); }
@media (max-width: 620px) { .row { grid-template-columns: 1fr auto auto; } .row__num, .row__date { display: none; } }
</style>
