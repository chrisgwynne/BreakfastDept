<script setup lang="ts">
import { onMounted, ref, reactive, computed } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { WebsiteIndex, WebsiteListItem, WebsiteEditor, WebsiteField, MediaFile, MediaList } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import StatusPill from '@/components/StatusPill.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const canEdit = computed(() => auth.can('website.edit') || auth.can('admin'))
const canPublish = computed(() => auth.can('website.publish') || auth.can('admin'))

const data = ref<WebsiteIndex | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

// editor
const editor = ref<WebsiteEditor | null>(null)
const values = reactive<Record<string, string | boolean>>({})
const original = reactive<Record<string, string | boolean>>({})
const fieldErrors = reactive<Record<string, string>>({})
const opening = ref(false)
const busy = ref('')

const TEXTAREAS = ['textarea', 'writer', 'markdown']
const NUMERIC = ['number', 'range']

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.get<WebsiteIndex>('/website')
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

async function open(item: WebsiteListItem) {
  opening.value = true
  clearErrors()
  try {
    const ed = await api.get<WebsiteEditor>(`/website/page/${item.id}`)
    for (const key of Object.keys(values)) delete values[key]
    applyEditor(ed)
    void loadMedia()
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Could not open that page.')
  } finally {
    opening.value = false
  }
}

function close() {
  editor.value = null
  media.value = []
  clearErrors()
}

// --- media library ---
const media = ref<MediaFile[]>([])
const maxBytes = ref(0)
const mediaBusy = ref('')
const uploadInput = ref<HTMLInputElement | null>(null)
async function loadMedia() {
  if (!editor.value) return
  try {
    const res = await api.get<MediaList>(`/website/page/${editor.value.id}/media`)
    media.value = res.items
    maxBytes.value = res.max_bytes
  } catch { media.value = [] }
}
function pickFile() {
  uploadInput.value?.click()
}
async function onFilePicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = '' // allow re-picking the same file
  if (!file || !editor.value || mediaBusy.value) return
  if (maxBytes.value && file.size > maxBytes.value) {
    ui.toast(`That file is too large (max ${Math.round(maxBytes.value / 1024 / 1024)} MB).`)
    return
  }
  mediaBusy.value = 'upload'
  try {
    const form = new FormData()
    form.append('file', file)
    await api.upload(`/website/page/${editor.value.id}/media`, form)
    ui.toast('File uploaded')
    await loadMedia()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Upload failed') }
  finally { mediaBusy.value = '' }
}
async function deleteMedia(f: MediaFile) {
  if (!editor.value || mediaBusy.value) return
  const warn = f.usage > 0
    ? `“${f.filename}” is used in ${f.usage} place${f.usage === 1 ? '' : 's'}. Delete it anyway?`
    : `Delete “${f.filename}”? This cannot be undone.`
  if (!window.confirm(warn)) return
  mediaBusy.value = f.filename
  try {
    await api.del(`/website/page/${editor.value.id}/media/${encodeURIComponent(f.filename)}${f.usage > 0 ? '?force=1' : ''}`)
    ui.toast('File deleted')
    await loadMedia()
  } catch (e) { ui.toast(e instanceof ApiError ? e.message : 'Could not delete the file') }
  finally { mediaBusy.value = '' }
}
function copyRef(f: MediaFile) {
  navigator.clipboard?.writeText(f.filename)
  ui.toast('Filename copied — paste it into an image field')
}

function clearErrors() {
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
}

function inputType(f: WebsiteField): string {
  if (f.type === 'email') return 'email'
  if (f.type === 'url') return 'url'
  if (f.type === 'tel') return 'tel'
  if (f.type === 'date') return 'date'
  if (f.type === 'time') return 'time'
  if (NUMERIC.includes(f.type)) return 'number'
  return 'text'
}

