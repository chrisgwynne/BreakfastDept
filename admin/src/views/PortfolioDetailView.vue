<script setup lang="ts">
import { onMounted, ref, reactive, computed, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type {
  PortfolioRecord,
  PortfolioStorySection,
  PortfolioResult,
  PortfolioMediaItem,
  PortfolioEvent,
} from '@/lib/types'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import NavIcon from '@/components/NavIcon.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('portfolio.manage') || auth.can('admin'))
const canPublish = computed(() => auth.can('portfolio.publish') || auth.can('admin'))

const id = computed(() => String(route.params.id))
const record = ref<PortfolioRecord | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

type Tab = 'overview' | 'story' | 'results' | 'media' | 'seo' | 'homepage' | 'history'
const tab = ref<Tab>('overview')

// Editable overview + SEO fields, mirrored into a reactive form. Autosave sends
// only what changed, guarded by the record's revision (optimistic concurrency).
const form = reactive<Record<string, string | number>>({})
const OVERVIEW_FIELDS = [
  'internal_name', 'display_title', 'card_title', 'case_study_headline', 'client_display_name',
  'summary', 'slug', 'project_type', 'industry', 'location', 'website_url', 'link_label',
  'completion_date', 'launch_date',
]
const SEO_FIELDS = ['seo_title', 'seo_description', 'og_title', 'og_description', 'robots']

const saving = ref(false)
const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle')
const conflict = ref(false)
let saveTimer: ReturnType<typeof setTimeout> | undefined

function hydrateForm(r: PortfolioRecord) {
  for (const f of [...OVERVIEW_FIELDS, ...SEO_FIELDS]) {
    form[f] = (r[f] as string | number | null) ?? ''
  }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const r = await api.get<PortfolioRecord>('/portfolio/' + id.value)
    record.value = r
    hydrateForm(r)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Error'
  } finally {
    loading.value = false
  }
}

// Collect only fields that differ from the loaded record.
function changedFields(): Record<string, string | number> {
  const r = record.value
  if (!r) return {}
  const out: Record<string, string | number> = {}
  for (const f of [...OVERVIEW_FIELDS, ...SEO_FIELDS]) {
    const cur = form[f] ?? ''
    const orig = (r[f] as string | number | null) ?? ''
    if (String(cur) !== String(orig)) out[f] = cur
  }
  return out
}

async function save() {
  const r = record.value
  if (!r || !canManage.value || saving.value) return
  const patch = changedFields()
  if (Object.keys(patch).length === 0) return
  saving.value = true
  saveState.value = 'saving'
  try {
    const updated = await api.patch<PortfolioRecord>('/portfolio/' + id.value, {
      ...patch,
      revision: r.revision,
    })
    // Merge the returned flat row (new revision/slug/status) without dropping
    // the child collections we already hold.
    record.value = { ...r, ...updated }
    hydrateForm(record.value)
    saveState.value = 'saved'
    conflict.value = false
  } catch (e) {
    saveState.value = 'error'
    if (e instanceof ApiError && e.code === 'revision_conflict') {
      conflict.value = true
    } else {
      ui.toast(e instanceof ApiError ? e.message : 'Could not save')
    }
  } finally {
    saving.value = false
  }
}

// Debounced autosave: any edit schedules a save ~800ms after typing stops.
function scheduleSave() {
  saveState.value = 'saving'
  clearTimeout(saveTimer)
  saveTimer = setTimeout(save, 800)
}
watch(form, scheduleSave, { deep: true })

// ---- Story ------------------------------------------------------------------
const STORY = [
  ['client', 'The client'],
  ['problem', 'The problem'],
  ['objective', 'The objective'],
  ['approach', 'Our approach'],
  ['work', 'The work'],
  ['outcome', 'The outcome'],
  ['client_response', 'In their words'],
  ['next', "What's next"],
] as const

const storyDraft = reactive<Record<string, { body: string; visible: boolean }>>({})
const storySaving = ref('')

