<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import type {
  HermesOverview, HermesCredential, HermesScopes, HermesHealth, HermesSettings,
  HermesActivityEntry, HermesStatus, HermesTestResult, HermesGeneratedCredential,
} from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const canManage = computed(() => auth.can('hermes.manage'))

type Tab = 'overview' | 'credentials' | 'scopes' | 'activity' | 'diagnostics'
const tab = ref<Tab>('overview')
const TABS: { key: Tab; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'credentials', label: 'Credentials' },
  { key: 'scopes', label: 'Scopes' },
  { key: 'activity', label: 'Activity' },
  { key: 'diagnostics', label: 'Diagnostics' },
]

// --- data + loading state per section ---
const overview = ref<HermesOverview | null>(null)
const credentials = ref<HermesCredential[]>([])
const scopes = ref<HermesScopes | null>(null)
const health = ref<HermesHealth | null>(null)
const settings = ref<HermesSettings | null>(null)
const activity = ref<{ items: HermesActivityEntry[]; total: number; page: number; per_page: number } | null>(null)
const activityFilter = ref('')
const activityPage = ref(1)

const loading = ref(true)
const error = ref<string | null>(null)
const loaded = ref<Set<Tab>>(new Set())

const STATUS: Record<HermesStatus, { label: string; tone: string }> = {
  healthy: { label: 'Healthy', tone: 'success' },
  attention: { label: 'Attention needed', tone: 'warning' },
  degraded: { label: 'Degraded', tone: 'danger' },
  misconfigured: { label: 'Not configured', tone: 'neutral' },
  disabled: { label: 'Disabled', tone: 'neutral' },
}

