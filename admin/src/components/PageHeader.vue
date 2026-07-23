<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; sub?: string }>()
</script>
<template>
  <header class="ph">
    <div class="ph__text">
      <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
      <h1 class="ph__title">{{ title }}</h1>
      <p v-if="sub" class="muted ph__sub">{{ sub }}</p>
    </div>
    <div class="ph__actions"><slot name="actions" /></div>
  </header>
</template>
<style scoped>
.ph { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sp-4); margin-bottom: var(--sp-6); flex-wrap: wrap; }
.ph__title { font-family: var(--font-display); font-weight: 500; font-size: var(--text-2xl); }
.ph__sub { margin-top: 2px; }
.ph__actions { display: flex; gap: var(--sp-2); }
</style>