function hydrateStory() {
  for (const [key] of STORY) {
    const existing = (record.value?.story ?? []).find((s) => s.section_key === key)
    storyDraft[key] = {
      body: existing ? String(existing.body ?? '') : '',
      visible: existing ? existing.visible === 1 : true,
    }
  }
}

async function saveStory(key: string) {
  if (!canManage.value) return
  storySaving.value = key
  try {
    const section = await api.post<PortfolioStorySection>('/portfolio/' + id.value + '/story/' + key, {
      body: storyDraft[key].body,
      visible: storyDraft[key].visible,
    })
    const list = record.value?.story ?? []
    const i = list.findIndex((s) => s.section_key === key)
    if (i >= 0) list[i] = section
    else list.push(section)
    if (record.value) record.value.story = list
    ui.toast('Story saved')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not save section')
  } finally {
    storySaving.value = ''
  }
}

// ---- Results ----------------------------------------------------------------
const resultDraft = reactive<{ label: string; value: string; note: string }>({ label: '', value: '', note: '' })

async function addResult() {
  if (!canManage.value || !resultDraft.label.trim() || !resultDraft.value.trim()) return
  try {
    const res = await api.post<PortfolioResult>('/portfolio/' + id.value + '/results', {
      label: resultDraft.label.trim(),
      value: resultDraft.value.trim(),
      note: resultDraft.note.trim() || undefined,
    })
    record.value?.results.push(res)
    Object.assign(resultDraft, { label: '', value: '', note: '' })
    ui.toast('Result added')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not add result')
  }
}

async function deleteResult(r: PortfolioResult) {
  if (!canManage.value) return
  try {
    await api.del('/portfolio/' + id.value + '/results/' + r.uuid)
    if (record.value) record.value.results = record.value.results.filter((x) => x.uuid !== r.uuid)
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not remove result')
  }
}

// ---- Media ------------------------------------------------------------------
const uploading = ref(false)
const uploadErr = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function pickFile() {
  uploadErr.value = ''
  fileInput.value?.click()
}

