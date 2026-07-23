<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAuth } from '@/stores/auth'

const auth = useAuth()
const booted = ref(false)
onMounted(async () => {
  if (!auth.ready) await auth.bootstrap()
  booted.value = true
})
</script>

<template>
  <div v-if="!booted" class="boot">
    <div class="boot__egg" aria-hidden="true"></div>
    <p class="eyebrow">Breakfast</p>
  </div>
  <router-view v-else v-slot="{ Component }">
    <transition name="fade" mode="out-in">
      <component :is="Component" />
    </transition>
  </router-view>
</template>

<style scoped>
.boot { position: fixed; inset: 0; display: grid; place-content: center; justify-items: center; gap: var(--sp-4); background: var(--paper); }
.boot__egg { width: 46px; height: 46px; border-radius: 50%; background: var(--surface);
  border: 3px solid var(--ink); box-shadow: var(--sh-2); display: grid; place-items: center; position: relative; }
.boot__egg::after { content: ""; width: 20px; height: 20px; border-radius: 50%; background: var(--butter);
  animation: pulse 1.1s var(--ease-in-out) infinite; }
@keyframes pulse { 0%, 100% { transform: scale(0.8); opacity: 0.8; } 50% { transform: scale(1.05); opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .boot__egg::after { animation: none; } }
</style>
