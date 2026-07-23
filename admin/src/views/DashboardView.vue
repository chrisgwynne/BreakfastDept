<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { Dashboard } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
const canEmail = computed(() => auth.can('email.send') || auth.can('admin'))
const data = ref<Dashboard | null>(null)
const loading = ref(true)
const failed = ref(false)

function money(n: number): string {
  return '£' + Math.round(n).toLocaleString('en-GB')
}

// The greeting is built on the client so it follows the viewer's own timezone
// and stays friendly even if the dashboard data hasn't loaded. A name is used
// only when the user has set a real one (never their email address).
function firstName(): string {
  const n = (auth.user?.name || '').trim()
  if (!n || n.includes('@')) return ''
  return n.split(/\s+/)[0]
}
const PHRASES: Record<string, string[]> = {
  morning: ['Morning', 'Good morning', 'Rise and shine', 'Bright and early'],
  afternoon: ['Afternoon', 'Good afternoon', 'Hope the day’s treating you well', 'Back at it'],
  evening: ['Evening', 'Good evening', 'Winding down'],
  night: ['Burning the midnight oil', 'Still up', 'Late one tonight'],
}
function timeBucket(h: number): keyof typeof PHRASES {
  if (h < 5) return 'night'
  if (h < 12) return 'morning'
  if (h < 18) return 'afternoon'
  if (h < 22) return 'evening'
  return 'night'
}
// Computed once per visit so the phrase doesn't flicker on re-render.
const list = PHRASES[timeBucket(new Date().getHours())]
const phrase = list[Math.floor(Math.random() * list.length)]
const name = firstName()
const greeting = name ? `${phrase}, ${name}` : phrase
const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })

onMounted(async () => {
  try {
    data.value = await api.get<Dashboard>('/dashboard')
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})

const attentionItems = (d: Dashboard) => [
  { label: 'New enquiries', value: d.attention.new_enquiries, to: '/leads', tone: 'purple', icon: 'inbox' },
  { label: 'Overdue tasks', value: d.attention.overdue_tasks, to: '/tasks', tone: 'danger', icon: 'clock' },
  { label: 'Failed emails', value: d.attention.failed_emails, to: '/email', tone: 'warning', icon: 'mail' },
  { label: 'Previews to action', value: d.attention.previews_awaiting, to: '/previews', tone: 'info', icon: 'window' },
  { label: 'Stalled deals', value: d.attention.stalled_opportunities, to: '/pipeline', tone: 'neutral', icon: 'pulse' },
]
</script>

<template>
  <div>
    <header class="dash__head">
      <div>
        <p class="eyebrow">{{ today }}</p>
        <h1 class="dash__title">{{ greeting }}.</h1>
      </div>
      <div class="row">
        <button v-if="canEmail" class="btn btn--sm" @click="ui.openCreate('email')"><NavIcon name="mail" /> Compose</button>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="ui.openCreate('lead')"><NavIcon name="plus" /> New lead</button>
      </div>
    </header>

    <!-- Attention -->
    <section class="sect">
      <h2 class="sect__h">Needs your attention</h2>
      <div class="attn">
        <template v-if="loading">
          <div v-for="i in 5" :key="i" class="attn__card skeleton" style="height:96px"></div>
        </template>
        <template v-else-if="data">
          <RouterLink v-for="a in attentionItems(data)" :key="a.label" :to="a.to"
            class="attn__card" :class="`attn__card--${a.tone}`" :data-zero="a.value === 0">
            <span class="attn__icon"><NavIcon :name="a.icon" /></span>
            <span class="attn__val num">{{ a.value }}</span>
            <span class="attn__label">{{ a.label }}</span>
          </RouterLink>
        </template>
      </div>
    </section>

    <div class="cols">
      <!-- Pipeline -->
      <section class="sect grow">
        <div class="sect__row">
          <h2 class="sect__h">Pipeline</h2>
          <RouterLink to="/pipeline" class="sect__link">Open board <NavIcon name="arrow" /></RouterLink>
        </div>
        <div class="card card--pad">
          <template v-if="loading"><div class="skeleton" style="height:150px"></div></template>
          <template v-else-if="data && data.pipeline.length">
            <div class="pl__value num">{{ money(data.metrics.pipeline_value) }}<span class="pl__cap">open value</span></div>
            <div class="pl__bars">
              <div v-for="s in data.pipeline" :key="s.key" class="pl__bar">
                <div class="pl__track"><div class="pl__fill" :style="{ width: (s.value / Math.max(1, data.metrics.pipeline_value) * 100) + '%' }"></div></div>
                <div class="pl__meta"><span>{{ s.label }}</span><span class="muted num">{{ s.count }} · {{ money(s.value) }}</span></div>
              </div>
            </div>
          </template>
          <p v-else class="empty">No open opportunities yet.</p>
        </div>
      </section>

      <!-- Recent activity -->
      <section class="sect" style="width:340px;flex:none">
        <h2 class="sect__h">Recent activity</h2>
        <div class="card card--pad">
          <template v-if="loading"><div v-for="i in 4" :key="i" class="skeleton" style="height:20px;margin-bottom:12px"></div></template>
          <ul v-else-if="data && data.recent.length" class="tl">
            <li v-for="e in data.recent" :key="e.id" class="tl__item">
              <span class="tl__dot"></span>
              <div><p class="tl__title">{{ e.title }}</p><p class="faint tl__meta">{{ e.meta }} · {{ e.at }}</p></div>
            </li>
          </ul>
          <p v-else class="empty">Nothing yet today.</p>
        </div>
      </section>
    </div>

    <p v-if="failed" class="dash__offline muted">Live data will appear here once you’re connected to the studio backend.</p>
  </div>
</template>

<style scoped>
.dash__head { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); margin-bottom: var(--sp-8); flex-wrap: wrap; }
.dash__title { font-family: var(--font-display); font-weight: 500; font-size: var(--text-3xl); letter-spacing: -0.02em; }
.sect { margin-bottom: var(--sp-8); }
.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.sect__row { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sp-3); }
.sect__link { display: inline-flex; align-items: center; gap: 4px; font-size: var(--text-sm); font-weight: 550; }
.sect__link svg { width: 14px; height: 14px; }