async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !record.value) return
  uploading.value = true
  uploadErr.value = ''
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('role', 'gallery')
    const media = await api.upload<PortfolioMediaItem>('/portfolio/' + id.value + '/media/upload', form)
    record.value.media.push(media)
    if (media.warnings?.length) ui.toast(media.warnings[0])
    else ui.toast('Image uploaded')
  } catch (err) {
    uploadErr.value = err instanceof ApiError ? err.message : 'Upload failed'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function saveAlt(m: PortfolioMediaItem) {
  try {
    await api.patch('/portfolio/' + id.value + '/media/' + m.uuid, { alt: m.alt })
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not save alt text')
  }
}

async function toggleApprove(m: PortfolioMediaItem) {
  try {
    const updated = await api.post<PortfolioMediaItem>('/portfolio/' + id.value + '/media/' + m.uuid + '/approve', {
      approved: m.approved_for_public !== 1,
    })
    Object.assign(m, updated)
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not update approval')
  }
}

async function archiveMedia(m: PortfolioMediaItem) {
  try {
    await api.post('/portfolio/' + id.value + '/media/' + m.uuid + '/archive', { archived: true })
    if (record.value) record.value.media = record.value.media.filter((x) => x.uuid !== m.uuid)
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not archive image')
  }
}

async function setCover(m: PortfolioMediaItem) {
  if (!record.value) return
  form.cover_media_uuid = m.uuid
  try {
    const updated = await api.patch<PortfolioRecord>('/portfolio/' + id.value, {
      cover_media_uuid: m.uuid,
      revision: record.value.revision,
    })
    record.value = { ...record.value, ...updated }
    ui.toast('Cover image set')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not set cover')
  }
}

function firstVariant(m: PortfolioMediaItem): string {
  try {
    const v = JSON.parse(m.variants || '{}') as Record<string, string>
    return v.thumb || v.card || v.original || ''
  } catch {
    return ''
  }
}

// ---- Validation + transitions ----------------------------------------------
const blockers = ref<string[]>([])
const checking = ref(false)

async function checkValidation() {
  checking.value = true
  try {
    blockers.value = (await api.get<{ blockers: string[] }>('/portfolio/' + id.value + '/validate')).blockers
  } catch {
    blockers.value = []
  } finally {
    checking.value = false
  }
}

const transitioning = ref('')
const scheduleAt = ref('')

async function doTransition(action: string, body: Record<string, unknown> = {}) {
  if (transitioning.value) return
  transitioning.value = action
  try {
    const updated = await api.post<PortfolioRecord>('/portfolio/' + id.value + '/' + action, body)
    if (record.value) record.value = { ...record.value, ...updated }
    ui.toast('Status updated')
    await Promise.all([checkValidation(), refreshHistory()])
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not change status')
  } finally {
    transitioning.value = ''
  }
}

async function schedule() {
  if (!scheduleAt.value) {
    ui.toast('Pick a date and time first')
    return
  }
  // datetime-local → ISO 8601.
  await doTransition('schedule', { scheduled_at: new Date(scheduleAt.value).toISOString() })
}

// Available actions per current status (mirrors the server state machine).
const actions = computed<Array<{ action: string; label: string; primary?: boolean; publishGate?: boolean }>>(() => {
  const s = record.value?.status
  switch (s) {
    case 'draft':
      return [{ action: 'submit-review', label: 'Submit for review', primary: true }]
    case 'internal_review':
      return [
        { action: 'mark-ready', label: 'Approve — mark ready', primary: true },
        { action: 'request-changes', label: 'Request changes' },
      ]
    case 'changes_requested':
      return [{ action: 'submit-review', label: 'Re-submit for review', primary: true }]
    case 'ready':
      return [
        { action: 'publish', label: 'Publish now', primary: true, publishGate: true },
        { action: 'request-changes', label: 'Request changes' },
      ]
    case 'scheduled':
      return [
        { action: 'publish', label: 'Publish now', primary: true, publishGate: true },
        { action: 'unpublish', label: 'Cancel schedule', publishGate: true },
      ]
    case 'published':
      return [{ action: 'unpublish', label: 'Unpublish', publishGate: true }]
    case 'unpublished':
      return [
        { action: 'publish', label: 'Re-publish', primary: true, publishGate: true },
        { action: 'archive', label: 'Archive' },
      ]
    case 'archived':
      return [{ action: 'restore', label: 'Restore to draft', primary: true }]
    default:
      return []
  }
})

const canSchedule = computed(() => ['ready', 'scheduled'].includes(record.value?.status ?? ''))

// ---- History ----------------------------------------------------------------
const history = ref<PortfolioEvent[]>([])
async function refreshHistory() {
  try {
    history.value = (await api.get<{ events: PortfolioEvent[] }>('/portfolio/' + id.value + '/history')).events
  } catch {
    history.value = []
  }
}

// ---- Duplicate / public link ------------------------------------------------
async function duplicate() {
  if (!canManage.value) return
  try {
    const rec = await api.post<{ uuid: string }>('/portfolio/' + id.value + '/duplicate', {})
    ui.toast('Duplicated to a new draft')
    router.push({ name: 'portfolio-record', params: { id: rec.uuid } })
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not duplicate')
  }
}

const publicUrl = computed(() =>
  record.value?.status === 'published' ? '/work/' + record.value.slug : '',
)

function when(d: string | null): string {
  if (!d) return '—'
  const t = new Date(d)
  return isNaN(t.getTime()) ? d : t.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

watch(record, (r) => {
  if (r) hydrateStory()
})

watch(tab, (t) => {
  if (t === 'history' && !history.value.length) refreshHistory()
})

onMounted(async () => {
  await load()
  hydrateStory()
  await checkValidation()
})

onBeforeUnmount(() => clearTimeout(saveTimer))
</script>

<template>
  <div>
    <button class="btn btn--sm back" @click="router.push({ name: 'portfolio' })">
      <NavIcon name="arrow" style="transform: rotate(180deg)" /> Portfolio
    </button>

    <DataState :loading="loading" :error="error" :empty="!record" @retry="load">
      <template v-if="record">
        <header class="head">
          <div class="head__main">
            <div class="head__titlerow">
              <h1 class="head__title" data-test="portfolio-title">
                {{ record.display_title || record.internal_name || 'Untitled case study' }}
              </h1>
              <StatusPill :status="record.status" data-test="portfolio-status" />
            </div>
            <p class="head__meta faint">
              <span class="mono">/work/{{ record.slug }}</span>
              <span v-if="publicUrl"> · <a :href="publicUrl" target="_blank" rel="noopener">View live ↗</a></span>
              <span class="save" :class="'save--' + saveState" data-test="portfolio-savestate">
                <template v-if="saveState === 'saving'">Saving…</template>
                <template v-else-if="saveState === 'saved'">All changes saved</template>
                <template v-else-if="saveState === 'error'">Save failed</template>
              </span>
            </p>
          </div>
          <div class="head__actions">
            <button v-if="canManage" class="btn btn--sm" @click="duplicate">Duplicate</button>
            <button
              v-for="a in actions"
              :key="a.action"
              class="btn btn--sm"
              :class="{ 'btn--primary': a.primary }"
              :disabled="!!transitioning || (a.publishGate && !canPublish) || (a.action === 'publish' && blockers.length > 0)"
              :data-test="'portfolio-' + a.action"
              :title="a.action === 'publish' && blockers.length ? 'Resolve the checklist before publishing' : ''"
              @click="doTransition(a.action)"
            >
              {{ transitioning === a.action ? '…' : a.label }}
            </button>
          </div>
        </header>

        <div v-if="conflict" class="banner banner--warn" role="alert">
          This record was changed elsewhere since you opened it.
          <button class="link" @click="load()">Reload the latest version</button>.
        </div>

        <!-- Publish checklist -->
        <div v-if="blockers.length" class="banner banner--check" data-test="portfolio-blockers">
          <strong>Before this can be published:</strong>
          <ul>
            <li v-for="b in blockers" :key="b">{{ b }}</li>
          </ul>
        </div>

        <div class="tabs" role="tablist">
          <button v-for="t in (['overview','story','results','media','seo','homepage','history'] as Tab[])" :key="t"
                  class="tab" :class="{ 'tab--active': tab === t }" role="tab"
                  :data-test="'tab-' + t" @click="tab = t">
            {{ t === 'seo' ? 'SEO' : t.charAt(0).toUpperCase() + t.slice(1) }}
          </button>
        </div>

        <!-- OVERVIEW -->
        <section v-show="tab === 'overview'" class="card card--pad" data-test="panel-overview">
          <fieldset :disabled="!canManage" class="bare">
            <div class="field">
              <label class="label">Internal name <span class="req">private</span></label>
              <input class="input" v-model="form.internal_name" data-test="field-internal_name" />
            </div>
            <div class="grid2">
              <div class="field"><label class="label">Public project title</label><input class="input" v-model="form.display_title" data-test="field-display_title" /></div>
              <div class="field"><label class="label">Card title</label><input class="input" v-model="form.card_title" /></div>
            </div>
            <div class="grid2">
              <div class="field"><label class="label">Client display name</label><input class="input" v-model="form.client_display_name" /></div>
              <div class="field"><label class="label">Public slug</label><input class="input mono" v-model="form.slug" /></div>
            </div>
            <div class="field"><label class="label">Case-study headline</label><input class="input" v-model="form.case_study_headline" /></div>
            <div class="field">
              <label class="label">Short public introduction</label>
              <textarea class="input" rows="3" v-model="form.summary" data-test="field-summary"></textarea>
            </div>
            <div class="grid2">
              <div class="field"><label class="label">Project type</label><input class="input" v-model="form.project_type" placeholder="e.g. Website rebuild" /></div>
              <div class="field"><label class="label">Industry</label><input class="input" v-model="form.industry" placeholder="e.g. Hospitality" /></div>
            </div>
            <div class="grid2">
              <div class="field"><label class="label">Location</label><input class="input" v-model="form.location" placeholder="e.g. Conwy, North Wales" /></div>
              <div class="field"><label class="label">Live website URL</label><input class="input" v-model="form.website_url" placeholder="https://…" /></div>
            </div>
            <div class="grid2">
              <div class="field"><label class="label">Completion date</label><input class="input" type="date" v-model="form.completion_date" /></div>
              <div class="field"><label class="label">Launch date</label><input class="input" type="date" v-model="form.launch_date" /></div>
            </div>
          </fieldset>
        </section>

        <!-- STORY -->
        <section v-show="tab === 'story'" class="card card--pad" data-test="panel-story">
          <p class="lead faint">The case-study narrative. Each part is optional — write only what tells the real story, and hide the rest.</p>
          <div v-for="[key, label] in STORY" :key="key" class="story" :data-test="'story-' + key">
            <div class="story__head">
              <h3 class="story__title">{{ label }}</h3>
              <label class="toggle">
                <input type="checkbox" v-model="storyDraft[key].visible" :disabled="!canManage" /> Visible
              </label>
            </div>
            <textarea class="input" rows="4" v-model="storyDraft[key].body" :disabled="!canManage"
                      :placeholder="'Write the ' + label.toLowerCase() + '…'"></textarea>
            <div class="story__foot">
              <button class="btn btn--sm btn--primary" :disabled="!canManage || storySaving === key"
                      :data-test="'story-save-' + key" @click="saveStory(key)">
                {{ storySaving === key ? 'Saving…' : 'Save section' }}
              </button>
            </div>
          </div>
        </section>

        <!-- RESULTS -->
        <section v-show="tab === 'results'" class="card card--pad" data-test="panel-results">
          <p class="lead faint">Genuine, verifiable outcomes only. If you don't have a real figure, leave it out — never invent one.</p>
          <div class="reslist">
            <div v-for="r in record.results" :key="r.uuid" class="resrow" :data-test="'result-' + r.uuid">
              <div><strong class="resval">{{ r.value }}</strong> <span class="reslabel">{{ r.label }}</span></div>
              <span class="resnote faint">{{ r.note }}</span>
              <button v-if="canManage" class="btn btn--sm" @click="deleteResult(r)">Remove</button>
            </div>
            <p v-if="!record.results.length" class="faint empty">No results added.</p>
          </div>
          <div v-if="canManage" class="resadd">
            <input class="input" v-model="resultDraft.value" placeholder="Value (e.g. +38%)" data-test="result-value" />
            <input class="input" v-model="resultDraft.label" placeholder="Label (e.g. online bookings)" data-test="result-label" />
            <input class="input" v-model="resultDraft.note" placeholder="Note (optional context)" />
            <button class="btn btn--sm btn--primary" data-test="result-add"
                    :disabled="!resultDraft.label.trim() || !resultDraft.value.trim()" @click="addResult">Add</button>
          </div>
        </section>

        <!-- MEDIA -->
        <section v-show="tab === 'media'" class="card card--pad" data-test="panel-media">
          <div class="media__bar">
            <p class="lead faint">Every public image must be approved for use and carry alt text. Uploads are stripped of EXIF/GPS metadata automatically.</p>
            <button v-if="canManage" class="btn btn--sm btn--primary" :disabled="uploading" data-test="media-upload" @click="pickFile">
              {{ uploading ? 'Uploading…' : 'Upload image' }}
            </button>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile" />
          </div>
          <p v-if="uploadErr" class="cf__err" role="alert">{{ uploadErr }}</p>
          <div class="mediagrid">
            <div v-for="m in record.media" :key="m.uuid" class="mcard" :data-test="'media-' + m.uuid">
              <div class="mcard__img">
                <img v-if="firstVariant(m)" :src="firstVariant(m)" :alt="m.alt || ''" loading="lazy" />
                <span v-else class="mcard__ph">No preview</span>
                <span v-if="record.cover_media_uuid === m.uuid" class="mcard__cover">Cover</span>
              </div>
              <input class="input input--sm" v-model="m.alt" placeholder="Alt text (describe the image)"
                     :disabled="!canManage" @blur="saveAlt(m)" />
              <div class="mcard__row">
                <label class="toggle">
                  <input type="checkbox" :checked="m.approved_for_public === 1" :disabled="!canManage"
                         :data-test="'media-approve-' + m.uuid" @change="toggleApprove(m)" />
                  Approved for public
                </label>
              </div>
              <div v-if="canManage" class="mcard__actions">
                <button class="btn btn--xs" @click="setCover(m)" :disabled="record.cover_media_uuid === m.uuid">Set cover</button>
                <button class="btn btn--xs" @click="archiveMedia(m)">Archive</button>
              </div>
            </div>
            <p v-if="!record.media.length" class="faint empty">No images yet.</p>
          </div>
        </section>

        <!-- SEO -->
        <section v-show="tab === 'seo'" class="card card--pad" data-test="panel-seo">
          <p class="lead faint">Overrides only. Leave blank to generate sensible defaults from the public fields above.</p>
          <fieldset :disabled="!canManage" class="bare">
            <div class="field"><label class="label">SEO title</label><input class="input" v-model="form.seo_title" /></div>
            <div class="field"><label class="label">SEO description</label><textarea class="input" rows="2" v-model="form.seo_description"></textarea></div>
            <div class="grid2">
              <div class="field"><label class="label">Social (OG) title</label><input class="input" v-model="form.og_title" /></div>
              <div class="field">
                <label class="label">Search indexing</label>
                <select class="input" v-model="form.robots">
                  <option value="index">Index (visible to search engines)</option>
                  <option value="noindex">No-index (hidden from search)</option>
                </select>
              </div>
            </div>
            <div class="field"><label class="label">Social (OG) description</label><textarea class="input" rows="2" v-model="form.og_description"></textarea></div>
          </fieldset>
        </section>

        <!-- HOMEPAGE / PUBLICATION -->
        <section v-show="tab === 'homepage'" class="card card--pad" data-test="panel-homepage">
          <h3 class="story__title">Publication</h3>
          <p class="lead faint">
            This piece is currently <StatusPill :status="record.status" />.
            <span v-if="record.homepage_position !== null"> It's featured on the homepage (position {{ record.homepage_position + 1 }}).</span>
          </p>
          <p class="faint">Which pieces appear on the homepage is managed together in the
            <RouterLink :to="{ name: 'portfolio-homepage' }">homepage selection</RouterLink>, so you can order them side by side.</p>

          <div v-if="canSchedule && canPublish" class="schedule">
            <h4 class="sub">Schedule publication</h4>
            <div class="schedule__row">
              <input class="input" type="datetime-local" v-model="scheduleAt" data-test="schedule-at" />
              <button class="btn btn--sm" :disabled="!!transitioning" data-test="portfolio-schedule-btn" @click="schedule">Schedule</button>
            </div>
            <p class="hint faint">The scheduler publishes due pieces automatically ({{ 'portfolio:publish-due' }}).</p>
          </div>
        </section>

        <!-- HISTORY -->
        <section v-show="tab === 'history'" class="card card--pad" data-test="panel-history">
          <ol class="timeline">
            <li v-for="e in history" :key="e.uuid" class="tl">
              <span class="tl__action">{{ (e.detail || e.type || '').replace(/[_:]/g, ' ') }}</span>
              <span class="tl__meta faint">{{ e.actor }} · {{ when(e.created_at) }}</span>
            </li>
            <li v-if="!history.length" class="faint empty">No history yet.</li>
          </ol>
        </section>
      </template>
    </DataState>
  </div>
</template>

<style scoped>
.back { margin-bottom: var(--sp-3); }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--sp-4); flex-wrap: wrap; margin-bottom: var(--sp-3); }
.head__titlerow { display: flex; align-items: center; gap: var(--sp-3); flex-wrap: wrap; }
.head__title { font-size: var(--text-2xl); font-weight: 700; }
.head__meta { font-size: var(--text-sm); margin-top: 4px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.head__actions { display: flex; gap: 8px; flex-wrap: wrap; }
.save { font-size: var(--text-xs); }
.save--saving { color: var(--ink-3); } .save--saved { color: var(--success-ink); } .save--error { color: var(--danger-ink); }
.mono { font-family: var(--font-mono); font-size: 0.92em; }
.banner { padding: 10px var(--sp-4); border-radius: var(--r-md); margin-bottom: var(--sp-3); font-size: var(--text-sm); }
.banner--warn { background: var(--warning-soft); color: var(--warning-ink); }
.banner--check { background: var(--butter-soft, var(--warning-soft)); color: var(--ink); border: 1px solid var(--line); }
.banner--check ul { margin: 6px 0 0 18px; } .banner--check li { list-style: disc; margin: 2px 0; }
.link { color: var(--purple-ink); text-decoration: underline; }
.tabs { display: flex; gap: 4px; margin-bottom: var(--sp-4); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.tab { padding: 8px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab:hover { color: var(--ink); } .tab--active { color: var(--ink); border-bottom-color: var(--purple); }
.bare { border: 0; padding: 0; margin: 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.field { margin-bottom: var(--sp-3); }
.req { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-3); background: var(--paper-2); border-radius: var(--r-pill); padding: 1px 6px; margin-left: 6px; }
.lead { font-size: var(--text-sm); margin-bottom: var(--sp-3); }
.story { padding: var(--sp-3) 0; border-top: 1px solid var(--line); }
.story:first-of-type { border-top: 0; }
.story__head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.story__title { font-size: var(--text-lg); font-weight: 650; }
.story__foot { margin-top: 8px; }
.toggle { display: inline-flex; align-items: center; gap: 6px; font-size: var(--text-sm); color: var(--ink-2); }
.reslist { display: grid; gap: 6px; margin-bottom: var(--sp-4); }
.resrow { display: grid; grid-template-columns: 1fr 1fr auto; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); border: 1px solid var(--line); border-radius: var(--r-md); }
.resval { font-size: var(--text-lg); color: var(--purple-ink); } .reslabel { font-size: var(--text-sm); }
.resnote { font-size: var(--text-sm); }
.resadd { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 8px; }
.media__bar { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--sp-3); flex-wrap: wrap; }
.hidden { display: none; }
.mediagrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--sp-3); margin-top: var(--sp-3); }
.mcard { border: 1px solid var(--line); border-radius: var(--r-md); padding: 8px; display: grid; gap: 6px; }
.mcard__img { position: relative; aspect-ratio: 4/3; background: var(--paper-2); border-radius: var(--r-sm); overflow: hidden; display: flex; align-items: center; justify-content: center; }
.mcard__img img { width: 100%; height: 100%; object-fit: cover; }
.mcard__ph { font-size: var(--text-xs); color: var(--ink-3); }
.mcard__cover { position: absolute; top: 6px; left: 6px; background: var(--butter); color: var(--ink); font-size: 10px; font-weight: 700; border-radius: var(--r-pill); padding: 1px 7px; }
.mcard__row { font-size: var(--text-sm); }
.mcard__actions { display: flex; gap: 6px; }
.input--sm { padding: 6px 8px; font-size: var(--text-sm); }
.btn--xs { padding: 4px 8px; font-size: var(--text-xs); }
.schedule { margin-top: var(--sp-4); padding-top: var(--sp-3); border-top: 1px solid var(--line); }
.sub { font-size: var(--text-base); font-weight: 650; margin-bottom: 8px; }
.schedule__row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.hint { font-size: var(--text-xs); margin-top: 6px; }
.timeline { display: grid; gap: 8px; }
.tl { display: flex; justify-content: space-between; gap: var(--sp-3); padding: 8px 0; border-bottom: 1px solid var(--line); font-size: var(--text-sm); text-transform: capitalize; }
.tl__meta { text-transform: none; }
.empty { padding: var(--sp-3) 0; }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); margin: var(--sp-2) 0; }
@media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } .resadd { grid-template-columns: 1fr; } .resrow { grid-template-columns: 1fr; } }
</style>