async function loadTab(t: Tab, force = false) {
  if (loaded.value.has(t) && !force) return
  loading.value = true
  error.value = null
  try {
    if (t === 'overview') overview.value = await api.get<HermesOverview>('/hermes/overview')
    else if (t === 'credentials') credentials.value = (await api.get<{ items: HermesCredential[] }>('/hermes/credentials')).items
    else if (t === 'scopes') scopes.value = await api.get<HermesScopes>('/hermes/scopes')
    else if (t === 'activity') await loadActivity()
    else if (t === 'diagnostics') {
      health.value = await api.get<HermesHealth>('/hermes/health')
      settings.value = await api.get<HermesSettings>('/hermes/settings')
    }
    loaded.value.add(t)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

async function loadActivity() {
  const q = new URLSearchParams()
  if (activityFilter.value) q.set('result', activityFilter.value)
  q.set('page', String(activityPage.value))
  activity.value = await api.get(`/hermes/activity?${q.toString()}`)
}
async function refilterActivity() {
  activityPage.value = 1
  loading.value = true
  try { await loadActivity() } catch (e) { error.value = e instanceof Error ? e.message : 'Error' } finally { loading.value = false }
}

function switchTab(t: Tab) {
  tab.value = t
  loadTab(t)
}

// --- self-test flow ---
const testOpen = ref(false)
const testCredential = ref('')
const testing = ref(false)
const testResult = ref<HermesTestResult | null>(null)
const testError = ref('')

function openTest(credentialId = '') {
  testCredential.value = credentialId || credentials.value[0]?.id || ''
  testResult.value = null
  testError.value = ''
  testOpen.value = true
  if (!loaded.value.has('credentials')) loadTab('credentials')
}
async function runTest() {
  if (!testCredential.value || testing.value) return
  testing.value = true
  testError.value = ''
  testResult.value = null
  try {
    testResult.value = await api.post<HermesTestResult>('/hermes/test', { credential: testCredential.value })
  } catch (e) {
    testError.value = e instanceof ApiError ? e.message : 'The test could not run.'
  } finally {
    testing.value = false
  }
}

// --- generate credential flow ---
const genOpen = ref(false)
const genId = ref('')
const genScopes = ref<Set<string>>(new Set())
const generating = ref(false)
const generated = ref<HermesGeneratedCredential | null>(null)
const genError = ref('')
const copied = ref(false)

function openGenerate() {
  genId.value = ''
  genScopes.value = new Set()
  generated.value = null
  genError.value = ''
  copied.value = false
  genOpen.value = true
  if (!scopes.value) loadTab('scopes')
}
function toggleScope(s: string) {
  const next = new Set(genScopes.value)
  next.has(s) ? next.delete(s) : next.add(s)
  genScopes.value = next
}
async function runGenerate() {
  if (generating.value) return
  generating.value = true
  genError.value = ''
  try {
    generated.value = await api.post<HermesGeneratedCredential>('/hermes/credentials/generate', {
      id: genId.value.trim(),
      scopes: [...genScopes.value],
    })
  } catch (e) {
    genError.value = e instanceof ApiError ? e.message : 'Could not generate the credential line.'
  } finally {
    generating.value = false
  }
}
async function copyLine() {
  if (!generated.value) return
  try {
    await navigator.clipboard.writeText(generated.value.env_line)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch { /* clipboard blocked — the value is selectable in the field */ }
}

// --- activity detail ---
const detail = ref<HermesActivityEntry | null>(null)
async function openEntry(id: string) {
  try {
    const res = await api.get<{ entry: HermesActivityEntry }>(`/hermes/activity/${id}`)
    detail.value = res.entry
  } catch { /* ignore */ }
}

function when(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? String(iso) : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function riskTone(r: string): string {
  return r === 'high' ? 'danger' : r === 'medium' ? 'warning' : 'neutral'
}
function resultTone(r: string): string {
  return r === 'ok' ? 'success' : r === 'denied' ? 'warning' : 'danger'
}

onMounted(() => loadTab('overview'))
</script>

<template>
  <div>
    <PageHeader eyebrow="Integration" title="Hermes"
                sub="The HMAC-authenticated API that lets trusted automations read and draft — never publish or send.">
      <template #actions>
        <span v-if="overview" class="pill" :class="`pill--${STATUS[overview.status].tone}`">
          <span class="pill__dot"></span>{{ STATUS[overview.status].label }}</span>
        <button v-if="canManage" class="btn btn--sm" @click="openTest()"><NavIcon name="check" /> Test connection</button>
      </template>
    </PageHeader>

    <div class="tabs" role="tablist">
      <button v-for="t in TABS" :key="t.key" class="tab" :class="{ 'tab--active': tab === t.key }"
              role="tab" :aria-selected="tab === t.key" @click="switchTab(t.key)">{{ t.label }}</button>
    </div>

    <DataState :loading="loading" :error="error" :rows="4" @retry="loadTab(tab, true)">
      <!-- ===================== OVERVIEW ===================== -->
      <div v-if="tab === 'overview' && overview">
        <!-- Not-configured / disabled explainer -->
        <div v-if="!overview.enabled || !overview.configured" class="setup card card--pad">
          <div class="setup__icon"><NavIcon name="plug" /></div>
          <h2 class="setup__title">{{ !overview.enabled ? 'Hermes is turned off' : 'Hermes has no credentials yet' }}</h2>
          <p class="setup__lead">Hermes lets trusted automations (like an assistant) securely read your studio data and
            prepare drafts for your review. It can never publish, send email, delete records or reveal secrets.</p>
          <ol class="setup__steps">
            <li :data-done="overview.enabled"><span>Enable it</span><code>HERMES_ENABLED=true</code> in your deployment .env</li>
            <li :data-done="overview.configured"><span>Add a credential</span>Create one below to get the env line, then redeploy</li>
            <li><span>Test it</span>Run a connection self-test once the credential is live</li>
          </ol>
          <button v-if="canManage" class="btn btn--primary btn--sm" @click="openGenerate"><NavIcon name="plus" /> Generate a credential</button>
        </div>

        <!-- Configured overview -->
        <template v-else>
          <div class="stats">
            <div class="stat"><span class="stat__k">Endpoint</span><span class="stat__v stat__v--mono">/{{ overview.endpoint }}</span></div>
            <div class="stat"><span class="stat__k">Credentials</span><span class="stat__v num">{{ overview.credential_count }}</span></div>
            <div class="stat"><span class="stat__k">Scopes available</span><span class="stat__v num">{{ overview.scope_count }}</span></div>
            <div class="stat"><span class="stat__k">Replay window</span><span class="stat__v num">{{ overview.replay_window }}s</span></div>
          </div>
          <div class="cols">
            <section class="card card--pad grow">
              <h3 class="h3">Last 24 hours</h3>
              <div class="mini">
                <div><span class="mini__n num">{{ overview.requests_24h }}</span><span class="mini__l">requests</span></div>
                <div><span class="mini__n num" :class="{ 'mini__n--bad': overview.failures_24h > 0 }">{{ overview.failures_24h }}</span><span class="mini__l">failures</span></div>
              </div>
              <dl class="facts">
                <div><dt>Last success</dt><dd>{{ when(overview.last_success) }}</dd></div>
                <div><dt>Last failure</dt><dd>{{ when(overview.last_failure) }}</dd></div>
              </dl>
            </section>
            <section class="card card--pad" style="width:340px;flex:none">
              <h3 class="h3">Recent activity</h3>
              <ul v-if="overview.recent.length" class="tl">
                <li v-for="a in overview.recent" :key="a.id" class="tl__i">
                  <span class="tl__dot" :class="`tl__dot--${resultTone(a.result)}`"></span>
                  <div><p class="tl__t"><span class="mono">{{ a.method }}</span> {{ a.endpoint }}</p>
                    <p class="faint tl__m">{{ a.credential }} · {{ when(a.at) }}</p></div>
                </li>
              </ul>
              <p v-else class="empty">No requests recorded yet.</p>
            </section>
          </div>
        </template>
      </div>

      <!-- ===================== CREDENTIALS ===================== -->
      <div v-else-if="tab === 'credentials'">
        <div class="rowhead">
          <p class="faint">Credentials are managed in your deployment environment — the shared secret never leaves it and is never shown here.</p>
          <button v-if="canManage" class="btn btn--sm btn--primary" @click="openGenerate"><NavIcon name="plus" /> Generate credential</button>
        </div>
        <div v-if="credentials.length" class="card list">
          <div v-for="c in credentials" :key="c.id" class="cred">
            <span class="cred__icon"><NavIcon name="key" /></span>
            <div class="cred__main">
              <strong class="cred__id">{{ c.id }}</strong>
              <div class="chips">
                <span v-for="s in c.scopes" :key="s" class="chip mono">{{ s }}</span>
              </div>
            </div>
            <div class="cred__meta">
              <span class="faint">{{ c.request_count }} req · last {{ when(c.last_used) }}</span>
              <span class="tag">deployment-managed</span>
            </div>
            <button v-if="canManage" class="btn btn--sm" @click="openTest(c.id)">Test</button>
          </div>
        </div>
        <p v-else class="empty empty--pad">No credentials configured. Generate one to get its env line.</p>
      </div>

      <!-- ===================== SCOPES ===================== -->
      <div v-else-if="tab === 'scopes' && scopes">
        <div v-for="g in scopes.groups" :key="g.domain" class="scopegroup">
          <h3 class="h3">{{ g.domain }}</h3>
          <div class="card list">
            <div v-for="s in g.scopes" :key="s.scope" class="scope">
              <div class="scope__main">
                <div class="scope__top">
                  <code class="mono scope__name">{{ s.scope }}</code>
                  <span class="pill pill--sm" :class="`pill--${riskTone(s.risk)}`">{{ s.risk }} risk</span>
                  <span class="tag" :class="{ 'tag--write': s.kind !== 'read' }">{{ s.kind }}</span>
                </div>
                <p class="scope__desc">{{ s.description }}</p>
              </div>
              <div class="scope__holders">
                <span v-if="s.granted_to.length" class="faint">Held by {{ s.granted_to.join(', ') }}</span>
                <span v-else class="faint">Not granted</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== ACTIVITY ===================== -->
      <div v-else-if="tab === 'activity'">
        <div class="tabs tabs--sub">
          <button v-for="f in ['', 'ok', 'denied', 'error']" :key="f" class="tab tab--sm"
                  :class="{ 'tab--active': activityFilter === f }"
                  @click="activityFilter = f; refilterActivity()">{{ f === '' ? 'All' : f }}</button>
        </div>
        <div v-if="activity && activity.items.length" class="card list">
          <button v-for="a in activity.items" :key="a.id" class="act" @click="openEntry(a.id)">
            <span class="pill pill--sm" :class="`pill--${resultTone(a.result)}`">{{ a.result }}</span>
            <span class="act__ep"><span class="mono act__m">{{ a.method }}</span> {{ a.endpoint }}</span>
            <span class="act__scope mono faint">{{ a.scope || '—' }}</span>
            <span class="act__cred faint">{{ a.credential }}</span>
            <span class="act__at faint">{{ when(a.at) }}</span>
          </button>
        </div>
        <p v-else class="empty empty--pad">No matching activity.</p>
        <div v-if="activity && activity.total > activity.per_page" class="pager">
          <button class="btn btn--sm" :disabled="activity.page <= 1" @click="activityPage--; loadActivity()">Previous</button>
          <span class="faint">Page {{ activity.page }} of {{ Math.ceil(activity.total / activity.per_page) }}</span>
          <button class="btn btn--sm" :disabled="activity.page * activity.per_page >= activity.total" @click="activityPage++; loadActivity()">Next</button>
        </div>
      </div>

      <!-- ===================== DIAGNOSTICS ===================== -->
      <div v-else-if="tab === 'diagnostics' && health">
        <div class="diag">
          <div class="diaggrid">
            <div class="dcard" :data-ok="health.enabled"><span class="dcard__k">Enabled</span><span class="dcard__v">{{ health.enabled ? 'Yes' : 'No' }}</span></div>
            <div class="dcard" :data-ok="health.configured"><span class="dcard__k">Credentials</span><span class="dcard__v">{{ health.credentials }}</span></div>
            <div class="dcard" :data-ok="health.audit_storage"><span class="dcard__k">Audit storage</span><span class="dcard__v">{{ health.audit_storage ? 'Ready' : 'Missing' }}</span></div>
            <div class="dcard" :data-ok="health.nonce_storage"><span class="dcard__k">Nonce store</span><span class="dcard__v">{{ health.nonce_storage ? 'Ready' : 'Missing' }}</span></div>
            <div class="dcard" :data-ok="health.queue_failed === 0"><span class="dcard__k">Queue</span><span class="dcard__v">{{ health.queue_pending }} pending · {{ health.queue_failed }} failed</span></div>
            <div class="dcard" :data-ok="health.failures_24h === 0"><span class="dcard__k">Failures (24h)</span><span class="dcard__v">{{ health.failures_24h }} of {{ health.requests_24h }}</span></div>
          </div>

          <section v-if="settings" class="card card--pad">
            <h3 class="h3">Deployment settings <span class="tag">read-only</span></h3>
            <p class="faint" style="margin-bottom:var(--sp-3)">These are set in the host environment and change only on a redeploy.</p>
            <div class="setrows">
              <div v-for="r in settings.deployment" :key="r.key" class="setrow">
                <div><code class="mono">{{ r.key }}</code><p class="faint setrow__note">{{ r.note }}</p></div>
                <span class="setrow__v">{{ r.value }}</span>
              </div>
            </div>
          </section>
        </div>
      </div>
    </DataState>

    <!-- ===================== TEST SHEET ===================== -->
    <Sheet :open="testOpen" eyebrow="Diagnostics" title="Test Hermes connection" @close="testOpen = false">
      <p class="faint" style="margin-bottom:var(--sp-4)">Signs a request with the credential’s secret and runs the full
        authentication chain server-side. It performs no action and never reveals the secret.</p>
      <div class="field">
        <label class="label" for="testcred">Credential</label>
        <select id="testcred" class="select" v-model="testCredential">
          <option v-for="c in credentials" :key="c.id" :value="c.id">{{ c.id }}</option>
        </select>
      </div>
      <button class="btn btn--primary btn--block" :disabled="!testCredential || testing" @click="runTest">
        {{ testing ? 'Testing…' : 'Run self-test' }}</button>
      <p v-if="testError" class="err" role="alert">{{ testError }}</p>

      <div v-if="testResult" class="steps">
        <div class="steps__head" :class="testResult.ok ? 'steps__head--ok' : 'steps__head--bad'">
          {{ testResult.ok ? 'All checks passed' : 'Some checks failed' }}
        </div>
        <div v-for="s in testResult.steps" :key="s.key" class="step" :data-ok="s.ok">
          <span class="step__mark">{{ s.ok ? '✓' : '✕' }}</span>
          <div><p class="step__label">{{ s.label }}</p><p v-if="s.detail" class="faint step__detail">{{ s.detail }}</p></div>
        </div>
      </div>
    </Sheet>

    <!-- ===================== GENERATE SHEET ===================== -->
    <Sheet :open="genOpen" eyebrow="Credential" title="Generate a credential" @close="genOpen = false">
      <template v-if="!generated">
        <p class="faint" style="margin-bottom:var(--sp-4)">This produces the environment line for a new (or rotated)
          credential with a fresh secret. Add it to your deployment .env and redeploy — nothing is changed here now.</p>
        <div class="field">
          <label class="label" for="genid">Credential id</label>
          <input id="genid" class="input mono" v-model="genId" placeholder="e.g. studio-assistant" autocomplete="off" />
        </div>
        <div class="field">
          <label class="label">Scopes</label>
          <div v-if="scopes" class="scopepick">
            <div v-for="g in scopes.groups" :key="g.domain" class="scopepick__group">
              <p class="scopepick__domain">{{ g.domain }}</p>
              <label v-for="s in g.scopes" :key="s.scope" class="scopepick__opt">
                <input type="checkbox" :checked="genScopes.has(s.scope)" @change="toggleScope(s.scope)" />
                <code class="mono">{{ s.scope }}</code>
                <span v-if="s.kind !== 'read'" class="tag tag--write">{{ s.kind }}</span>
              </label>
            </div>
          </div>
        </div>
        <button class="btn btn--primary btn--block" :disabled="generating || !genId.trim() || genScopes.size === 0" @click="runGenerate">
          {{ generating ? 'Generating…' : 'Generate env line' }}</button>
        <p v-if="genError" class="err" role="alert">{{ genError }}</p>
      </template>

      <template v-else>
        <div class="gen">
          <p class="gen__title">{{ generated.rotating ? 'Rotated' : 'Created' }} <code class="mono">{{ generated.id }}</code></p>
          <p class="gen__warn">⚠ The secret is shown once. Copy it now — it isn’t stored here.</p>
          <textarea class="textarea mono gen__line" readonly rows="3" :value="generated.env_line"></textarea>
          <button class="btn btn--sm" @click="copyLine">{{ copied ? 'Copied' : 'Copy line' }}</button>
          <p class="faint gen__inst">{{ generated.instructions }}</p>
        </div>
      </template>
    </Sheet>

    <!-- ===================== ACTIVITY DETAIL ===================== -->
    <Sheet :open="!!detail" eyebrow="Request" :title="detail?.endpoint || 'Activity'" @close="detail = null">
      <template v-if="detail">
        <div class="dl">
          <div><dt>Result</dt><dd><span class="pill pill--sm" :class="`pill--${resultTone(detail.result)}`">{{ detail.result }}</span> · {{ detail.status }}</dd></div>
          <div><dt>Method</dt><dd class="mono">{{ detail.method }}</dd></div>
          <div><dt>Endpoint</dt><dd class="mono">{{ detail.endpoint }}</dd></div>
          <div><dt>Scope</dt><dd class="mono">{{ detail.scope || '—' }}</dd></div>
          <div><dt>Credential</dt><dd>{{ detail.credential }}</dd></div>
          <div><dt>Target</dt><dd>{{ detail.target_type || '—' }} {{ detail.target_uuid || '' }}</dd></div>
          <div><dt>Request id</dt><dd class="mono">{{ detail.request_id }}</dd></div>
          <div><dt>When</dt><dd>{{ when(detail.at) }}</dd></div>
        </div>
        <div v-if="detail.metadata && Object.keys(detail.metadata).length" class="metabox">
          <p class="label">Metadata (redacted)</p>
          <pre class="mono metabox__pre">{{ JSON.stringify(detail.metadata, null, 2) }}</pre>
        </div>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-5); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.tabs--sub { border: none; margin-bottom: var(--sp-3); }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3);
  border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab--sm { padding: 5px 12px; text-transform: capitalize; }
.tab:hover { color: var(--ink); }
.tab--active { color: var(--ink); border-bottom-color: var(--purple); }

.pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: var(--r-pill);
  font-size: var(--text-xs); font-weight: 600; }
.pill--sm { padding: 2px 8px; text-transform: capitalize; }
.pill__dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.pill--success { background: var(--success-soft); color: var(--success-ink); }
.pill--warning { background: var(--warning-soft); color: var(--warning-ink); }
.pill--danger { background: var(--danger-soft); color: var(--danger-ink); }
.pill--neutral { background: var(--paper-2); color: var(--ink-2); }

.mono { font-family: var(--font-mono); font-size: 0.92em; }
.h3 { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.empty { color: var(--ink-3); font-size: var(--text-sm); text-align: center; }
.empty--pad { padding: var(--sp-16) 0; }

/* setup / not-configured */
.setup { display: grid; justify-items: start; gap: var(--sp-3); max-width: 640px; }
.setup__icon { width: 44px; height: 44px; border-radius: var(--r-md); background: var(--purple-soft); color: var(--purple-ink); display: grid; place-items: center; }
.setup__icon svg { width: 22px; height: 22px; }
.setup__title { font-family: var(--font-display); font-size: var(--text-xl); font-weight: 500; }
.setup__lead { color: var(--ink-2); line-height: 1.6; }
.setup__steps { list-style: none; display: grid; gap: 10px; counter-reset: s; margin: var(--sp-2) 0; }
.setup__steps li { display: grid; grid-template-columns: auto 1fr; gap: 10px 12px; align-items: baseline; font-size: var(--text-sm); color: var(--ink-2); }
.setup__steps li::before { counter-increment: s; content: counter(s); width: 22px; height: 22px; border-radius: 50%;
  background: var(--paper-2); color: var(--ink-3); display: grid; place-items: center; font-size: 11px; font-weight: 700; grid-row: span 2; }
.setup__steps li[data-done="true"]::before { content: "✓"; background: var(--success-soft); color: var(--success-ink); }
.setup__steps span { font-weight: 600; color: var(--ink); }
.setup__steps code { font-family: var(--font-mono); font-size: 12px; background: var(--paper-2); padding: 1px 6px; border-radius: 5px; }

.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--sp-3); margin-bottom: var(--sp-5); }
.stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: var(--sp-4); box-shadow: var(--sh-1); display: grid; gap: 5px; }
.stat__k { font-size: var(--text-xs); color: var(--ink-3); }
.stat__v { font-family: var(--font-display); font-size: var(--text-xl); font-weight: 500; }
.stat__v--mono { font-family: var(--font-mono); font-size: var(--text-sm); }

