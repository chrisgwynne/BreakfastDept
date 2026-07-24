<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useUi } from '@/stores/ui'
import type { BrevoOverview, BrevoConfig, BrevoTestResult } from '@/lib/types'

const ui = useUi()
const loading = ref(true)
const error = ref('')
const overview = ref<BrevoOverview | null>(null)
const config = reactive<BrevoConfig>({
  enabled: false, sender_name: '', sender_email: '', reply_to_name: '', reply_to_email: '',
  base_url: '', marketing_list_id: '', track_opens: true, track_clicks: true, contact_sync: false,
})
const fieldErrors = reactive<Record<string, string>>({})

const newKey = ref('')
const showKeyForm = ref(false)
const savingKey = ref(false)
const savingConfig = ref(false)
const testing = ref(false)
const test = ref<BrevoTestResult | null>(null)
const removing = ref(false)

const configured = computed(() => overview.value?.configured ?? false)

function when(d: string): string {
  if (!d) return '—'
  const t = new Date(d)
  return isNaN(t.getTime()) ? d : t.toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })
}

function apply(ov: BrevoOverview) {
  overview.value = ov
  Object.assign(config, ov.config)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    apply((await api.get<{ brevo: BrevoOverview }>('/settings/brevo')).brevo)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not load Brevo settings.'
  } finally {
    loading.value = false
  }
}

async function saveKey() {
  if (savingKey.value || !newKey.value.trim()) return
  savingKey.value = true
  fieldErrors.api_key = ''
  try {
    apply((await api.post<{ brevo: BrevoOverview }>('/settings/brevo/key', { api_key: newKey.value.trim() })).brevo)
    newKey.value = ''
    showKeyForm.value = false
    test.value = null
    ui.toast('API key saved')
  } catch (e) {
    if (e instanceof ApiError && e.fields.api_key) fieldErrors.api_key = e.fields.api_key
    else ui.toast(e instanceof ApiError ? e.message : 'Could not save the key.')
  } finally {
    savingKey.value = false
  }
}

async function removeKey() {
  if (removing.value) return
  removing.value = true
  try {
    apply((await api.del<{ brevo: BrevoOverview }>('/settings/brevo/key')).brevo)
    test.value = null
    ui.toast('API key removed')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not remove the key.')
  } finally {
    removing.value = false
  }
}

async function saveConfig() {
  if (savingConfig.value) return
  savingConfig.value = true
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
  try {
    apply((await api.put<{ brevo: BrevoOverview }>('/settings/brevo', { ...config })).brevo)
    ui.toast('Brevo settings saved')
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) {
      Object.assign(fieldErrors, e.fields)
      ui.toast('Please fix the highlighted fields.')
    } else {
      ui.toast(e instanceof ApiError ? e.message : 'Could not save settings.')
    }
  } finally {
    savingConfig.value = false
  }
}