async function saveDraft() {
  if (!editor.value || busy.value) return
  busy.value = 'save'
  clearErrors()
  try {
    // Only send fields the user actually changed. Legacy content can already
    // exceed a blueprint limit in a field they never touched — resubmitting it
    // would fail validation for no reason.
    const changed: Record<string, string | boolean> = {}
    for (const k of Object.keys(values)) {
      if (values[k] !== original[k]) changed[k] = values[k]
    }
    if (Object.keys(changed).length === 0) {
      busy.value = ''
      ui.toast('No changes to save')
      return
    }
    const ed = await api.patch<WebsiteEditor>(`/website/page/${editor.value.id}`, changed)
    applyEditor(ed)
    ui.toast('Draft saved')
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) {
      Object.assign(fieldErrors, e.fields)
      ui.toast('Please fix the highlighted fields.')
    } else {
      ui.toast(e instanceof ApiError ? e.message : 'Could not save the draft.')
    }
  } finally {
    busy.value = ''
  }
}

async function act(action: 'publish' | 'discard' | 'unpublish' | 'republish') {
  if (!editor.value || busy.value) return
  busy.value = action
  try {
    const ed = await api.post<WebsiteEditor>(`/website/page/${editor.value.id}/${action}`)
    applyEditor(ed)
    const msg: Record<string, string> = {
      publish: 'Published — changes are live',
      discard: 'Draft discarded',
      unpublish: 'Page taken offline',
      republish: 'Page is live again',
    }
    ui.toast(msg[action])
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.message : 'Action failed')
  } finally {
    busy.value = ''
  }
}

function applyEditor(ed: WebsiteEditor) {
  editor.value = ed
  for (const f of ed.fields) {
    const v = f.value ?? (f.type === 'toggle' ? false : '')
    values[f.name] = v
    original[f.name] = v
  }
}