.cols { display: flex; gap: var(--sp-5); align-items: flex-start; flex-wrap: wrap; }
.cols .grow { min-width: 280px; }
.mini { display: flex; gap: var(--sp-6); margin-bottom: var(--sp-4); }
.mini__n { font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 500; display: block; line-height: 1; }
.mini__n--bad { color: var(--danger-ink); }
.mini__l { font-size: var(--text-xs); color: var(--ink-3); }
.facts { display: grid; gap: 0; }
.facts > div { display: flex; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--line); font-size: var(--text-sm); }
.facts dt { color: var(--ink-3); }

.tl { list-style: none; display: grid; gap: var(--sp-3); }
.tl__i { display: grid; grid-template-columns: 12px 1fr; gap: 10px; }
.tl__dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; }
.tl__dot--success { background: var(--success); } .tl__dot--warning { background: var(--warning); } .tl__dot--danger { background: var(--danger); }
.tl__t { font-size: var(--text-sm); } .tl__m { font-size: var(--text-xs); margin-top: 1px; }

.rowhead { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-4); margin-bottom: var(--sp-3); }
.rowhead .faint { max-width: 60ch; font-size: var(--text-sm); }
.list { padding: 6px; }
.cred { display: grid; grid-template-columns: 36px 1fr auto auto; align-items: center; gap: var(--sp-3); padding: 12px var(--sp-4); }
.cred + .cred { border-top: 1px solid var(--line); }
.cred__icon { width: 34px; height: 34px; border-radius: var(--r-sm); background: var(--paper-2); color: var(--ink-3); display: grid; place-items: center; }
.cred__icon svg { width: 17px; height: 17px; }
.cred__id { font-family: var(--font-mono); font-size: var(--text-sm); }
.chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.chip { background: var(--paper-2); color: var(--ink-2); padding: 1px 7px; border-radius: var(--r-pill); font-size: 11px; }
.cred__meta { display: grid; justify-items: end; gap: 3px; text-align: right; font-size: var(--text-xs); }
.tag { font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; font-weight: 600; color: var(--ink-3); background: var(--paper-2); padding: 1px 6px; border-radius: 4px; }
.tag--write { background: var(--warning-soft); color: var(--warning-ink); }

