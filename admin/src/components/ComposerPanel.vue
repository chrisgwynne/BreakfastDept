<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { portfolio, type ComposerReview } from '@/lib/portfolio'
import { ApiError } from '@/lib/api'

const props = defineProps<{ id: string }>()
const emit = defineEmits<{ applied: [message: string] }>()

const data = ref<ComposerReview | null>(null)
const loading = ref(true)
const busy = ref('')
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await portfolio.composer(props.id)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not analyse this case study.'
  } finally {
    loading.value = false
  }
}

async function apply(kind: string, key = '', label = '') {
  busy.value = kind + ':' + key
  error.value = ''
  try {
    await portfolio.compose(props.id, { kind, key })
    emit('applied', label || 'Composition applied.')
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not apply that composition.'
  } finally {
    busy.value = ''
  }
}

const health = computed(() => data.value?.storyHealth)
const unique = computed(() => data.value?.uniqueness)
const rhythm = computed(() => data.value?.rhythm)

function tone(score: number): string {
  return score >= 75 ? 'good' : score >= 50 ? 'ok' : 'low'
}

onMounted(load)
</script>

<template>
  <div class="composer" data-test="composer-panel">
    <p v-if="loading" class="muted">Reviewing the page…</p>
    <p v-if="error" class="error" role="alert" data-test="composer-error">{{ error }}</p>

    <template v-if="data && !loading">
      <!-- Scores -->
      <div class="scores">
        <div class="score" :class="tone(health?.overall ?? 0)" data-test="composer-story-score">
          <div class="score__n">{{ health?.overall ?? 0 }}</div>
          <div class="score__l">Story health</div>
        </div>
        <div class="score" :class="tone(unique?.overall ?? 0)" data-test="composer-uniqueness">
          <div class="score__n">{{ unique?.overall ?? 0 }}</div>
          <div class="score__l">Uniqueness</div>
        </div>
        <div class="score" :class="tone(rhythm?.score ?? 0)" data-test="composer-rhythm">
          <div class="score__n">{{ rhythm?.score ?? 0 }}</div>
          <div class="score__l">Reading rhythm</div>
        </div>
      </div>

      <!-- Creative director notes -->
      <section class="block">
        <h3>Creative director</h3>
        <ul class="notes" data-test="composer-notes">
          <li v-for="(n, i) in data.review" :key="i" class="note" :class="n.severity" :data-test="`note-${n.code}`">
            <strong>{{ n.message }}</strong>
            <span class="muted">{{ n.action }}</span>
          </li>
          <li v-if="!data.review.length" class="note good">Nothing to flag — the page is reading well.</li>
        </ul>
      </section>

      <!-- Story health detail -->
      <section class="block">
        <h3>Story health</h3>
        <ul class="metrics">
          <li v-for="m in health?.metrics ?? []" :key="m.key" :data-test="`metric-${m.key}`">
            <span class="bar"><span class="bar__fill" :class="tone(m.score)" :style="{ width: m.score + '%' }"></span></span>
            <span class="metric__l">{{ m.label }}</span>
            <span class="muted">{{ m.reason }}</span>
          </li>
        </ul>
      </section>

      <!-- Storyboard -->
      <section class="block">
        <h3>Storyboard</h3>
        <ol class="storyboard" data-test="composer-storyboard">
          <li v-for="(c, i) in data.storyboard" :key="i" class="cell" :class="{ dim: c.hiddenEverywhere }" :data-test="`cell-${i}`">
            <span class="cell__k">{{ c.kind }}</span>
            <span class="cell__t">{{ c.type.replace('cs-', '') }}</span>
            <span v-if="c.hasImage" class="cell__img" title="has image">◧</span>
          </li>
          <li v-if="!data.storyboard.length" class="muted">No sections yet — apply a starter or a layout idea below.</li>
        </ol>
      </section>

      <!-- Layout ideas -->
      <section class="block">
        <h3>Layout ideas</h3>
        <p class="muted">Real block structures from the approved system — pick one to rebuild the page.</p>
        <div class="ideas" data-test="composer-ideas">
          <article v-for="idea in data.ideas" :key="idea.key" class="idea" :data-test="`idea-${idea.key}`">
            <header><strong>{{ idea.name }}</strong><span class="chip">{{ idea.hero }} · {{ idea.ending }}</span></header>
            <p class="muted">{{ idea.rationale }}</p>
            <p class="seq">{{ idea.blocks.map(b => b.replace('cs-', '')).join(' → ') }}</p>
            <button class="btn" :disabled="!!busy" :data-test="`idea-apply-${idea.key}`"
              @click="apply('layout', idea.key, `Applied the ${idea.name} layout.`)">Use this layout</button>
          </article>
        </div>
      </section>

      <!-- Inspiration + starter -->
      <section class="block">
        <h3>Inspire me</h3>
        <div class="chips" data-test="composer-inspire">
          <button v-for="ins in data.inspirations" :key="ins.key" class="chip chip--btn" :disabled="!!busy"
            :data-test="`inspire-${ins.key}`" @click="apply('inspiration', ins.key, `Rebuilt as ${ins.name}.`)">{{ ins.name }}</button>
        </div>
        <button class="btn btn--primary starter" :disabled="!!busy" data-test="composer-starter"
          @click="apply('starter', '', 'Applied a rich starter composition.')">Start from a rich composition</button>
      </section>
    </template>
  </div>
