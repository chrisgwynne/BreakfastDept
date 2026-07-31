<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { portfolio, AD_OPTIONS, type CaseStudy, type Screenshot } from '@/lib/portfolio'
import { ApiError } from '@/lib/api'

const route = useRoute()
const id = String(route.params.id)

const cs = ref<CaseStudy | null>(null)
const loading = ref(true)
const saving = ref(false)
const msg = ref('')
const err = ref('')
const tab = ref<'details' | 'art' | 'blocks' | 'shots' | 'composition' | 'crop' | 'preview'>('details')

/** Editable state mirrored from the loaded case study. */
const fields = reactive<Record<string, string>>({})
const art = reactive<Record<string, string>>({})
const hosts = ref('')
const paths = ref('')
type Block = { id: string; type: string; content: Record<string, unknown> }
const blocks = ref<Block[]>([])

// Capture panel
const capViewport = ref('desktop')
const capPath = ref('/')
const capFull = ref(false)
const capturing = ref(false)

// Crop panel
const cropSource = ref('')
const crop = reactive({ x: 0.1, y: 0.1, w: 0.5, h: 0.5, kind: 'detail', rotate90: 0, label: '' })

// Composition (single cs-composition block)
type Layer = { id: string; type: string; image: string; text: string; col: number; colSpan: number; row: number; rowSpan: number; scale: number; rotation: number; frame: string; shadow: string; visible: { desktop: boolean; tablet: boolean; mobile: boolean } }
const composition = reactive<{ aspect: string; layers: Layer[]; responsive: Record<string, Record<string, unknown>>; mobileOrder: string[] }>({ aspect: '16:9', layers: [], responsive: { tablet: {}, mobile: {} }, mobileOrder: [] })

// Preview Studio
const device = ref<'desktop' | 'tablet' | 'mobile'>('desktop')
const reduced = ref(false)
const iframe = ref<HTMLIFrameElement | null>(null)
const previewNonce = ref(0)
const deviceWidth = computed(() => ({ desktop: 1280, tablet: 834, mobile: 390 }[device.value]))
const breakpoint = computed(() => (deviceWidth.value <= 640 ? 'mobile' : deviceWidth.value <= 1024 ? 'tablet' : 'desktop'))
const previewSrc = computed(() => cs.value ? `${cs.value.previewUrl}?rm=${reduced.value ? 1 : 0}&n=${previewNonce.value}` : '')

const imageWeight = computed(() => (cs.value?.media ?? []).reduce((s, m) => s + m.bytes, 0))
const perfEstimate = computed(() => (imageWeight.value / 1_000_000).toFixed(1) + ' MB')

const adKeys = ['preset', 'hero', 'ending', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'density', 'corner', 'border', 'image_scale', 'rotation', 'caption', 'pullquote', 'animation', 'transition', 'motif']

function hydrate(data: CaseStudy) {
  cs.value = data
  Object.assign(fields, data.fields)
  Object.assign(art, data.artDirection.fields)
  hosts.value = data.approvedHosts.join(', ')
  paths.value = data.approvedPaths.join(', ')
  try {
    blocks.value = JSON.parse(data.blocks || '[]')
  } catch {
    blocks.value = []
  }
  // Load an existing composition block into the editor, if any.
  const compBlock = blocks.value.find((b) => b.type === 'cs-composition')
  if (compBlock) {
    try {
      const parsed = JSON.parse(String(compBlock.content.data || '{}'))
      composition.aspect = parsed.aspect || '16:9'
      composition.layers = (parsed.layers || []).map((l: Record<string, unknown>) => ({
        id: String(l.id), type: String(l.type), image: String(l.image || ''), text: String(l.text || ''),
        col: Number(l.col || 1), colSpan: Number(l.colSpan || 6), row: Number(l.row || 1), rowSpan: Number(l.rowSpan || 4),
        scale: Number(l.scale || 1), rotation: Number(l.rotation || 0), frame: String(l.frame || 'none'), shadow: String(l.shadow || 'md'),
        visible: (l.visible as Layer['visible']) || { desktop: true, tablet: true, mobile: true },
      }))
      composition.responsive = parsed.responsive || { tablet: {}, mobile: {} }
      composition.mobileOrder = parsed.mobileOrder || []
    } catch { /* ignore */ }
  }
}

