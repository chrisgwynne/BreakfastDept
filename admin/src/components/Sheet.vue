<script setup lang="ts">
import { watch, onBeforeUnmount } from 'vue'

const props = defineProps<{ open: boolean; title?: string; eyebrow?: string }>()
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
watch(() => props.open, (open) => {
  if (open) document.addEventListener('keydown', onKey)
  else document.removeEventListener('keydown', onKey)
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <transition name="fade">
      <div v-if="open" class="sheet__scrim" @click="emit('close')"></div>
    </transition>
    <transition name="sheet">
      <aside v-if="open" class="sheet" role="dialog" aria-modal="true" :aria-label="title">
        <header class="sheet__head">
          <div>
            <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
            <h2 class="sheet__title">{{ title }}</h2>
          </div>
          <button class="iconbtn" aria-label="Close" @click="emit('close')">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>
        <div class="sheet__body"><slot /></div>
        <footer v-if="$slots.footer" class="sheet__foot"><slot name="footer" /></footer>
      </aside>
    </transition>
  </Teleport>
</template>

<style scoped>
.sheet__scrim { position: fixed; inset: 0; background: var(--overlay); z-index: var(--z-drawer); }
.sheet { position: fixed; top: 0; right: 0; height: 100vh; width: min(480px, 92vw); z-index: calc(var(--z-drawer) + 1);
  background: var(--surface-elevated); border-left: 1px solid var(--line); box-shadow: var(--sh-4);
  display: flex; flex-direction: column; }
.sheet__head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sp-3);
  padding: var(--sp-5) var(--sp-6); border-bottom: 1px solid var(--line); }
.sheet__title { font-family: var(--font-display); font-size: var(--text-xl); font-weight: 500; }
.sheet__body { flex: 1; overflow-y: auto; padding: var(--sp-6); }
.sheet__foot { padding: var(--sp-4) var(--sp-6); border-top: 1px solid var(--line); display: flex; gap: var(--sp-2); justify-content: flex-end; }

.sheet-enter-active, .sheet-leave-active { transition: transform var(--dur-3) var(--ease-out); }
.sheet-enter-from, .sheet-leave-to { transform: translateX(100%); }
</style>
