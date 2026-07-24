<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
import { ApiError } from '@/lib/api'
import PageHeader from '@/components/PageHeader.vue'
import BrevoSettings from '@/components/BrevoSettings.vue'

const auth = useAuth()
const router = useRouter()

// A friendly display name — used in the dashboard greeting. Seeded from the
// current name, unless it's just the email (in which case start blank).
const initialName = (() => {
  const n = (auth.user?.name || '').trim()
  return !n || n.includes('@') ? '' : n
})()
const displayName = ref(initialName)
const saving = ref(false)
const saved = ref(false)
const nameError = ref('')

async function saveName() {
  if (saving.value) return
  saving.value = true
  saved.value = false
  nameError.value = ''
  try {
    await auth.updateName(displayName.value.trim())
    saved.value = true
    setTimeout(() => (saved.value = false), 2400)
  } catch (e) {
    nameError.value = e instanceof ApiError ? e.message : 'Could not save your name.'
  } finally {
    saving.value = false
  }
}

const PERMS: Record<string, string> = {
  admin: 'Full administrator',
  'crm.manage': 'Manage CRM records',
  'crm.export': 'Export CRM data',
  'email.send': 'Send email',
}
const permissions = computed(() =>
  (auth.user?.permissions ?? []).map((p) => ({ key: p, label: PERMS[p] ?? p })))

async function signOut() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div>
    <PageHeader eyebrow="You" title="Settings" sub="Your account and access." />

    <div class="grid">
      <section class="card card--pad">
        <div class="acct">
          <span class="acct__avatar">{{ auth.user?.initials || '··' }}</span>
          <div>
            <p class="acct__name">{{ auth.user?.name }}</p>
            <p class="faint">{{ auth.user?.email }}</p>
          </div>
        </div>
        <form class="nameform" @submit.prevent="saveName">
          <label class="label" for="displayName">Display name</label>
          <p class="faint nameform__hint">The name Breakfast greets you by. Leave blank to just say hello.</p>
          <div class="nameform__row">
            <input id="displayName" class="input" v-model="displayName" type="text" maxlength="80"
                   placeholder="e.g. Chris" autocomplete="name" />
            <button class="btn btn--primary btn--sm" type="submit" :disabled="saving">
              {{ saving ? 'Saving…' : 'Save' }}</button>
          </div>
          <p v-if="saved" class="nameform__ok">Saved.</p>
          <p v-if="nameError" class="nameform__err" role="alert">{{ nameError }}</p>
        </form>

        <div class="rows">
          <div class="row"><span class="row__k">Role</span><span class="row__v">{{ auth.user?.role || '—' }}</span></div>
        </div>
        <button class="btn btn--danger btn--sm" @click="signOut">Sign out</button>
      </section>

      <section class="card card--pad">
        <h2 class="sect__h">What you can do</h2>
        <ul class="perms">
          <li v-for="p in permissions" :key="p.key" class="perm">
            <span class="perm__tick" aria-hidden="true">✓</span>{{ p.label }}
          </li>
          <li v-if="!permissions.length" class="faint">Standard access.</li>
        </ul>
        <p class="note">Access is granted by an administrator and enforced on the server. If you need more,
          ask whoever set up your account.</p>
      </section>

      <BrevoSettings v-if="auth.can('brevo.view')" class="span2" />
    </div>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sp-5); align-items: start; }
.acct { display: flex; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-4); }
.acct__avatar { display: grid; place-items: center; width: 48px; height: 48px; border-radius: 50%;
  background: var(--purple-soft); color: var(--purple-ink); font-weight: 700; }
.acct__name { font-size: var(--text-lg); font-weight: 600; }
.nameform { display: grid; gap: 6px; margin-bottom: var(--sp-4); padding-bottom: var(--sp-4); border-bottom: 1px solid var(--line); }
.nameform__hint { font-size: var(--text-xs); margin-top: -2px; }
.nameform__row { display: flex; gap: var(--sp-2); margin-top: 4px; }
.nameform__row .input { flex: 1; }
.nameform__ok { font-size: var(--text-xs); color: var(--success-ink); font-weight: 600; }
.nameform__err { font-size: var(--text-xs); color: var(--danger-ink); font-weight: 600; }

.rows { display: grid; margin-bottom: var(--sp-4); }
.row { display: flex; justify-content: space-between; padding: 10px 0; border-top: 1px solid var(--line); font-size: var(--text-sm); }
.row__k { color: var(--ink-3); }
.row__v { text-transform: capitalize; }

.sect__h { font-size: var(--text-base); font-weight: 650; color: var(--ink-2); margin-bottom: var(--sp-3); }
.perms { list-style: none; display: grid; gap: 8px; margin-bottom: var(--sp-4); }
.perm { display: flex; align-items: center; gap: 10px; font-size: var(--text-sm); }
.perm__tick { display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%;
  background: var(--success-soft); color: var(--success-ink); font-size: 11px; font-weight: 700; }
.note { font-size: var(--text-sm); color: var(--ink-3); line-height: 1.6; }

.span2 { grid-column: 1 / -1; }

@media (max-width: 720px) { .grid { grid-template-columns: 1fr; } }
</style>