async function load() {
  loading.value = true
  try {
    hydrate(await portfolio.get(id))
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Could not load the case study.'
  } finally {
    loading.value = false
  }
}

function syncCompositionIntoBlocks() {
  if (!composition.layers.length) return
  const data = JSON.stringify(composition)
  const existing = blocks.value.find((b) => b.type === 'cs-composition')
  if (existing) existing.content.data = data
  else blocks.value.push({ id: 'comp-' + Date.now(), type: 'cs-composition', content: { data, caption: '' } })
}

async function save() {
  if (!cs.value) return
  saving.value = true
  err.value = ''
  msg.value = ''
  syncCompositionIntoBlocks()
  try {
    const patch: Record<string, unknown> = {
      ...fields,
      artDirection: Object.fromEntries(adKeys.map((k) => [k, art[k] ?? ''])),
      approvedHosts: hosts.value.split(',').map((s) => s.trim()).filter(Boolean),
      approved_paths: paths.value,
      blocks: JSON.stringify(blocks.value),
    }
    hydrate(await portfolio.save(id, patch))
    msg.value = 'Saved.'
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Could not save.'
  } finally {
    saving.value = false
  }
}

async function doPublish() {
  await save()
  try {
    hydrate(await portfolio.publish(id))
    msg.value = 'Published.'
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Could not publish.'
  }
}

// ---- Screenshots ----
async function capture() {
  if (!cs.value) return
  capturing.value = true
  err.value = ''
  try {
    await portfolio.capture(id, { viewport: capViewport.value, path: capPath.value, full: capFull.value, page: capPath.value === '/' ? 'homepage' : capPath.value.replace('/', '') })
    hydrate(await portfolio.get(id))
    msg.value = 'Screenshot captured.'
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Capture failed.'
  } finally {
    capturing.value = false
  }
}
async function approve(s: Screenshot) { hydrate(await withReload(() => portfolio.approve(id, s.uuid))) }
async function privacy(s: Screenshot) { hydrate(await withReload(() => portfolio.privacy(id, s.uuid, 'checked'))) }
async function attach(s: Screenshot) { hydrate(await portfolio.attach(id, s.uuid)) }
async function withReload<T>(fn: () => Promise<T>): Promise<CaseStudy> { await fn(); return portfolio.get(id) }

// ---- Blocks ----
function addBlock(type: string, content: Record<string, unknown> = {}) {
  blocks.value.push({ id: type.replace('cs-', '') + '-' + Date.now(), type, content })
}
function removeBlock(i: number) { blocks.value.splice(i, 1) }
function moveBlock(i: number, dir: number) {
  const j = i + dir
  if (j < 0 || j >= blocks.value.length) return
  const [b] = blocks.value.splice(i, 1)
  blocks.value.splice(j, 0, b)
}

// ---- Crop / derivative ----
const cropping = ref(false)
async function makeDerivative() {
  if (!cropSource.value) return
  cropping.value = true
  try {
    await portfolio.derivative(id, { source: cropSource.value, kind: crop.kind, crop: { x: crop.x, y: crop.y, w: crop.w, h: crop.h }, rotate90: crop.rotate90, label: crop.label })
    hydrate(await portfolio.get(id))
    msg.value = 'Derivative created.'
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Could not create derivative.'
  } finally {
    cropping.value = false
  }
}

// ---- Composition layers ----
function addLayer(type: string) {
  const n = composition.layers.length
  composition.layers.push({
    id: 'L' + Date.now() + n, type, image: cs.value?.media[0]?.filename || '', text: type === 'annotation' ? 'Note' : '',
    col: 1 + (n % 3) * 3, colSpan: 6, row: 1 + n, rowSpan: 4, scale: 1, rotation: 0,
    frame: type === 'mobile-shot' ? 'phone' : type === 'desktop-shot' ? 'browser' : 'none', shadow: 'md',
    visible: { desktop: true, tablet: true, mobile: true },
  })
}
function moveLayer(i: number, dir: number) {
  const j = i + dir
  if (j < 0 || j >= composition.layers.length) return
  const [l] = composition.layers.splice(i, 1)
  composition.layers.splice(j, 0, l)
}
function removeLayer(i: number) { composition.layers.splice(i, 1) }

