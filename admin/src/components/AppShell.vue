<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router'
import { useAuth } from '@/stores/auth'
import NavIcon from '@/components/NavIcon.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import NewMenu from '@/components/NewMenu.vue'
import CreateForms from '@/components/CreateForms.vue'
import { useUi } from '@/stores/ui'

const auth = useAuth()
const route = useRoute()
const router = useRouter()
const ui = useUi()
const palette = ref<InstanceType<typeof CommandPalette> | null>(null)

const nav = [
  { to: '/', name: 'dashboard', label: 'Dashboard', icon: 'grid' },
  { to: '/leads', name: 'leads', label: 'Leads', icon: 'inbox' },
  { to: '/crm', name: 'crm', label: 'CRM', icon: 'users' },
  { to: '/pipeline', name: 'pipeline', label: 'Pipeline', icon: 'columns' },
  { to: '/tasks', name: 'tasks', label: 'Tasks', icon: 'check' },
  { to: '/calendar', name: 'calendar', label: 'Calendar', icon: 'calendar', perm: 'calendar.view' },
  { to: '/previews', name: 'previews', label: 'Client Previews', icon: 'window' },
  { to: '/projects', name: 'projects', label: 'Projects', icon: 'grid' },
  { to: '/proposals', name: 'proposals', label: 'Proposals', icon: 'columns', perm: 'crm.manage' },
  { to: '/contracts', name: 'contracts', label: 'Contracts', icon: 'check', perm: 'crm.manage' },
  { to: '/invoices', name: 'invoices', label: 'Invoices', icon: 'receipt', perm: 'invoices.view' },
  { to: '/vault', name: 'vault', label: 'Vault', icon: 'cog', perm: 'crm.manage' },
  { to: '/email', name: 'email', label: 'Email', icon: 'mail' },
  { to: '/website', name: 'website', label: 'Website', icon: 'globe' },
  { to: '/reports', name: 'reports', label: 'Reports', icon: 'chart' },
  { to: '/operations', name: 'operations', label: 'Operations', icon: 'pulse', perm: 'admin' },
  { to: '/hermes', name: 'hermes', label: 'Hermes', icon: 'plug', perm: 'hermes.view' },
]
const visibleNav = computed(() => nav.filter((n) => !n.perm || auth.can(n.perm)))

const mobileOpen = ref(false)
const menuOpen = ref(false)

async function doLogout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="shell" :class="{ 'shell--mobile-open': mobileOpen }">
    <!-- Navigation rail -->
    <aside class="rail">
      <div class="rail__brand">
        <span class="rail__mark"></span>
        <span class="display rail__word">Breakfast</span>
      </div>
      <nav class="rail__nav" aria-label="Primary">
        <RouterLink v-for="item in visibleNav" :key="item.to" :to="item.to" class="navlink"
          :class="{ 'navlink--active': route.name === item.name }" @click="mobileOpen = false">
          <NavIcon :name="item.icon" class="navlink__icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
      <RouterLink to="/settings" class="navlink navlink--foot" @click="mobileOpen = false">
        <NavIcon name="cog" class="navlink__icon" /><span>Settings</span>
      </RouterLink>
    </aside>
    <div class="scrim" @click="mobileOpen = false"></div>

    <!-- Main column -->
    <div class="main">
      <header class="topbar">
        <button class="iconbtn topbar__burger" @click="mobileOpen = true" aria-label="Menu">
          <NavIcon name="menu" />
        </button>
        <button class="topbar__search" @click="palette?.open()">
          <NavIcon name="search" class="topbar__searchicon" />
          <span>Search or jump to…</span>
          <kbd>⌘K</kbd>
        </button>
        <div class="grow"></div>
        <NewMenu />
        <div class="acct">
          <button class="acct__btn" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen">
            <span class="acct__avatar">{{ auth.user?.initials || '··' }}</span>
          </button>
          <transition name="fade">
            <div v-if="menuOpen" class="acct__menu" @click="menuOpen = false">
              <div class="acct__head">
                <strong>{{ auth.user?.name }}</strong>
                <span class="faint">{{ auth.user?.email }}</span>
              </div>
              <RouterLink to="/settings" class="acct__item">Settings</RouterLink>
              <button class="acct__item acct__item--danger" @click="doLogout">Sign out</button>
            </div>
          </transition>
        </div>
      </header>

      <main class="content">
        <RouterView v-slot="{ Component }">
          <transition name="rise" mode="out-in">
            <component :is="Component" />
          </transition>
        </RouterView>
      </main>
    </div>

    <CommandPalette ref="palette" />
    <CreateForms />

    <!-- Accurate post-write feedback -->
    <transition name="fade">
      <div v-if="ui.toastMsg" class="app-toast">{{ ui.toastMsg }}</div>
    </transition>
  </div>
</template>

