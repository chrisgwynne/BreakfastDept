<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useUi } from '@/stores/ui'
import type { StripeOverview, StripeTestResult } from '@/lib/types'

const ui = useUi()
const loading = ref(true)
const error = ref('')
const overview = ref<StripeOverview | null>(null)
const config = reactive<{ enabled: boolean; mode: string; currency: string; publishable_key: string; success_url: string; cancel_url: string }>({
  enabled: false, mode: 'test', currency: 'GBP', publishable_key: '', success_url: '', cancel_url: '',
})

const newSecret = ref('')
const showSecretForm = ref(false)
const newWebhook = ref('')
const showWebhookForm = ref(false)
const savingSecret = ref(false)
const savingWebhook = ref(false)
const savingConfig = ref(false)
const testing = ref(false)
const removing = ref(false)
const test = ref<StripeTestResult | null>(null)

const configured = computed(() => overview.value?.has_secret ?? false)

function when(d: string): string {
  if (!d) return '—'
  return d
}

function apply(ov: StripeOverview) {
  overview.value = ov
  config.enabled = ov.enabled
  config.mode = ov.mode
  config.currency = ov.currency
  config.publishable_key = ov.publishable_key
  config.success_url = ov.success_url
  config.cancel_url = ov.cancel_url
}

async function load() {
  loading.value = true; error.value = ''
  try { apply((await api.get<{ stripe: StripeOverview }>('/settings/stripe')).stripe) }
  catch (e) { error.value = e instanceof Error ? e.message : 'Could not load Stripe settings.' }
  finally { loading.value = false }
}