function previewUrl(): string {
  return editor.value ? `/breakfast-admin/preview/page/${editor.value.id}` : '#'
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader eyebrow="Content" title="Website"
                sub="Edit the live public site — save a draft, preview it, then publish when you’re happy.">
      <template #actions>
        <a v-if="data" class="btn btn--sm" :href="data.url" target="_blank" rel="noopener">
          <NavIcon name="globe" /> View site</a>
      </template>
    </PageHeader>

    <DataState :loading="loading" :error="error" :empty="!!data && !data.items.length"
               empty-title="No pages found" empty-note="Editable pages of your website will appear here."
               @retry="load">
      <div v-if="data" class="card list">
        <button v-for="p in data.items" :key="p.id" class="page" @click="open(p)" :disabled="opening">
          <span class="page__icon"><NavIcon :name="p.kind === 'site' ? 'cog' : (p.home ? 'grid' : 'globe')" /></span>
          <span class="page__main">
            <strong class="truncate">{{ p.title || p.id }}<span v-if="p.home" class="page__badge">Home</span></strong>
            <span class="faint truncate">{{ p.kind === 'site' ? 'Global business details, SEO defaults & socials' : '/' + p.id + ' · ' + p.template }}</span>
          </span>
          <StatusPill :status="p.status" />
          <NavIcon name="arrow" class="page__go" />
        </button>
      </div>
    </DataState>

    <p class="note">Text, toggles, links’ labels and SEO fields are editable here. Rich sections built from
      structured blocks, galleries and file pickers stay in the advanced content editor and are shown read-only.</p>

    <!-- Editor -->
    <Sheet :open="!!editor" :eyebrow="editor?.kind === 'site' ? 'Global' : (editor?.template || 'Page')"
           :title="editor?.title || 'Edit'" @close="close">
      <template v-if="editor">
        <div class="edrow">
          <StatusPill :status="editor.status" />
          <a v-if="editor.kind === 'page'" class="btn btn--sm" :href="previewUrl()" target="_blank" rel="noopener">
            <NavIcon name="window" /> Preview draft</a>
          <a class="btn btn--sm" :href="editor.url" target="_blank" rel="noopener"><NavIcon name="globe" /> Live</a>
        </div>

        <div class="fields">
          <div v-for="f in editor.fields" :key="f.name" class="field"
               :class="{ 'field--half': f.width === '1/2', 'field--toggle': f.type === 'toggle' }">
            <template v-if="f.type === 'toggle'">
              <label class="toggle">
                <input type="checkbox" v-model="values[f.name] as boolean" :disabled="!canEdit" />
                <span>{{ f.label }}</span>
              </label>
            </template>
            <template v-else>
              <label class="label">{{ f.label }}<span v-if="f.required" class="req">*</span></label>
              <textarea v-if="TEXTAREAS.includes(f.type)" class="textarea" rows="3"
                        v-model="values[f.name] as string" :disabled="!canEdit"></textarea>
              <select v-else-if="f.options && f.options.length" class="input" v-model="values[f.name] as string" :disabled="!canEdit">
                <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <input v-else class="input" :type="inputType(f)" v-model="values[f.name] as string" :disabled="!canEdit" />
            </template>
            <p v-if="f.help" class="fhelp">{{ f.help }}</p>
            <p v-if="fieldErrors[f.name]" class="ferr" role="alert">{{ fieldErrors[f.name] }}</p>
          </div>
        </div>

        <div v-if="editor.readonly.length" class="readonly">
          <p class="label">Advanced fields (edited in the content editor)</p>
          <div class="rochips">
            <span v-for="f in editor.readonly" :key="f.name" class="rochip">{{ f.label }} <em>{{ f.type }}</em></span>
          </div>
        </div>

        <!-- Media library for this page -->
        <div class="media">
          <div class="media__head">
            <p class="label">Media</p>
            <button v-if="canEdit" class="btn btn--sm" :disabled="mediaBusy === 'upload'" @click="pickFile">
              <NavIcon name="plus" /> {{ mediaBusy === 'upload' ? 'Uploading…' : 'Upload file' }}</button>
            <input ref="uploadInput" class="visually-hidden" type="file" accept="image/*,.pdf" @change="onFilePicked" />
          </div>
          <p v-if="!media.length" class="faint sm">No files yet. Upload images or PDFs to use on this page.</p>
          <div v-else class="media__grid">
            <div v-for="f in media" :key="f.filename" class="mediaitem">
              <div class="mediaitem__thumb">
                <img v-if="f.is_image" :src="f.url" :alt="f.filename" loading="lazy" />
                <NavIcon v-else name="window" />
              </div>
              <div class="mediaitem__meta">
                <strong class="truncate" :title="f.filename">{{ f.filename }}</strong>
                <span class="faint">{{ f.nice_size }}<template v-if="f.width"> · {{ f.width }}×{{ f.height }}</template>
                  <template v-if="f.usage"> · used {{ f.usage }}×</template></span>
              </div>
              <div class="mediaitem__actions">
                <button class="iconbtn" title="Copy filename" @click="copyRef(f)"><NavIcon name="check" /></button>
                <button v-if="canEdit" class="iconbtn iconbtn--danger" title="Delete" :disabled="!!mediaBusy" @click="deleteMedia(f)">×</button>
              </div>
            </div>
          </div>
        </div>
      </template>

      <template #footer>
        <button v-if="editor && editor.has_changes && canEdit" class="btn btn--sm" :disabled="!!busy" @click="act('discard')">Discard draft</button>
        <div class="grow"></div>
        <button v-if="editor && editor.can_unpublish && canPublish && editor.status !== 'unlisted'" class="btn btn--sm" :disabled="!!busy" @click="act('unpublish')">Take offline</button>
        <button v-if="editor && editor.status === 'unlisted' && canPublish" class="btn btn--sm" :disabled="!!busy" @click="act('republish')">Put online</button>
        <button v-if="canEdit" class="btn btn--sm" :disabled="!!busy" @click="saveDraft">{{ busy === 'save' ? 'Saving…' : 'Save draft' }}</button>
        <button v-if="editor && editor.has_changes && canPublish" class="btn btn--primary btn--sm" :disabled="!!busy" @click="act('publish')">{{ busy === 'publish' ? 'Publishing…' : 'Publish' }}</button>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.list { padding: 6px; }
.page { display: grid; grid-template-columns: 38px 1fr auto auto; align-items: center; gap: var(--sp-3);
  width: 100%; padding: 12px var(--sp-4); border-radius: var(--r-md); text-align: left; transition: background var(--dur-1); }
.page:hover { background: var(--surface-2); }
.page + .page { border-top: 1px solid var(--line); }
.page__icon { width: 34px; height: 34px; border-radius: var(--r-sm); background: var(--paper-2); color: var(--ink-3); display: grid; place-items: center; }
.page__icon svg { width: 17px; height: 17px; }
.page__main { display: grid; min-width: 0; }
.page__main strong { font-size: var(--text-base); display: flex; align-items: center; gap: 8px; }
.page__badge { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
  background: var(--butter-soft); color: var(--on-butter); padding: 1px 6px; border-radius: var(--r-pill); }
.page__main span:last-child { font-size: var(--text-sm); }
.page__go { width: 15px; height: 15px; color: var(--ink-4); }
.note { font-size: var(--text-sm); color: var(--ink-3); line-height: 1.6; margin-top: var(--sp-5); max-width: 66ch; }

.edrow { display: flex; align-items: center; gap: var(--sp-2); margin-bottom: var(--sp-4); flex-wrap: wrap; }
.fields { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3) var(--sp-4); }
.field--half { grid-column: span 1; } .field { grid-column: 1 / -1; }
.field--half { grid-column: span 1; }
.req { color: var(--danger-ink); margin-left: 2px; }
.fhelp { font-size: var(--text-xs); color: var(--ink-3); margin-top: 4px; line-height: 1.5; }
.ferr { font-size: var(--text-xs); color: var(--danger-ink); margin-top: 4px; }

