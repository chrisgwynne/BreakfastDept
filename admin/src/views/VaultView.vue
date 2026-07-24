<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

interface VaultField { fkey: string; label: string; hint: string; has_value: boolean }
interface VaultItem { id: string; label: string; item_type: string; username: string; url: string; account_id: string; owner: string; archived: boolean; fields: VaultField[] }

const auth = useAuth()
const ui = useUi()
const isAdmin = computed(() => auth.can('admin'))

const items = ref<VaultItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const detail = ref<VaultItem | null>(null)
// Revealed values live only in memory and auto-mask after a short interval.
const revealed = reactive<Record<string, string>>({})
const reauthed = ref(false)

const editorOpen = ref(false)
const saving = ref(false)
const editErr = ref('')
const draft = reactive<{ label: string; item_type: string; username: string; url: string; fkey: string; value: string }>({
  label: '', item_type: 'hosting', username: '', url: '', fkey: 'password', value: '',
})

async function load() {
  loading.value = true; error.value = null
  try { items.value = (await api.get<{ items: VaultItem[] }>('/vault')).items }
  catch (e) { error.value = e instanceof Error ? e.message : 'Error' }
  finally { loading.value = false }
}

function newItem() {
  Object.assign(draft, { label: '', item_type: 'hosting', username: '', url: '', fkey: 'password', value: '' })
  editErr.value = ''
  editorOpen.value = true
}
async function save() {
  if (saving.value || !draft.label.trim()) return
  saving.value = true; editErr.value = ''
  try {
    const fields = draft.value ? [{ fkey: draft.fkey, label: draft.fkey, value: draft.value }] : []
    await api.post('/vault', { label: draft.label, item_type: draft.item_type, username: draft.username, url: draft.url, fields })
    editorOpen.value = false
    ui.toast('Vault item created')
    await load()
  } catch (e) { editErr.value = e instanceof ApiError ? e.message : 'Could not create the item.' }
  finally { saving.value = false }
}

async function openDetail(id: string) {
  reauthed.value = false
  for (const k of Object.keys(revealed)) delete revealed[k]
  try { detail.value = (await api.get<{ item: VaultItem }>(`/vault/${id}`)).item }
  catch { /* ignore */ }
}

