import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, setCsrf, ApiError } from '@/lib/api'
import type { Session, SessionUser } from '@/lib/types'

export const useAuth = defineStore('auth', () => {
  const user = ref<SessionUser | null>(null)
  const ready = ref(false)

  const isAuthed = computed(() => user.value !== null)

  function apply(session: Session): void {
    user.value = session.user
    setCsrf(session.csrf)
  }

  /** Load the current session on boot; never throws. */
  async function bootstrap(): Promise<void> {
    try {
      apply(await api.get<Session>('/session'))
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
  }

  async function login(email: string, password: string, remember: boolean): Promise<void> {
    // A pre-flight GET seeds the CSRF token for the POST.
    try {
      const pre = await api.get<{ csrf: string }>('/session/csrf')
      setCsrf(pre.csrf)
    } catch {
      /* first-load may 401; the login endpoint also accepts a fresh token */
    }
    apply(await api.post<Session>('/session', { email, password, remember }))
  }

  /** Update the signed-in user's own display name (the greeting name). */
  async function updateName(name: string): Promise<void> {
    apply(await api.patch<Session>('/session', { name }))
  }

  async function logout(): Promise<void> {
    try {
      await api.del('/session')
    } catch (e) {
      if (!(e instanceof ApiError)) throw e
    }
    user.value = null
  }

  function can(permission: string): boolean {
    return user.value?.permissions.includes(permission) ?? false
  }

  return { user, ready, isAuthed, bootstrap, login, logout, updateName, can }
})