<style>
.app-toast { position: fixed; bottom: var(--sp-6); left: 50%; transform: translateX(-50%); z-index: var(--z-toast);
  background: var(--ink); color: var(--paper); padding: 10px var(--sp-4); border-radius: var(--r-pill);
  font-size: var(--text-sm); font-weight: 500; box-shadow: var(--sh-3); }
</style>

<style scoped>
.shell { display: grid; grid-template-columns: var(--rail-w) 1fr; min-height: 100vh; background: var(--paper); }

.rail { position: sticky; top: 0; height: 100vh; display: flex; flex-direction: column; gap: var(--sp-1);
  padding: var(--sp-4) var(--sp-3); background: var(--paper-2); border-right: 1px solid var(--line);
  z-index: var(--z-rail); }
.rail__brand { display: flex; align-items: center; gap: 10px; padding: var(--sp-2) var(--sp-2) var(--sp-4); }
.rail__mark { width: 26px; height: 26px; border-radius: 50%; background: var(--surface); border: 2.5px solid var(--ink); position: relative; box-shadow: var(--sh-1); }
.rail__mark::after { content: ""; position: absolute; inset: 0; margin: auto; width: 10px; height: 10px; border-radius: 50%; background: var(--butter); }
.rail__word { font-size: 1.15rem; }
.rail__nav { display: grid; gap: 2px; }
.navlink { display: flex; align-items: center; gap: var(--sp-3); padding: 9px var(--sp-3); border-radius: var(--r-sm);
  color: var(--ink-2); font-size: var(--text-base); font-weight: 520; text-decoration: none;
  transition: background var(--dur-1) var(--ease-out), color var(--dur-1); }
.navlink:hover { background: var(--surface-2); color: var(--ink); text-decoration: none; }
.navlink--active { background: var(--surface); color: var(--ink); font-weight: 600; box-shadow: var(--sh-1); }
.navlink--active .navlink__icon { color: var(--purple); }
.navlink__icon { width: 18px; height: 18px; color: var(--ink-3); flex: none; }
.navlink--foot { margin-top: auto; }

.scrim { display: none; }

.main { display: flex; flex-direction: column; min-width: 0; }
.topbar { position: sticky; top: 0; z-index: var(--z-topbar); display: flex; align-items: center; gap: var(--sp-2);
  height: var(--topbar-h); padding: 0 var(--sp-5); background: color-mix(in srgb, var(--paper) 80%, transparent);
  backdrop-filter: saturate(140%) blur(10px); border-bottom: 1px solid var(--line); }
.topbar__burger { display: none; }
.topbar__search { display: flex; align-items: center; gap: 10px; height: 36px; width: min(360px, 42vw); padding: 0 12px;
  border-radius: var(--r-sm); border: 1px solid var(--line-2); background: var(--surface); color: var(--ink-3);
  font-size: var(--text-sm); transition: border-color var(--dur-1), box-shadow var(--dur-1); }
.topbar__search:hover { border-color: var(--line-strong); }
.topbar__searchicon { width: 15px; height: 15px; }
.topbar__search kbd { margin-left: auto; font-family: var(--font-mono); font-size: 10px; padding: 2px 6px;
  border: 1px solid var(--line-2); border-radius: 5px; background: var(--paper-2); color: var(--ink-3); }
.topbar__new { gap: 4px; }

.acct { position: relative; }
.acct__btn { padding: 2px; border-radius: 50%; }
.acct__avatar { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%;
  background: var(--purple-soft); color: var(--purple-ink); font-size: var(--text-sm); font-weight: 700; }
.acct__menu { position: absolute; right: 0; top: 46px; width: 230px; background: var(--surface-elevated);
  border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--sh-3); padding: var(--sp-2); display: grid; gap: 2px; }
.acct__head { display: grid; padding: var(--sp-2) var(--sp-3) var(--sp-3); gap: 2px; border-bottom: 1px solid var(--line); margin-bottom: var(--sp-1); }
.acct__item { text-align: left; padding: 8px var(--sp-3); border-radius: var(--r-xs); font-size: var(--text-base); color: var(--ink-2); }
.acct__item:hover { background: var(--surface-2); color: var(--ink); text-decoration: none; }
.acct__item--danger { color: var(--danger-ink); }

.content { padding: var(--sp-6) var(--sp-8) var(--sp-16); max-width: var(--container); width: 100%; margin: 0 auto; }

@media (max-width: 900px) {
  .shell { grid-template-columns: 1fr; }
  .rail { position: fixed; left: 0; top: 0; width: var(--rail-w); transform: translateX(-100%);
    transition: transform var(--dur-3) var(--ease-out); box-shadow: var(--sh-4); }
  .shell--mobile-open .rail { transform: translateX(0); }
  .shell--mobile-open .scrim { display: block; position: fixed; inset: 0; background: var(--overlay); z-index: calc(var(--z-rail) - 1); }
  .topbar__burger { display: inline-grid; }
  .topbar__search { width: auto; flex: 1; }
  .topbar__search span { display: none; }
  .content { padding: var(--sp-4) var(--sp-4) var(--sp-16); }
}
</style>
