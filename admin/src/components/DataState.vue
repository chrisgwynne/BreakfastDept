<script setup lang="ts">
// One wrapper for the three non-happy list states — loading, error and empty —
// so every screen handles them identically. The default slot renders only once
// data has loaded successfully and is non-empty.
withDefaults(defineProps<{
  loading: boolean
  error?: string | null
  empty?: boolean
  rows?: number
  emptyTitle?: string
  emptyNote?: string
}>(), {
  error: null,
  empty: false,
  rows: 6,
  emptyTitle: 'Nothing here yet',
  emptyNote: 'When there’s something to show, it’ll appear here.',
})
const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div v-if="loading" class="ds__load" aria-busy="true">
    <div v-for="i in rows" :key="i" class="skeleton ds__row"></div>
  </div>

  <div v-else-if="error" class="ds__state ds__state--error" role="alert">
    <div class="ds__egg ds__egg--error" aria-hidden="true"></div>
    <p class="ds__title">Couldn’t load this</p>
    <p class="ds__note">{{ error }}</p>
    <button class="btn btn--sm" @click="emit('retry')">Try again</button>
  </div>

  <div v-else-if="empty" class="ds__state">
    <div class="ds__egg" aria-hidden="true"></div>
    <p class="ds__title">{{ emptyTitle }}</p>
    <p class="ds__note">{{ emptyNote }}</p>
    <slot name="empty-action" />
  </div>

  <slot v-else />
</template>

<style scoped>
.ds__load { display: grid; gap: 10px; }
.ds__row { height: 52px; border-radius: var(--r-md); }
.ds__state { display: grid; place-items: center; gap: var(--sp-3); text-align: center;
  padding: var(--sp-16) var(--sp-6); }
.ds__egg { width: 46px; height: 46px; border-radius: 50%; background: var(--paper-2);
  border: 2px dashed var(--line-strong); display: grid; place-items: center; }
.ds__egg::after { content: ""; width: 16px; height: 16px; border-radius: 50%; background: var(--butter-soft); }
.ds__egg--error { border-color: var(--danger); }
.ds__egg--error::after { background: var(--danger-soft); }
.ds__title { font-family: var(--font-display); font-size: var(--text-lg); font-weight: 500; }
.ds__note { color: var(--ink-3); font-size: var(--text-sm); max-width: 40ch; }
</style>
