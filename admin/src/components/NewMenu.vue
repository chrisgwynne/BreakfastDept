<script setup lang="ts">
import { ref, computed } from 'vue'
import { useUi, type CreateType } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import NavIcon from '@/components/NavIcon.vue'

const ui = useUi()
const auth = useAuth()
const open = ref(false)

const canManage = computed(() => auth.can('crm.manage') || auth.can('admin'))
const canEmail = computed(() => auth.can('email.send') || auth.can('admin'))

interface Item { type: CreateType; label: string; icon: string; show: boolean }
const items = computed<Item[]>(() => {
  const all: Item[] = [
    { type: 'lead', label: 'Lead', icon: 'inbox', show: canManage.value },
    { type: 'contact', label: 'Contact', icon: 'users', show: canManage.value },
    { type: 'company', label: 'Company', icon: 'grid', show: canManage.value },
    { type: 'deal', label: 'Opportunity', icon: 'columns', show: canManage.value },
    { type: 'task', label: 'Task', icon: 'check', show: canManage.value },
    { type: 'email', label: 'Email', icon: 'mail', show: canEmail.value },
  ]
  return all.filter((i) => i.show)
})

function choose(type: CreateType) {
  open.value = false
  ui.openCreate(type)
}
</script>

<template>
  <div v-if="items.length" class="nm">
    <button class="btn btn--sm btn--primary nm__btn" @click="open = !open" :aria-expanded="open">
      <NavIcon name="plus" /><span>New</span>
    </button>
    <transition name="fade">
      <div v-if="open" class="nm__menu" @click="open = false">
        <button v-for="i in items" :key="i.type" class="nm__item" @click="choose(i.type)">
          <span class="nm__icon"><NavIcon :name="i.icon" /></span>{{ i.label }}
        </button>
      </div>
    </transition>
    <div v-if="open" class="nm__scrim" @click="open = false"></div>
  </div>
</template>

<style scoped>
.nm { position: relative; }
.nm__btn { gap: 4px; }
.nm__menu { position: absolute; right: 0; top: 42px; z-index: calc(var(--z-topbar) + 5); width: 200px;
  background: var(--surface-elevated); border: 1px solid var(--line); border-radius: var(--r-md);
  box-shadow: var(--sh-3); padding: var(--sp-2); display: grid; gap: 2px; }
.nm__item { display: flex; align-items: center; gap: 10px; padding: 8px var(--sp-3); border-radius: var(--r-xs);
  text-align: left; font-size: var(--text-base); color: var(--ink-2); }
.nm__item:hover { background: var(--surface-2); color: var(--ink); }
.nm__icon { width: 26px; height: 26px; display: grid; place-items: center; border-radius: var(--r-xs);
  background: var(--paper-2); color: var(--ink-3); }
.nm__icon svg { width: 15px; height: 15px; }
.nm__scrim { position: fixed; inset: 0; z-index: calc(var(--z-topbar) + 4); }
</style>