// ---- Preview ----
function refreshPreview() { previewNonce.value++ }
function openPreview() { if (cs.value) window.open(previewSrc.value, '_blank', 'noopener') }
const copied = ref(false)
async function copySecureLink() {
  try {
    const { url } = await portfolio.previewLink(id)
    await navigator.clipboard.writeText(url).catch(() => {})
    copied.value = true
    msg.value = 'Secure preview link copied.'
    setTimeout(() => (copied.value = false), 2500)
  } catch (e) {
    err.value = e instanceof ApiError ? e.message : 'Could not create a link.'
  }
}

onMounted(load)
</script>

<template>
  <section class="ed" v-if="cs">
    <header class="ed__head">
      <div>
        <RouterLink :to="{ name: 'portfolio' }" class="back">← Portfolio</RouterLink>
        <h1>{{ fields.title || cs.title }}</h1>
        <span class="tag">{{ cs.status }}</span>
      </div>
      <div class="ed__actions">
        <button class="btn" :disabled="saving" @click="save" data-test="pf-save">{{ saving ? 'Saving…' : 'Save draft' }}</button>
        <button class="btn btn--primary" :disabled="!cs.publishable" @click="doPublish" data-test="pf-publish" :title="cs.blockers.join('; ')">Publish</button>
      </div>
    </header>

    <p v-if="msg" class="ok" role="status" data-test="pf-msg">{{ msg }}</p>
    <p v-if="err" class="error" role="alert" data-test="pf-err">{{ err }}</p>
    <ul v-if="cs.blockers.length" class="blockers" data-test="pf-blockers">
      <li v-for="b in cs.blockers" :key="b">⛔ {{ b }}</li>
    </ul>

    <nav class="tabs" role="tablist">
      <button v-for="t in ['details','art','blocks','shots','composition','crop','preview']" :key="t"
        class="tabs__btn" :class="{ 'is-active': tab === t }" :data-test="`tab-${t}`" @click="tab = (t as typeof tab)">{{ t }}</button>
    </nav>

    <!-- DETAILS -->
    <div v-show="tab === 'details'" class="panel">
      <label class="fld"><span>Title</span><input v-model="fields.title" data-test="f-title" /></label>
      <label class="fld"><span>Summary</span><textarea v-model="fields.summary" data-test="f-summary"></textarea></label>
      <label class="fld"><span>Client / subject</span><input v-model="fields.client" /></label>
      <label class="fld"><span>Live project URL</span><input v-model="fields.project_url" data-test="f-url" /></label>
      <label class="fld"><span>Approved capture hosts</span><input v-model="hosts" data-test="f-hosts" placeholder="acme.com, www.acme.com" /></label>
      <label class="fld"><span>Approved capture paths</span><input v-model="paths" data-test="f-paths" placeholder="/, /about, /contact" /></label>
    </div>

    <!-- ART DIRECTION -->
    <div v-show="tab === 'art'" class="panel grid2">
      <label v-for="k in adKeys" :key="k" class="fld" v-show="AD_OPTIONS[k]">
        <span>{{ k.replace('_', ' ') }}</span>
        <select v-model="art[k]" :data-test="`ad-${k}`">
          <option value="">(preset default)</option>
          <option v-for="o in AD_OPTIONS[k]" :key="o" :value="o">{{ o }}</option>
        </select>
      </label>
    </div>

    <!-- BLOCKS -->
    <div v-show="tab === 'blocks'" class="panel">
      <div class="addbar">
        <button class="btn" data-test="add-quote" @click="addBlock('cs-quote', { text: 'A quote worth the size.', kind: 'statement', style: 'giant' })">+ Giant pull quote</button>
        <button class="btn" data-test="add-statement" @click="addBlock('cs-statement', { text: 'The single biggest improvement.', kind: 'improvement' })">+ Statement</button>
        <button class="btn" data-test="add-fullpage" @click="addBlock('cs-fullpage', { image: [cs.media[0]?.filename], treatment: 'strip', caption: '' })">+ Full-page capture</button>
        <button class="btn" data-test="add-beforeafter" @click="addBlock('cs-beforeafter', { before: [cs.media[0]?.filename], after: [cs.media[1]?.filename || cs.media[0]?.filename], mode: 'slider', labels: 'Before,After' })">+ Before / after</button>
      </div>
      <ol class="blocklist" data-test="blocklist">
        <li v-for="(b, i) in blocks" :key="b.id" class="blockrow">
          <span class="blockrow__type">{{ b.type }}</span>
          <span class="spacer"></span>
          <button class="mini" @click="moveBlock(i, -1)" aria-label="Move up">↑</button>
          <button class="mini" @click="moveBlock(i, 1)" aria-label="Move down">↓</button>
          <button class="mini" @click="removeBlock(i)" aria-label="Remove">✕</button>
        </li>
      </ol>
    </div>

    <!-- SCREENSHOTS -->
    <div v-show="tab === 'shots'" class="panel">
      <div class="cap">
        <select v-model="capViewport" data-test="cap-viewport"><option>desktop</option><option>laptop</option><option>tablet</option><option>mobile</option></select>
        <input v-model="capPath" data-test="cap-path" aria-label="Path" />
        <label class="chk"><input type="checkbox" v-model="capFull" data-test="cap-full" /> full page</label>
        <button class="btn btn--primary" :disabled="capturing" @click="capture" data-test="cap-go">{{ capturing ? 'Capturing…' : 'Capture' }}</button>
      </div>
      <table class="shots" data-test="shots">
        <tr v-for="s in cs.screenshots" :key="s.uuid" :data-test="`shot-${s.viewport}`">
          <td>{{ s.viewport }}<span v-if="s.is_stub" class="tag">stub</span></td>
          <td>{{ s.width }}×{{ s.height }}</td>
          <td>
            <button class="mini" :disabled="s.public_use_approved" @click="approve(s)" :data-test="`approve-${s.uuid}`">{{ s.public_use_approved ? '✓ public use' : 'approve use' }}</button>
            <button class="mini" :disabled="s.privacy_reviewed" @click="privacy(s)" :data-test="`privacy-${s.uuid}`">{{ s.privacy_reviewed ? '✓ privacy' : 'privacy ok' }}</button>
            <button class="mini" :disabled="!(s.public_use_approved && s.privacy_reviewed)" @click="attach(s)" :data-test="`attach-${s.uuid}`">attach</button>
          </td>
        </tr>
      </table>
    </div>

    <!-- COMPOSITION -->
    <div v-show="tab === 'composition'" class="panel">
      <div class="addbar">
        <button class="btn" data-test="layer-desktop" @click="addLayer('desktop-shot')">+ Desktop</button>
        <button class="btn" data-test="layer-mobile" @click="addLayer('mobile-shot')">+ Mobile</button>
        <button class="btn" data-test="layer-annotation" @click="addLayer('annotation')">+ Annotation</button>
      </div>
      <ol class="layers" data-test="layers">
        <li v-for="(l, i) in composition.layers" :key="l.id" class="layer" :data-test="`layer-${i}`">
          <span class="layer__type">{{ l.type }}</span>
          <select v-if="l.type !== 'annotation'" v-model="l.image" class="mini-sel"><option v-for="m in cs.media" :key="m.filename" :value="m.filename">{{ m.filename }}</option></select>
          <input v-else v-model="l.text" class="mini-in" aria-label="Annotation text" />
          <label class="tiny">scale<input type="number" step="0.05" min="0.5" max="1.4" v-model.number="l.scale" :data-test="`layer-${i}-scale`" /></label>
          <label class="tiny">rot
            <select v-model.number="l.rotation" :data-test="`layer-${i}-rot`"><option :value="-8">-8</option><option :value="-5">-5</option><option :value="-3">-3</option><option :value="0">0</option><option :value="3">3</option><option :value="5">5</option><option :value="8">8</option></select>
          </label>
          <label class="tiny">tablet<input type="checkbox" v-model="l.visible.tablet" :data-test="`layer-${i}-tablet`" /></label>
          <label class="tiny">mobile<input type="checkbox" v-model="l.visible.mobile" :data-test="`layer-${i}-mobile`" /></label>
          <span class="spacer"></span>
          <button class="mini" @click="moveLayer(i, -1)" :data-test="`layer-${i}-up`" aria-label="Move up">↑</button>
          <button class="mini" @click="moveLayer(i, 1)" aria-label="Move down">↓</button>
          <button class="mini" @click="removeLayer(i)" aria-label="Remove">✕</button>
        </li>
      </ol>
      <p class="muted small">Positions are a safe 12×8 grid; rotation and scale are bounded. Nothing can overflow the canvas.</p>
    </div>

    <!-- CROP -->
    <div v-show="tab === 'crop'" class="panel">
      <label class="fld"><span>Source image</span>
        <select v-model="cropSource" data-test="crop-source"><option value="">— pick —</option><option v-for="m in cs.media" :key="m.filename" :value="m.filename">{{ m.filename }}</option></select>
      </label>
      <div class="grid2">
        <label class="fld"><span>x</span><input type="number" step="0.01" min="0" max="1" v-model.number="crop.x" data-test="crop-x" /></label>
        <label class="fld"><span>y</span><input type="number" step="0.01" min="0" max="1" v-model.number="crop.y" data-test="crop-y" /></label>
        <label class="fld"><span>w</span><input type="number" step="0.01" min="0" max="1" v-model.number="crop.w" data-test="crop-w" /></label>
        <label class="fld"><span>h</span><input type="number" step="0.01" min="0" max="1" v-model.number="crop.h" data-test="crop-h" /></label>
        <label class="fld"><span>kind</span><select v-model="crop.kind" data-test="crop-kind"><option>card</option><option>hero</option><option>social</option><option>mobile</option><option>detail</option></select></label>
        <label class="fld"><span>rotate 90°</span><select v-model.number="crop.rotate90"><option :value="0">0</option><option :value="90">90</option><option :value="180">180</option><option :value="270">270</option></select></label>
      </div>
      <button class="btn btn--primary" :disabled="cropping || !cropSource" @click="makeDerivative" data-test="crop-create">Create derivative</button>
      <ul class="derivs" data-test="derivs">
        <li v-for="d in cs.derivatives" :key="d.uuid">{{ d.kind }} — {{ d.width }}×{{ d.height }} <span v-if="d.needs_review" class="tag tag--warn">review</span></li>
      </ul>
    </div>

    <!-- PREVIEW STUDIO -->
    <div v-show="tab === 'preview'" class="panel">
      <div class="studio__bar">
        <div class="seg">
          <button v-for="d in ['desktop','tablet','mobile']" :key="d" class="seg__btn" :class="{ 'is-active': device === d }" :data-test="`dev-${d}`" @click="device = (d as typeof device)">{{ d }}</button>
        </div>
        <label class="chk"><input type="checkbox" v-model="reduced" data-test="dev-rm" /> reduced motion</label>
        <span class="bp" data-test="bp-indicator">breakpoint: {{ breakpoint }} · {{ deviceWidth }}px</span>
        <span class="spacer"></span>
        <button class="mini" @click="refreshPreview" data-test="prev-refresh">refresh</button>
        <button class="mini" @click="openPreview" data-test="prev-open">open ↗</button>
        <button class="mini" @click="copySecureLink" data-test="prev-copy">{{ copied ? 'copied' : 'copy secure link' }}</button>
      </div>
      <div class="studio">
        <aside class="studio__side">
          <h3>Art direction</h3>
          <p class="small">preset <b>{{ cs.artDirection.preset }}</b> · hero <b>{{ cs.artDirection.data.hero }}</b> · anim <b>{{ cs.artDirection.data.anim }}</b></p>
          <h3>Estimates</h3>
          <p class="small">images ≈ <b data-test="perf-estimate">{{ perfEstimate }}</b></p>
          <h3>Warnings</h3>
          <ul class="small" data-test="warnings">
            <li v-for="w in cs.warnings" :key="w.category">⚠ {{ w.message }}</li>
            <li v-if="!cs.warnings.length" class="muted">None.</li>
          </ul>
          <h3>Blocks</h3>
          <ol class="small nav" data-test="block-nav"><li v-for="b in blocks" :key="b.id">{{ b.type }}</li></ol>
          <h3>Publication</h3>
          <p class="small" data-test="pub-state">{{ cs.publishable ? 'Ready to publish' : cs.blockers.join('; ') }}</p>
        </aside>
        <div class="studio__frame">
          <iframe ref="iframe" :src="previewSrc" :style="{ width: deviceWidth + 'px' }" title="Case study preview" data-test="preview-frame"></iframe>
        </div>
      </div>
    </div>
  </section>
  <p v-else-if="loading" class="muted">Loading…</p>
  <p v-else class="error">{{ err }}</p>