</template>

<style scoped>
.composer { display: flex; flex-direction: column; gap: 1.25rem; }
.scores { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; }
.score { border: 1px solid var(--line, #e5e2da); border-radius: 12px; padding: 1rem; text-align: center; }
.score__n { font-size: 2rem; font-weight: 800; line-height: 1; }
.score__l { font-size: .8rem; opacity: .7; margin-top: .35rem; }
.score.good { background: #edf7ee; } .score.ok { background: #fff8e6; } .score.low { background: #fdecec; }
.block h3 { margin: 0 0 .5rem; font-size: .95rem; }
.notes, .metrics { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem; }
.note { border-left: 3px solid #ccc; padding: .4rem .6rem; display: flex; flex-direction: column; gap: .1rem; border-radius: 4px; background: #faf9f6; }
.note.high { border-color: #e0503a; } .note.medium { border-color: #e2a63a; } .note.low { border-color: #8aa; } .note.good { border-color: #4a9; }
.metrics li { display: grid; grid-template-columns: 120px 130px 1fr; align-items: center; gap: .5rem; font-size: .85rem; }
.bar { grid-column: 1; height: 8px; background: #eee; border-radius: 6px; overflow: hidden; }
.bar__fill { display: block; height: 100%; } .bar__fill.good { background: #4a9; } .bar__fill.ok { background: #e2a63a; } .bar__fill.low { background: #e0503a; }
.storyboard { display: flex; flex-wrap: wrap; gap: .5rem; list-style: none; margin: 0; padding: 0; counter-reset: cell; }
.cell { position: relative; width: 84px; height: 60px; border: 1px solid var(--line, #e5e2da); border-radius: 8px; padding: .35rem; display: flex; flex-direction: column; justify-content: space-between; background: #fff; font-size: .7rem; }
.cell.dim { opacity: .45; }
.cell__k { font-weight: 700; text-transform: uppercase; letter-spacing: .03em; font-size: .6rem; opacity: .6; }
.cell__t { font-weight: 600; }
.cell__img { position: absolute; top: .3rem; right: .4rem; }
.ideas { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .75rem; }
.idea { border: 1px solid var(--line, #e5e2da); border-radius: 12px; padding: .8rem; display: flex; flex-direction: column; gap: .4rem; }
.idea header { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.seq { font-size: .75rem; opacity: .75; }
.chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .8rem; }
.chip { font-size: .72rem; background: #f0ede6; border-radius: 999px; padding: .15rem .55rem; }
.chip--btn { border: 1px solid var(--line, #e5e2da); cursor: pointer; }
.starter { align-self: flex-start; }
.muted { opacity: .7; font-size: .85rem; }
</style>
