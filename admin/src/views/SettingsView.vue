<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'

const auth = useAuth()
const router = useRouter()

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
    </div>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sp-5); align-items: start; }
.acct { display: flex; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-4); }
.acct__avatar { display: grid; place-items: center; width: 48px; height: 48px; border-radius: 50%;
  background: var(--purple-soft); color: var(--purple-ink); font-weight: 700; }
.acct__name { font-size: var(--text-lg); font-weight: 600; }
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

@media (max-width: 720px) { .grid { grid-template-columns: 1fr; } }
</style>
