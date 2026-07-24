import { defineStore } from 'pinia'
import { ref, reactive } from 'vue'

// The create actions the app can trigger from anywhere (New menu, dashboard,
// command palette, list pages). Every one maps to a real create form + endpoint.
export type CreateType = 'lead' | 'contact' | 'company' | 'task' | 'deal' | 'email'

/**
 * Small shared UI store: which create form is open, a per-resource "version"
 * counter that list views watch to reload after a successful write, and a toast
 * for accurate post-write feedback ("Lead created", "Email queued", …).
 */
export const useUi = defineStore('ui', () => {
  const createType = ref<CreateType | null>(null)
  const createContext = ref<Record<string, string>>({})

  // Bumped when a resource changes so open list views reload real data.
  const version = reactive<Record<string, number>>({
    leads: 0, contacts: 0, companies: 0, tasks: 0, deals: 0, emails: 0,
  })

  const toastMsg = ref('')
  let toastTimer: ReturnType<typeof setTimeout> | undefined

  function openCreate(type: CreateType, context: Record<string, string> = {}) {
    createContext.value = context
    createType.value = type
  }
  function closeCreate() {
    createType.value = null
    createContext.value = {}
  }

  function toast(message: string) {
    toastMsg.value = message
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => (toastMsg.value = ''), 2800)
  }

  /** Signal that a resource changed and show accurate feedback. */
  function changed(resource: keyof typeof version, message: string) {
    version[resource] = (version[resource] ?? 0) + 1
    toast(message)
  }

  return { createType, createContext, version, toastMsg, openCreate, closeCreate, toast, changed }
})