async function runTest() {
  if (testing.value) return
  testing.value = true
  test.value = null
  try {
    test.value = await api.post<BrevoTestResult>('/settings/brevo/test')
    ui.toast(test.value.ok ? 'Brevo connection verified' : 'Connection test found problems')
    if (overview.value) await load()
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Test failed')
  } finally {
    testing.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="card card--pad" data-test="brevo-settings">
    <div class="hd">
      <h2 class="sect__h">Brevo email</h2>
      <span v-if="overview" class="badge" :class="configured ? 'badge--ok' : 'badge--off'">
        {{ configured ? 'Configured' : 'Not configured' }}</span>
    </div>

    <p v-if="loading" class="faint">Loading…</p>
    <p v-else-if="error" class="err">{{ error }}</p>

    <template v-else-if="overview">
      <!-- API key -->
      <div class="keyrow">
        <div>
          <p class="klabel">API key</p>
          <p v-if="configured" class="kval mono">{{ overview.key_hint }}
            <span v-if="overview.env_managed" class="faint"> · from environment</span></p>
          <p v-else class="faint">No API key set. Add one to send email through Brevo.</p>
        </div>
        <div class="kactions">
          <button v-if="!showKeyForm" class="btn btn--sm" @click="showKeyForm = true">{{ configured ? 'Replace' : 'Add key' }}</button>
          <button v-if="configured && overview.source === 'settings'" class="btn btn--sm btn--danger" :disabled="removing" @click="removeKey">Remove</button>
        </div>
      </div>
      <div v-if="showKeyForm" class="keyform">
        <input class="input mono" v-model="newKey" type="password" autocomplete="off" placeholder="xkeysib-…"
               @keyup.enter="saveKey" />
        <button class="btn btn--primary btn--sm" :disabled="savingKey || !newKey.trim()" @click="saveKey">{{ savingKey ? 'Saving…' : 'Save key' }}</button>
        <button class="btn btn--sm" @click="showKeyForm = false; newKey = ''">Cancel</button>
      </div>
      <p v-if="fieldErrors.api_key" class="ferr">{{ fieldErrors.api_key }}</p>

      <!-- Connection test -->
      <div class="testrow">
        <button class="btn btn--sm" :disabled="testing || !configured" @click="runTest">{{ testing ? 'Testing…' : 'Test connection' }}</button>
        <span class="faint tiny">Last ok: {{ when(overview.last_success) }} · Last fail: {{ when(overview.last_failure) }}</span>
      </div>
      <ul v-if="test" class="steps">
        <li v-for="s in test.steps" :key="s.key" class="step">
          <span class="step__dot" :class="s.ok ? 'ok' : 'bad'">{{ s.ok ? '✓' : '×' }}</span>
          <span class="step__label">{{ s.label }}</span>
          <span class="step__detail faint">{{ s.detail }}</span>
        </li>
      </ul>

      <!-- Config -->
      <div class="cfg">
        <label class="toggle"><input type="checkbox" v-model="config.enabled" /> <span>Enabled</span></label>
        <div class="two">
          <div class="field"><label class="label">Sender name</label><input class="input" v-model="config.sender_name" /></div>
          <div class="field"><label class="label">Sender email</label><input class="input" v-model="config.sender_email" type="email" />
            <p v-if="fieldErrors.sender_email" class="ferr">{{ fieldErrors.sender_email }}</p></div>
        </div>
        <div class="two">
          <div class="field"><label class="label">Reply-to name</label><input class="input" v-model="config.reply_to_name" /></div>
          <div class="field"><label class="label">Reply-to email</label><input class="input" v-model="config.reply_to_email" type="email" />
            <p v-if="fieldErrors.reply_to_email" class="ferr">{{ fieldErrors.reply_to_email }}</p></div>
        </div>
        <div class="two">
          <div class="field"><label class="label">API base URL</label><input class="input mono" v-model="config.base_url" />
            <p v-if="fieldErrors.base_url" class="ferr">{{ fieldErrors.base_url }}</p></div>
          <div class="field"><label class="label">Marketing list ID</label><input class="input" v-model="config.marketing_list_id" /></div>
        </div>
        <div class="toggles">
          <label class="toggle"><input type="checkbox" v-model="config.track_opens" /> <span>Track opens</span></label>
          <label class="toggle"><input type="checkbox" v-model="config.track_clicks" /> <span>Track clicks</span></label>
          <label class="toggle"><input type="checkbox" v-model="config.contact_sync" /> <span>Contact sync</span></label>
        </div>
        <button class="btn btn--primary btn--sm" :disabled="savingConfig" @click="saveConfig">{{ savingConfig ? 'Saving…' : 'Save Brevo settings' }}</button>
      </div>

      <p class="note">The key is encrypted at rest and never shown again in full — only the last four characters.</p>
    </template>
  </section>
</template>

<style scoped>
.hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sp-3); }
.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); }
.badge { font-size: var(--text-xs); font-weight: 600; padding: 2px 10px; border-radius: var(--r-pill); }
.badge--ok { background: var(--success-soft); color: var(--success-ink); }
.badge--off { background: var(--paper-2); color: var(--ink-3); }
.mono { font-family: var(--font-mono); font-size: 0.9em; }
.err { color: var(--danger-ink); font-size: var(--text-sm); }
.ferr { color: var(--danger-ink); font-size: var(--text-xs); margin-top: 4px; }

.keyrow { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3);
  padding: var(--sp-3); background: var(--paper-2); border-radius: var(--r-md); }
.klabel { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-3); }
.kval { font-size: var(--text-base); font-weight: 600; }
.kactions { display: flex; gap: var(--sp-2); }
.keyform { display: flex; gap: var(--sp-2); margin-top: var(--sp-2); }
.keyform .input { flex: 1; }

.testrow { display: flex; align-items: center; gap: var(--sp-3); margin-top: var(--sp-4); flex-wrap: wrap; }
.tiny { font-size: var(--text-xs); }
.steps { list-style: none; margin-top: var(--sp-2); display: grid; gap: 6px; }
.step { display: flex; align-items: center; gap: var(--sp-2); font-size: var(--text-sm); }
.step__dot { width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; flex: none; }
.step__dot.ok { background: var(--success-soft); color: var(--success-ink); }
.step__dot.bad { background: var(--danger-soft); color: var(--danger-ink); }
.step__detail { margin-left: auto; }

.cfg { margin-top: var(--sp-5); padding-top: var(--sp-4); border-top: 1px solid var(--line); display: grid; gap: var(--sp-3); }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.toggle { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); }
.toggle input { width: 16px; height: 16px; }
.toggles { display: flex; gap: var(--sp-4); flex-wrap: wrap; }
.note { font-size: var(--text-xs); color: var(--ink-3); margin-top: var(--sp-3); }
@media (max-width: 620px) { .two { grid-template-columns: 1fr; } }
</style>