async function saveSecret() {
  if (savingSecret.value || !newSecret.value.trim()) return
  savingSecret.value = true
  try {
    apply((await api.post<{ stripe: StripeOverview }>('/settings/stripe/key', { secret_key: newSecret.value.trim() })).stripe)
    newSecret.value = ''; showSecretForm.value = false; test.value = null
    ui.toast('Stripe secret key saved')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not save the key.') }
  finally { savingSecret.value = false }
}

async function removeSecret() {
  if (removing.value) return
  removing.value = true
  try {
    apply((await api.del<{ stripe: StripeOverview }>('/settings/stripe/key')).stripe)
    test.value = null
    ui.toast('Stripe secret key removed')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not remove the key.') }
  finally { removing.value = false }
}

async function saveWebhook() {
  if (savingWebhook.value || !newWebhook.value.trim()) return
  savingWebhook.value = true
  try {
    apply((await api.post<{ stripe: StripeOverview }>('/settings/stripe/webhook-secret', { webhook_secret: newWebhook.value.trim() })).stripe)
    newWebhook.value = ''; showWebhookForm.value = false
    ui.toast('Webhook signing secret saved')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not save the webhook secret.') }
  finally { savingWebhook.value = false }
}

async function saveConfig() {
  if (savingConfig.value) return
  savingConfig.value = true
  try {
    apply((await api.put<{ stripe: StripeOverview }>('/settings/stripe', { ...config })).stripe)
    ui.toast('Stripe settings saved')
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not save settings.') }
  finally { savingConfig.value = false }
}

async function runTest() {
  if (testing.value) return
  testing.value = true; test.value = null
  try {
    test.value = await api.post<StripeTestResult>('/settings/stripe/test')
    ui.toast(test.value.ok ? 'Stripe connection verified' : 'Connection test failed')
    await load()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Test failed') }
  finally { testing.value = false }
}

onMounted(load)
</script>

<template>
  <section class="card card--pad">
    <div class="hd">
      <h2 class="sect__h">Stripe payments</h2>
      <span v-if="overview" class="badge" :class="overview.enabled ? 'badge--ok' : 'badge--off'">
        {{ overview.enabled ? 'Live' : (configured ? 'Configured' : 'Not configured') }}
        <template v-if="overview"> · {{ overview.mode }}</template>
      </span>
    </div>

    <p v-if="loading" class="faint">Loading…</p>
    <p v-else-if="error" class="err">{{ error }}</p>

    <template v-else-if="overview">
      <!-- Secret key -->
      <div class="keyrow">
        <div>
          <p class="klabel">Secret key ({{ overview.mode }})</p>
          <p v-if="overview.has_secret" class="kval mono">{{ overview.secret_key_hint }}</p>
          <p v-else class="faint">No secret key set for {{ overview.mode }} mode.</p>
        </div>
        <div class="kactions">
          <button v-if="!showSecretForm" class="btn btn--sm" data-test="stripe-add-key" @click="showSecretForm = true">{{ overview.has_secret ? 'Replace' : 'Add key' }}</button>
          <button v-if="overview.has_secret" class="btn btn--sm btn--danger" :disabled="removing" @click="removeSecret">Remove</button>
        </div>
      </div>
      <div v-if="showSecretForm" class="keyform">
        <input class="input mono" v-model="newSecret" type="password" autocomplete="off" placeholder="sk_test_… or sk_live_…" data-test="stripe-secret-input" @keyup.enter="saveSecret" />
        <button class="btn btn--primary btn--sm" data-test="stripe-secret-save" :disabled="savingSecret || !newSecret.trim()" @click="saveSecret">{{ savingSecret ? 'Saving…' : 'Save key' }}</button>
        <button class="btn btn--sm" @click="showSecretForm = false; newSecret = ''">Cancel</button>
      </div>

      <!-- Webhook secret -->
      <div class="keyrow" style="margin-top:var(--sp-2)">
        <div>
          <p class="klabel">Webhook signing secret</p>
          <p v-if="overview.has_webhook_secret" class="kval mono">{{ overview.webhook_secret_hint }}</p>
          <p v-else class="faint">No webhook secret. Verified payments require this.</p>
        </div>
        <div class="kactions">
          <button v-if="!showWebhookForm" class="btn btn--sm" @click="showWebhookForm = true">{{ overview.has_webhook_secret ? 'Replace' : 'Add secret' }}</button>
        </div>
      </div>
      <div v-if="showWebhookForm" class="keyform">
        <input class="input mono" v-model="newWebhook" type="password" autocomplete="off" placeholder="whsec_…" @keyup.enter="saveWebhook" />
        <button class="btn btn--primary btn--sm" :disabled="savingWebhook || !newWebhook.trim()" @click="saveWebhook">{{ savingWebhook ? 'Saving…' : 'Save secret' }}</button>
        <button class="btn btn--sm" @click="showWebhookForm = false; newWebhook = ''">Cancel</button>
      </div>

      <!-- Connection test -->
      <div class="testrow">
        <button class="btn btn--sm" :disabled="testing || !configured" @click="runTest">{{ testing ? 'Testing…' : 'Test connection' }}</button>
        <span v-if="test" class="faint tiny">{{ test.ok ? 'Connected: ' + (test.business || test.account_id) : 'Failed' }}</span>
        <span class="faint tiny">Last payment: {{ when(overview.last_payment) }}</span>
      </div>

      <!-- Config -->
      <div class="cfg">
        <label class="toggle"><input type="checkbox" v-model="config.enabled" data-test="stripe-enabled" /> <span>Enable online payments</span></label>
        <div class="two">
          <div class="field"><label class="label">Mode</label>
            <select class="input" v-model="config.mode"><option value="test">Test</option><option value="live">Live</option></select></div>
          <div class="field"><label class="label">Currency</label><input class="input" v-model="config.currency" /></div>
        </div>
        <div class="field"><label class="label">Publishable key</label><input class="input mono" v-model="config.publishable_key" placeholder="pk_…" /></div>
        <div class="two">
          <div class="field"><label class="label">Success URL (optional)</label><input class="input mono" v-model="config.success_url" placeholder="Defaults to the invoice page" /></div>
          <div class="field"><label class="label">Cancel URL (optional)</label><input class="input mono" v-model="config.cancel_url" /></div>
        </div>
        <button class="btn btn--primary btn--sm" data-test="stripe-save-config" :disabled="savingConfig" @click="saveConfig">{{ savingConfig ? 'Saving…' : 'Save Stripe settings' }}</button>
      </div>

      <p class="note">Keys are encrypted at rest and never shown again — only a masked hint. A live key can only be
        stored in live mode. Payments are confirmed by a verified Stripe webhook, never a success redirect.</p>
    </template>
  </section>
</template>

<style scoped>
.hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sp-3); }
.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); }
.badge { font-size: var(--text-xs); font-weight: 600; padding: 2px 10px; border-radius: var(--r-pill); text-transform: capitalize; }
.badge--ok { background: var(--success-soft); color: var(--success-ink); }
.badge--off { background: var(--paper-2); color: var(--ink-3); }
.mono { font-family: var(--font-mono); font-size: 0.9em; }
.err { color: var(--danger-ink); font-size: var(--text-sm); }
.keyrow { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3);
  padding: var(--sp-3); background: var(--paper-2); border-radius: var(--r-md); }
.klabel { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-3); }
.kval { font-size: var(--text-base); font-weight: 600; }
.kactions { display: flex; gap: var(--sp-2); }
.keyform { display: flex; gap: var(--sp-2); margin-top: var(--sp-2); }
.keyform .input { flex: 1; }
.testrow { display: flex; align-items: center; gap: var(--sp-3); margin-top: var(--sp-4); flex-wrap: wrap; }
.tiny { font-size: var(--text-xs); }
.cfg { margin-top: var(--sp-5); padding-top: var(--sp-4); border-top: 1px solid var(--line); display: grid; gap: var(--sp-3); }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.toggle { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); }
.toggle input { width: 16px; height: 16px; }
.note { font-size: var(--text-xs); color: var(--ink-3); margin-top: var(--sp-3); line-height: 1.6; }
@media (max-width: 620px) { .two { grid-template-columns: 1fr; } }
</style>
