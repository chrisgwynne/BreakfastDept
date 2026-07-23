<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { ApiError } from '@/lib/api'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const remember = ref(true)
const loading = ref(false)
const error = ref('')

async function submit() {
  if (loading.value) return
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value.trim(), password.value, remember.value)
    const next = typeof route.query.next === 'string' ? route.query.next : '/'
    router.replace(next)
  } catch (e) {
    // Never disclose whether the account exists.
    error.value = e instanceof ApiError && e.status === 429
      ? 'Too many attempts. Please wait a moment and try again.'
      : 'Those details don’t match. Please try again.'
    password.value = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth">
    <div class="auth__art" aria-hidden="true">
      <div class="auth__grain"></div>
      <div class="auth__egg"></div>
      <div class="auth__blob auth__blob--1"></div>
      <div class="auth__blob auth__blob--2"></div>
      <blockquote class="auth__quote">
        <span class="display">The private operating system for Breakfast.</span>
        <cite class="eyebrow">Leads · CRM · Previews · Studio</cite>
      </blockquote>
    </div>

    <div class="auth__panel">
      <form class="auth__form" @submit.prevent="submit" novalidate>
        <div class="auth__brand">
          <span class="auth__mark"></span>
          <span class="display auth__word">Breakfast</span>
        </div>
        <h1 class="auth__title">Welcome back</h1>
        <p class="muted auth__sub">Sign in to the studio admin.</p>

        <div v-if="error" class="auth__error" role="alert">{{ error }}</div>

        <div class="field">
          <label class="label" for="email">Email</label>
          <input id="email" class="input" type="email" autocomplete="username" v-model="email"
                 required autofocus placeholder="you@breakfastdept.com" />
        </div>
        <div class="field">
          <label class="label" for="password">Password</label>
          <input id="password" class="input" type="password" autocomplete="current-password"
                 v-model="password" required placeholder="••••••••••" />
        </div>

        <label class="auth__remember">
          <input type="checkbox" v-model="remember" />
          <span>Keep me signed in on this device</span>
        </label>

        <button class="btn btn--primary btn--lg btn--block" type="submit" :disabled="loading">
          <span v-if="!loading">Sign in</span>
          <span v-else class="auth__spin" aria-label="Signing in"></span>
        </button>

        <p class="faint auth__foot">Protected area · Breakfast Studio</p>
      </form>
    </div>
  </div>
</template>

<style scoped>
.auth { min-height: 100vh; display: grid; grid-template-columns: 1.05fr 0.95fr; background: var(--paper); }
@media (max-width: 860px) { .auth { grid-template-columns: 1fr; } .auth__art { display: none; } }

/* Left — editorial art panel */
.auth__art { position: relative; overflow: hidden; background: radial-gradient(120% 120% at 15% 10%, #fff6da 0%, var(--paper-2) 45%, #efe6cf 100%); }
.auth__grain { position: absolute; inset: 0; opacity: 0.5;
  background-image: radial-gradient(rgba(28,26,23,0.05) 1px, transparent 1px); background-size: 18px 18px; }
.auth__egg { position: absolute; top: 12%; left: 14%; width: 120px; height: 120px; border-radius: 50%;
  background: var(--surface); border: 3px solid var(--ink); box-shadow: var(--sh-3); display: grid; place-items: center; }
.auth__egg::after { content: ""; width: 52px; height: 52px; border-radius: 50%; background: var(--butter); }
.auth__blob { position: absolute; border-radius: 50%; filter: blur(2px); }
.auth__blob--1 { width: 220px; height: 220px; right: -40px; top: 24%; background: rgba(75,50,214,0.10); }
.auth__blob--2 { width: 160px; height: 160px; left: 30%; bottom: 8%; background: rgba(253,200,0,0.18); }
.auth__quote { position: absolute; left: var(--sp-12); right: var(--sp-12); bottom: var(--sp-16); display: grid; gap: var(--sp-3); }
.auth__quote .display { font-size: clamp(1.8rem, 3.2vw, 2.6rem); line-height: 1.12; color: var(--ink); }
.auth__quote cite { color: var(--ink-3); font-style: normal; }

/* Right — form */
.auth__panel { display: grid; place-items: center; padding: var(--sp-8); }
.auth__form { width: 100%; max-width: 380px; display: grid; gap: var(--sp-4); }
.auth__brand { display: flex; align-items: center; gap: 10px; margin-bottom: var(--sp-2); }
.auth__mark { width: 30px; height: 30px; border-radius: 50%; background: var(--surface); border: 2.5px solid var(--ink);
  position: relative; box-shadow: var(--sh-1); }
.auth__mark::after { content: ""; position: absolute; inset: 0; margin: auto; width: 12px; height: 12px; border-radius: 50%; background: var(--butter); }
.auth__word { font-size: 1.3rem; }
.auth__title { font-family: var(--font-display); font-weight: 500; font-size: var(--text-2xl); }
.auth__sub { margin-top: -6px; margin-bottom: var(--sp-2); }
.auth__error { background: var(--danger-soft); color: var(--danger-ink); border-radius: var(--r-sm);
  padding: 10px var(--sp-3); font-size: var(--text-sm); font-weight: 500; }
.auth__remember { display: flex; align-items: center; gap: 10px; font-size: var(--text-sm); color: var(--ink-2); cursor: pointer; }
.auth__remember input { width: 16px; height: 16px; accent-color: var(--purple); }
.auth__foot { text-align: center; font-size: var(--text-xs); margin-top: var(--sp-2); }
.auth__spin { width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.5);
  border-top-color: #fff; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