.attn { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--sp-3); }
.attn__card { display: grid; gap: 6px; padding: var(--sp-4); border-radius: var(--r-lg); background: var(--surface);
  border: 1px solid var(--line); box-shadow: var(--sh-1); transition: transform var(--dur-1) var(--ease-out), box-shadow var(--dur-2); }
.attn__card:hover { transform: translateY(-2px); box-shadow: var(--sh-2); text-decoration: none; }
.attn__card[data-zero="true"] { opacity: 0.62; }
.attn__icon { width: 30px; height: 30px; display: grid; place-items: center; border-radius: var(--r-sm); background: var(--paper-2); color: var(--ink-3); }
.attn__icon svg { width: 17px; height: 17px; }
.attn__val { font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 500; line-height: 1; }
.attn__label { font-size: var(--text-sm); color: var(--ink-3); }
.attn__card--danger:not([data-zero="true"]) .attn__icon { background: var(--danger-soft); color: var(--danger-ink); }
.attn__card--warning:not([data-zero="true"]) .attn__icon { background: var(--warning-soft); color: var(--warning-ink); }
.attn__card--purple:not([data-zero="true"]) .attn__icon { background: var(--purple-soft); color: var(--purple-ink); }
.attn__card--info:not([data-zero="true"]) .attn__icon { background: var(--info-soft); color: var(--info-ink); }

.cols { display: flex; gap: var(--sp-5); align-items: flex-start; }
.pl__value { font-family: var(--font-display); font-size: var(--text-2xl); font-weight: 500; display: flex; align-items: baseline; gap: 10px; }
.pl__cap { font-family: var(--font-sans); font-size: var(--text-sm); color: var(--ink-3); font-weight: 400; }
.pl__bars { display: grid; gap: var(--sp-3); margin-top: var(--sp-4); }
.pl__track { height: 8px; border-radius: var(--r-pill); background: var(--paper-2); overflow: hidden; }
.pl__fill { height: 100%; border-radius: var(--r-pill); background: linear-gradient(90deg, var(--purple), #7a63e6); transition: width var(--dur-4) var(--ease-out); }
.pl__meta { display: flex; justify-content: space-between; font-size: var(--text-sm); margin-top: 5px; }

.tl { list-style: none; display: grid; gap: var(--sp-3); }
.tl__item { display: grid; grid-template-columns: 14px 1fr; gap: 10px; }
.tl__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--butter); margin-top: 6px; box-shadow: 0 0 0 3px var(--butter-soft); }
.tl__title { font-size: var(--text-sm); font-weight: 500; }
.tl__meta { font-size: var(--text-xs); }
.empty { color: var(--ink-3); font-size: var(--text-sm); padding: var(--sp-4) 0; text-align: center; }
.dash__offline { text-align: center; font-size: var(--text-sm); margin-top: var(--sp-8); }

@media (max-width: 1000px) { .attn { grid-template-columns: repeat(2, 1fr); } .cols { flex-direction: column; } .cols .sect { width: 100% !important; } }
</style>
