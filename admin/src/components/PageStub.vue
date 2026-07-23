<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
defineProps<{ eyebrow?: string; title: string; sub?: string; note?: string }>()
</script>
<template>
  <div>
    <PageHeader :eyebrow="eyebrow" :title="title" :sub="sub" />
    <div class="stub card card--pad">
      <div class="stub__egg" aria-hidden="true"></div>
      <p class="stub__note">{{ note || 'This area is coming together.' }}</p>
    </div>
  </div>
</template>
<style scoped>
.stub { display: grid; place-items: center; gap: var(--sp-4); padding: var(--sp-16); text-align: center; }
.stub__egg { width: 44px; height: 44px; border-radius: 50%; background: var(--paper-2); border: 2px dashed var(--line-strong);
  display: grid; place-items: center; }
.stub__egg::after { content: ""; width: 16px; height: 16px; border-radius: 50%; background: var(--butter-soft); }
.stub__note { color: var(--ink-3); max-width: 34ch; }
</style>
