<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ status: string; label?: string }>()

// Map any domain status onto one of the semantic tones from the design system.
const TONES: Record<string, string> = {
  new: 'purple', triaged: 'info', qualified: 'info', discovery: 'info',
  proposal: 'warning', decision: 'warning', active: 'success', won: 'success',
  converted: 'success', done: 'success', complete: 'success', completed: 'success',
  open: 'neutral', draft: 'neutral', pending: 'neutral',
  issued: 'info', sent: 'info', viewed: 'info', partial: 'warning', paid: 'success',
  overdue: 'danger', failed: 'danger', lost: 'danger', spam: 'danger',
  disabled: 'muted', expired: 'muted', archived: 'muted', closed: 'muted', void: 'muted',
}

const tone = computed(() => TONES[props.status?.toLowerCase()] ?? 'neutral')
const text = computed(() =>
  props.label ?? (props.status ? props.status.charAt(0).toUpperCase() + props.status.slice(1).replace(/[_-]/g, ' ') : '—'))
</script>

<template>
  <span class="pill" :class="`pill--${tone}`">{{ text }}</span>
</template>

<style scoped>
.pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: var(--r-pill);
  font-size: var(--text-xs); font-weight: 600; line-height: 1.4; white-space: nowrap; }
.pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.9; }
.pill--purple { background: var(--purple-soft); color: var(--purple-ink); }
.pill--info { background: var(--info-soft); color: var(--info-ink); }
.pill--success { background: var(--success-soft); color: var(--success-ink); }
.pill--warning { background: var(--warning-soft); color: var(--warning-ink); }
.pill--danger { background: var(--danger-soft); color: var(--danger-ink); }
.pill--neutral { background: var(--paper-2); color: var(--ink-2); }
.pill--muted { background: var(--paper-2); color: var(--ink-3); }
</style>