</template>

<style scoped>
.ed { max-width: 1200px; }
.ed__head { display: flex; justify-content: space-between; align-items: flex-end; gap: var(--sp-3); margin-bottom: var(--sp-3); }
.ed__head h1 { display: inline; margin-right: var(--sp-2); }
.back { font-size: 13px; color: var(--ink-3); display: block; }
.ed__actions { display: flex; gap: var(--sp-2); }
.tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--line); margin-bottom: var(--sp-3); flex-wrap: wrap; }
.tabs__btn { padding: 7px 12px; border: 0; background: none; text-transform: capitalize; border-bottom: 2px solid transparent; cursor: pointer; }
.tabs__btn.is-active { border-color: var(--purple); font-weight: 600; }
.panel { display: grid; gap: var(--sp-3); }
.grid2 { grid-template-columns: 1fr 1fr; }
.fld { display: grid; gap: 4px; font-size: 13px; }
.fld span { color: var(--ink-3); text-transform: capitalize; }
.fld input, .fld select, .fld textarea, .mini-sel, .mini-in { padding: 7px 10px; border: 1px solid var(--line); border-radius: var(--r-sm); font: inherit; }
.addbar, .cap { display: flex; gap: var(--sp-2); flex-wrap: wrap; align-items: center; }
.blocklist, .layers, .derivs, .nav { display: grid; gap: 4px; }
.blockrow, .layer { display: flex; align-items: center; gap: var(--sp-2); padding: 7px 10px; border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--surface); flex-wrap: wrap; }
.blockrow__type, .layer__type { font-family: var(--mono, monospace); font-size: 12px; }
.spacer { flex: 1; }
.mini { font-size: 12px; padding: 3px 8px; border: 1px solid var(--line); border-radius: var(--r-sm); background: var(--surface); cursor: pointer; }
.tiny { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; }
.tiny input[type=number], .tiny select { width: 56px; padding: 2px 4px; }
.shots { width: 100%; border-collapse: collapse; }
.shots td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
.tag { font-size: 11px; padding: 1px 7px; border-radius: var(--r-pill); background: var(--surface-2); margin-left: 6px; }
.tag--warn { background: var(--butter); }
.studio__bar { display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap; margin-bottom: var(--sp-2); }
.seg { display: inline-flex; border: 1px solid var(--line); border-radius: var(--r-sm); overflow: hidden; }
.seg__btn { padding: 5px 12px; border: 0; background: var(--surface); cursor: pointer; text-transform: capitalize; }
.seg__btn.is-active { background: var(--purple); color: #fff; }
.bp { font-size: 12px; color: var(--ink-3); }
.studio { display: grid; grid-template-columns: 240px 1fr; gap: var(--sp-3); }
.studio__side h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-3); margin: var(--sp-2) 0 2px; }
.studio__frame { border: 1px solid var(--line); border-radius: var(--r-sm); overflow: auto; background: var(--surface-2); display: flex; justify-content: center; }
.studio__frame iframe { height: 78vh; border: 0; background: #fff; max-width: 100%; }
.small { font-size: 12px; }
.chk { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; }
.ok { color: var(--success, #16a34a); }
.error { color: var(--danger, #dc2626); }
.blockers { color: var(--danger, #dc2626); font-size: 13px; display: grid; gap: 2px; }
.muted { color: var(--ink-3); }
</style>