.scopegroup { margin-bottom: var(--sp-5); }
.scope { display: grid; grid-template-columns: 1fr auto; gap: var(--sp-3); padding: 12px var(--sp-4); align-items: center; }
.scope + .scope { border-top: 1px solid var(--line); }
.scope__top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.scope__name { font-size: var(--text-sm); }
.scope__desc { font-size: var(--text-sm); color: var(--ink-3); margin-top: 4px; }
.scope__holders { text-align: right; font-size: var(--text-xs); max-width: 24ch; }

.act { display: grid; grid-template-columns: auto 2fr 1.2fr 1fr auto; gap: var(--sp-3); align-items: center; width: 100%;
  padding: 10px var(--sp-4); text-align: left; border-radius: var(--r-sm); }
.act:hover { background: var(--surface-2); }
.act + .act { border-top: 1px solid var(--line); }
.act__ep { font-size: var(--text-sm); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.act__m { color: var(--purple-ink); font-weight: 600; }
.act__scope, .act__cred, .act__at { font-size: var(--text-xs); white-space: nowrap; }
.pager { display: flex; align-items: center; justify-content: center; gap: var(--sp-4); margin-top: var(--sp-4); }

.diaggrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sp-3); margin-bottom: var(--sp-5); }
.dcard { background: var(--surface); border: 1px solid var(--line); border-left: 3px solid var(--line-strong); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); display: grid; gap: 4px; box-shadow: var(--sh-1); }
.dcard[data-ok="true"] { border-left-color: var(--success); }
.dcard[data-ok="false"] { border-left-color: var(--warning); }
.dcard__k { font-size: var(--text-xs); color: var(--ink-3); }
.dcard__v { font-size: var(--text-base); font-weight: 550; }
.setrows { display: grid; }
.setrow { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sp-4); padding: 12px 0; border-top: 1px solid var(--line); }
.setrow code { font-size: var(--text-sm); }
.setrow__note { font-size: var(--text-xs); margin-top: 3px; max-width: 52ch; }
.setrow__v { font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 600; white-space: nowrap; }