.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
.media { margin-top: var(--sp-5); padding-top: var(--sp-4); border-top: 1px solid var(--line); }
.media__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sp-3); }
.media__grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-2); }
.mediaitem { display: grid; grid-template-columns: 44px 1fr auto; align-items: center; gap: var(--sp-2);
  border: 1px solid var(--line); border-radius: var(--r-md); padding: 6px 8px; background: var(--surface); }
.mediaitem__thumb { width: 44px; height: 44px; border-radius: var(--r-sm); overflow: hidden; background: var(--paper-2);
  display: grid; place-items: center; color: var(--ink-3); }
.mediaitem__thumb img { width: 100%; height: 100%; object-fit: cover; }
.mediaitem__meta { display: grid; min-width: 0; }
.mediaitem__meta strong { font-size: var(--text-sm); }
.mediaitem__meta span { font-size: var(--text-xs); }
.mediaitem__actions { display: flex; gap: 2px; }
.iconbtn--danger:hover { color: var(--danger-ink); }
.sm { font-size: var(--text-sm); }
@media (max-width: 620px) { .media__grid { grid-template-columns: 1fr; } }
.toggle { display: flex; align-items: center; gap: 10px; font-size: var(--text-base); font-weight: 500; padding: 6px 0; }
.toggle input { width: 18px; height: 18px; }
.readonly { margin-top: var(--sp-5); padding-top: var(--sp-4); border-top: 1px solid var(--line); }
.rochips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: var(--sp-2); }
.rochip { font-size: var(--text-xs); color: var(--ink-3); background: var(--paper-2); border: 1px solid var(--line);
  padding: 4px 10px; border-radius: var(--r-pill); }
.rochip em { color: var(--ink-4); font-style: normal; font-family: var(--font-mono); font-size: 0.85em; }
.grow { flex: 1; }
@media (max-width: 620px) { .fields { grid-template-columns: 1fr; } .field--half { grid-column: 1 / -1; } }
</style>
