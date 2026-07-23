<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
import NavIcon from '@/components/NavIcon.vue'

const router = useRouter()
const auth = useAuth()
const open = ref(false)
const query = ref('')
const active = ref(0)
const inputEl = ref<HTMLInputElement | null>(null)

interface Command { label: string; hint: string; icon: string; to: string; perm?: string }

const commands: Command[] = [
  { label: 'Dashboard', hint: 'Overview', icon: 'grid', to: '/' },
  { label: 'Leads', hint: 'Enquiry inbox', icon: 'inbox', to: '/leads' },
  { label: 'CRM — Contacts', hint: 'People & companies', icon: 'users', to: '/crm' },
  { label: 'Pipeline', hint: 'Opportunities board', icon: 'columns', to: '/pipeline' },
  { label: 'Tasks', hint: 'What needs doing', icon: 'check', to: '/tasks' },
  { label: 'Client Previews', hint: 'Shared work', icon: 'window', to: '/previews' },
  { label: 'Invoices', hint: 'Billing & payments', icon: 'receipt', to: '/invoices', perm: 'invoices.view' },
  { label: 'Email', hint: 'Compose & delivery', icon: 'mail', to: '/email' },
  { label: 'Website', hint: 'Public site', icon: 'globe', to: '/website' },
  { label: 'Reports', hint: 'Numbers', icon: 'chart', to: '/reports' },
  { label: 'Operations', hint: 'System health', icon: 'pulse', to: '/operations', perm: 'admin' },
  { label: 'Hermes', hint: 'Integration console', icon: 'plug', to: '/hermes', perm: 'hermes.view' },
  { label: 'Settings', hint: 'Your account', icon: 'cog', to: '/settings' },
]

const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  const allowed = commands.filter((c) => !c.perm || auth.can(c.perm))
  if (!q) return allowed
  return allowed.filter((c) => (c.label + ' ' + c.hint).toLowerCase().includes(q))
})

watch(results, () => { active.value = 0 })

function toggle(show: boolean) {
  open.value = show
  if (show) {
    query.value = ''
    active.value = 0
    nextTick(() => inputEl.value?.focus())
  }
}

function choose(cmd?: Command) {
  const target = cmd ?? results.value[active.value]
  if (!target) return
  toggle(false)
  if (router.currentRoute.value.path !== target.to) router.push(target.to)
}

function onKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    toggle(!open.value)
    return
  }
  if (!open.value) return
  if (e.key === 'Escape') { e.preventDefault(); toggle(false) }
  else if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(active.value + 1, results.value.length - 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(active.value - 1, 0) }
  else if (e.key === 'Enter') { e.preventDefault(); choose() }
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
defineExpose({ open: () => toggle(true) })
</script>

<template>
  <Teleport to="body">
    <transition name="fade">
      <div v-if="open" class="cp__scrim" @click="toggle(false)">
        <div class="cp" role="dialog" aria-modal="true" aria-label="Command palette" @click.stop>
          <div class="cp__search">
            <NavIcon name="search" class="cp__icon" />
            <input ref="inputEl" v-model="query" class="cp__input" placeholder="Jump to…"
                   autocomplete="off" spellcheck="false" />
            <kbd class="cp__kbd">esc</kbd>
          </div>
          <ul class="cp__list">
            <li v-for="(c, i) in results" :key="c.to" class="cp__item" :class="{ 'cp__item--active': i === active }"
                @mouseenter="active = i" @click="choose(c)">
              <span class="cp__itemicon"><NavIcon :name="c.icon" /></span>
              <span class="cp__label">{{ c.label }}</span>
              <span class="cp__hint">{{ c.hint }}</span>
            </li>
            <li v-if="!results.length" class="cp__none">No matches for “{{ query }}”.</li>
          </ul>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.cp__scrim { position: fixed; inset: 0; z-index: var(--z-command); background: var(--overlay);
  display: grid; align-items: start; justify-items: center; padding-top: 14vh; }
.cp { width: min(560px, 92vw); background: var(--surface-elevated); border: 1px solid var(--line);
  border-radius: var(--r-xl); box-shadow: var(--sh-4); overflow: hidden; }
.cp__search { display: flex; align-items: center; gap: 10px; padding: 0 var(--sp-4); height: 54px; border-bottom: 1px solid var(--line); }
.cp__icon { width: 18px; height: 18px; color: var(--ink-3); flex: none; }
.cp__input { flex: 1; border: none; background: none; font-size: var(--text-lg); color: var(--ink); outline: none; }
.cp__kbd { font-family: var(--font-mono); font-size: 10px; padding: 3px 6px; border: 1px solid var(--line-2);
  border-radius: 5px; background: var(--paper-2); color: var(--ink-3); }
.cp__list { list-style: none; max-height: 340px; overflow-y: auto; padding: var(--sp-2); }
.cp__item { display: flex; align-items: center; gap: var(--sp-3); padding: 10px var(--sp-3); border-radius: var(--r-sm); cursor: pointer; }
.cp__item--active { background: var(--surface-2); }
.cp__itemicon { width: 26px; height: 26px; display: grid; place-items: center; border-radius: var(--r-xs); background: var(--paper-2); color: var(--ink-3); flex: none; }
.cp__itemicon svg { width: 15px; height: 15px; }
.cp__item--active .cp__itemicon { color: var(--purple); }
.cp__label { font-weight: 550; font-size: var(--text-base); }
.cp__hint { margin-left: auto; font-size: var(--text-xs); color: var(--ink-3); }
.cp__none { padding: var(--sp-6); text-align: center; color: var(--ink-3); font-size: var(--text-sm); }
</style>