.err { color: var(--danger-ink); font-size: var(--text-sm); font-weight: 500; margin-top: var(--sp-3); }
.steps { margin-top: var(--sp-5); border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; }
.steps__head { padding: 10px var(--sp-4); font-weight: 600; font-size: var(--text-sm); }
.steps__head--ok { background: var(--success-soft); color: var(--success-ink); }
.steps__head--bad { background: var(--danger-soft); color: var(--danger-ink); }
.step { display: grid; grid-template-columns: 22px 1fr; gap: 10px; padding: 10px var(--sp-4); align-items: start; }
.step + .step { border-top: 1px solid var(--line); }
.step__mark { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; }
.step[data-ok="true"] .step__mark { background: var(--success-soft); color: var(--success-ink); }
.step[data-ok="false"] .step__mark { background: var(--danger-soft); color: var(--danger-ink); }
.step__label { font-size: var(--text-sm); font-weight: 500; }
.step__detail { font-size: var(--text-xs); margin-top: 2px; }

.scopepick { display: grid; gap: var(--sp-3); max-height: 320px; overflow-y: auto; border: 1px solid var(--line); border-radius: var(--r-md); padding: var(--sp-3); }
.scopepick__domain { font-size: var(--text-xs); font-weight: 700; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.scopepick__opt { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: var(--text-sm); cursor: pointer; }

.gen { display: grid; gap: var(--sp-3); }
.gen__title { font-size: var(--text-base); }
.gen__warn { font-size: var(--text-sm); color: var(--warning-ink); background: var(--warning-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); font-weight: 500; }
.gen__line { width: 100%; font-size: 12px; word-break: break-all; }
.gen__inst { font-size: var(--text-xs); line-height: 1.6; }

.dl { display: grid; gap: 0; }
.dl > div { display: flex; justify-content: space-between; gap: var(--sp-3); padding: 9px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); }
.dl dt { color: var(--ink-3); }
.metabox { margin-top: var(--sp-4); }
.metabox__pre { background: var(--paper-2); padding: var(--sp-3); border-radius: var(--r-sm); font-size: 12px; overflow-x: auto; margin-top: 6px; }

@media (max-width: 900px) {
  .stats { grid-template-columns: repeat(2, 1fr); }
  .diaggrid { grid-template-columns: 1fr; }
  .cols { flex-direction: column; } .cols section { width: 100% !important; }
  .act { grid-template-columns: auto 1fr auto; } .act__scope, .act__cred { display: none; }
  .cred { grid-template-columns: 32px 1fr auto; } .cred__meta { display: none; }
}
</style>