// Step-up re-authentication: verify the current password before any reveal.
async function reauth() {
  const password = window.prompt('Confirm your password to reveal secrets:') || ''
  if (!password) return
  try {
    await api.post('/vault/reauth', { password })
    reauthed.value = true
    ui.toast('Re-authenticated — secrets can be revealed for 5 minutes')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Re-authentication failed') }
}

async function reveal(field: VaultField) {
  if (!detail.value) return
  try {
    const res = await api.post<{ value: string }>(`/vault/${detail.value.id}/reveal`, { fkey: field.fkey })
    revealed[field.fkey] = res.value
    // Mask again after 20 seconds — never persisted anywhere.
    setTimeout(() => { delete revealed[field.fkey] }, 20000)
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) { reauthed.value = false; ui.toast('Re-authentication required') }
    else ui.toast(e instanceof ApiError ? e.message : 'Could not reveal')
  }
}
async function copyField(field: VaultField) {
  if (!detail.value) return
  try {
    const res = await api.post<{ value: string }>(`/vault/${detail.value.id}/copy`, { fkey: field.fkey })
    try { await navigator.clipboard?.writeText(res.value) } catch { /* clipboard unavailable */ }
    ui.toast('Copied to clipboard (masked here)')
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) { reauthed.value = false; ui.toast('Re-authentication required') }
    else ui.toast(e instanceof ApiError ? e.message : 'Could not copy')
  }
}
async function rotateKeys() {
  if (!isAdmin.value) return
  if (!window.confirm('Rotate the vault encryption keys and re-encrypt all secrets?')) return
  try { const r = await api.post<{ rotated: number }>('/vault/rotate-keys', {}); ui.toast(`Rotated ${r.rotated} secret(s) to a new key`) }
  catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Rotation failed') }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Security" title="Credential vault" sub="Encrypted client credentials. Secrets are masked; revealing requires re-authentication.">
      <template #actions>
        <button v-if="isAdmin" class="btn btn--sm" @click="rotateKeys"><NavIcon name="cog" /> Rotate keys</button>
        <button v-if="isAdmin" class="btn btn--sm btn--primary" data-test="vault-new" @click="newItem"><NavIcon name="plus" /> New item</button>
      </template>
    </PageHeader>

    <DataState :loading="loading" :error="error" :empty="!items.length"
               empty-title="No credentials yet" empty-note="Store a client credential securely." @retry="load">
      <div class="card list" data-test="vault-list">
        <button v-for="i in items" :key="i.id" class="row" @click="openDetail(i.id)">
          <span class="row__label truncate">{{ i.label }}</span>
          <span class="row__type">{{ i.item_type }}</span>
          <span class="row__user faint truncate">{{ i.username || '—' }}</span>
          <span class="row__fields faint">{{ i.fields.length }} secret(s)</span>
        </button>
      </div>
    </DataState>

    <!-- Create -->
    <Sheet :open="editorOpen" eyebrow="Vault" title="New credential" @close="editorOpen = false">
      <div class="field"><label class="label">Label</label><input class="input" v-model="draft.label" data-test="vault-label" placeholder="e.g. Hosting cPanel" /></div>
      <div class="cf__row">
        <div class="field"><label class="label">Type</label>
          <select class="input" v-model="draft.item_type">
            <option v-for="t in ['registrar','hosting','cms','dns','analytics','email_provider','ecommerce','payment_gateway','api_key','ssh','database','other']" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div class="field"><label class="label">Username</label><input class="input" v-model="draft.username" /></div>
      </div>
      <div class="field"><label class="label">URL</label><input class="input mono" v-model="draft.url" /></div>
      <div class="cf__row">
        <div class="field"><label class="label">Secret type</label>
          <select class="input" v-model="draft.fkey">
            <option v-for="t in ['password','api_key','token','recovery_code','private_key','db_password','secure_note']" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div class="field"><label class="label">Secret value</label><input class="input mono" v-model="draft.value" type="password" data-test="vault-secret" autocomplete="off" /></div>
      </div>
      <p class="note">The secret is encrypted at rest and never shown again except through an audited reveal.</p>
      <p v-if="editErr" class="cf__err" role="alert">{{ editErr }}</p>
      <template #footer>
        <button class="btn btn--sm" @click="editorOpen = false">Cancel</button>
        <button class="btn btn--primary btn--sm" data-test="vault-save" :disabled="saving || !draft.label.trim()" @click="save">{{ saving ? 'Saving…' : 'Create' }}</button>
      </template>
    </Sheet>

    <!-- Detail -->
    <Sheet :open="!!detail" :eyebrow="detail?.item_type || 'Vault'" :title="detail?.label || ''" @close="detail = null">
      <template v-if="detail">
        <div class="drow"><span class="dk">Username</span><span>{{ detail.username || '—' }}</span></div>
        <div class="drow"><span class="dk">URL</span><span class="mono truncate">{{ detail.url || '—' }}</span></div>

        <div class="reauthbar">
          <span v-if="reauthed" class="ok">Re-authenticated ✓</span>
          <button v-else class="btn btn--sm btn--primary" data-test="vault-reauth" @click="reauth">Re-authenticate to reveal</button>
        </div>

        <div class="secrets" data-test="vault-secrets">
          <div v-for="f in detail.fields" :key="f.fkey" class="secret">
            <span class="secret__key">{{ f.label }}</span>
            <span class="secret__val mono" :data-test="'secret-' + f.fkey">{{ revealed[f.fkey] ?? f.hint }}</span>
            <span class="secret__actions">
              <button class="btn btn--sm" :disabled="!reauthed" data-test="vault-reveal" @click="reveal(f)">Reveal</button>
              <button class="btn btn--sm" :disabled="!reauthed" @click="copyField(f)">Copy</button>
            </span>
          </div>
          <p v-if="!detail.fields.length" class="faint">No secrets stored.</p>
        </div>
        <p class="note">Revealed values are shown briefly then re-masked, and every reveal/copy is recorded in the access log.</p>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.mono { font-family: var(--font-mono); font-size: 0.9em; }
.list { padding: 6px; }
.row { display: grid; grid-template-columns: 1fr auto auto auto; align-items: center; gap: var(--sp-3); width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; }
.row:hover { background: var(--surface-2); } .row + .row { border-top: 1px solid var(--line); }
.row__label { font-weight: 600; } .row__type { font-size: var(--text-xs); text-transform: uppercase; color: var(--ink-3); }
.row__user, .row__fields { font-size: var(--text-sm); white-space: nowrap; }
.cf__row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); margin-top: var(--sp-2); }
.note { font-size: var(--text-xs); color: var(--ink-3); margin-top: var(--sp-2); }
.drow { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); gap: var(--sp-3); }
.dk { color: var(--ink-3); }
.reauthbar { margin: var(--sp-4) 0; } .reauthbar .ok { color: var(--success-ink); font-weight: 600; font-size: var(--text-sm); }
.secrets { display: grid; gap: var(--sp-2); }
.secret { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: var(--sp-3); padding: 8px var(--sp-3); background: var(--paper-2); border-radius: var(--r-md); }
.secret__key { font-size: var(--text-sm); font-weight: 600; text-transform: capitalize; }
.secret__val { overflow: hidden; text-overflow: ellipsis; }
.secret__actions { display: inline-flex; gap: 6px; }
@media (max-width: 620px) { .row { grid-template-columns: 1fr auto; } .row__user, .row__type { display: none; } .cf__row { grid-template-columns: 1fr; } }
</style>
