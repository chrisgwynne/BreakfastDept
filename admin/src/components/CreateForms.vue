<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useUi } from '@/stores/ui'
import type { StageRef } from '@/lib/types'
import Sheet from '@/components/Sheet.vue'

const ui = useUi()

const TITLES: Record<string, { eyebrow: string; title: string }> = {
  lead:    { eyebrow: 'CRM', title: 'New lead' },
  contact: { eyebrow: 'CRM', title: 'New contact' },
  company: { eyebrow: 'CRM', title: 'New company' },
  task:    { eyebrow: 'Work', title: 'New task' },
  deal:    { eyebrow: 'Pipeline', title: 'New opportunity' },
  email:   { eyebrow: 'Messages', title: 'Compose email' },
}

const form = reactive<Record<string, string>>({})
const errors = ref<Record<string, string>>({})
const generalError = ref('')
const saving = ref(false)
const stages = ref<StageRef[]>([])

const open = computed(() => ui.createType !== null)
const meta = computed(() => (ui.createType ? TITLES[ui.createType] : { eyebrow: '', title: '' }))

// Reset the form each time a create sheet opens; seed any context (e.g. the
// contact/company to relate an email or task to).
watch(() => ui.createType, async (type) => {
  for (const k of Object.keys(form)) delete form[k]
  errors.value = {}
  generalError.value = ''
  Object.assign(form, ui.createContext)
  if (type === 'deal' && stages.value.length === 0) {
    try { stages.value = (await api.get<{ stages: StageRef[] }>('/opportunities')).stages } catch { /* leave empty */ }
  }
})

function close() {
  ui.closeCreate()
}

async function submit() {
  if (saving.value || !ui.createType) return
  saving.value = true
  errors.value = {}
  generalError.value = ''
  const type = ui.createType
  try {
    if (type === 'lead') {
      await api.post('/enquiries', pick(['name', 'email', 'company', 'phone', 'source', 'enquiry_type', 'status', 'next_action', 'follow_up_date', 'notes']))
      ui.changed('leads', 'Lead created')
      ui.changed('contacts', 'Lead created')
    } else if (type === 'contact') {
      await api.post('/contacts', pick(['first_name', 'last_name', 'email', 'phone', 'job_title', 'company', 'notes']))
      ui.changed('contacts', 'Contact created')
    } else if (type === 'company') {
      await api.post('/companies', pick(['name', 'website', 'sector', 'location', 'notes']))
      ui.changed('companies', 'Company created')
    } else if (type === 'task') {
      await api.post('/tasks', pick(['title', 'due_date', 'priority', 'notes', 'contact_uuid', 'opportunity_uuid']))
      ui.changed('tasks', 'Task created')
    } else if (type === 'deal') {
      await api.post('/opportunities', pick(['title', 'stage', 'value', 'probability', 'contact_uuid', 'company_uuid']))
      ui.changed('deals', 'Opportunity created')
    } else if (type === 'email') {
      const res = await api.post<{ message: string }>('/email/send', pick(['to', 'subject', 'body', 'contact_uuid', 'enquiry_uuid', 'opportunity_uuid']))
      ui.changed('emails', res.message || 'Email queued')
    }
    close()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) errors.value = e.fields
    else generalError.value = e instanceof Error ? e.message : 'Something went wrong.'
  } finally {
    saving.value = false
  }
}

async function sendTest() {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  generalError.value = ''
  try {
    const res = await api.post<{ message: string }>('/email/test', pick(['subject', 'body']))
    ui.toast(res.message || 'Test email queued')
    close()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) errors.value = e.fields
    else generalError.value = e instanceof Error ? e.message : 'Could not send the test.'
  } finally {
    saving.value = false
  }
}

function pick(keys: string[]): Record<string, string> {
  const out: Record<string, string> = {}
  for (const k of keys) if (form[k] !== undefined && form[k] !== '') out[k] = form[k]
  return out
}
</script>

<template>
  <Sheet :open="open" :eyebrow="meta.eyebrow" :title="meta.title" @close="close">
    <form class="cf" @submit.prevent="submit">
      <!-- LEAD -->
      <template v-if="ui.createType === 'lead'">
        <Field label="Name" name="name" :err="errors.name"><input class="input" v-model="form.name" autocomplete="off" placeholder="e.g. Sian Roberts" /></Field>
        <Field label="Email" name="email" :err="errors.email"><input class="input" v-model="form.email" type="email" placeholder="sian@example.co.uk" /></Field>
        <Field label="Company" name="company"><input class="input" v-model="form.company" placeholder="e.g. Roberts Cafe" /></Field>
        <div class="cf__row">
          <Field label="Phone" name="phone"><input class="input" v-model="form.phone" /></Field>
          <Field label="Source" name="source"><input class="input" v-model="form.source" placeholder="referral, search…" /></Field>
        </div>
        <div class="cf__row">
          <Field label="Enquiry type" name="enquiry_type">
            <select class="select" v-model="form.enquiry_type"><option value="">General</option><option value="website-review">Website review</option><option value="quote">Quote</option><option value="project">Project</option></select>
          </Field>
          <Field label="Status" name="status">
            <select class="select" v-model="form.status"><option value="new">New</option><option value="qualified">Qualified</option></select>
          </Field>
        </div>
        <div class="cf__row">
          <Field label="Next action" name="next_action"><input class="input" v-model="form.next_action" placeholder="Send proposal" /></Field>
          <Field label="Follow-up date" name="follow_up_date"><input class="input" v-model="form.follow_up_date" type="date" /></Field>
        </div>
        <Field label="Notes" name="notes"><textarea class="textarea" v-model="form.notes" rows="3"></textarea></Field>
      </template>

      <!-- CONTACT -->
      <template v-else-if="ui.createType === 'contact'">
        <div class="cf__row">
          <Field label="First name" name="first_name" :err="errors.first_name"><input class="input" v-model="form.first_name" /></Field>
          <Field label="Last name" name="last_name"><input class="input" v-model="form.last_name" /></Field>
        </div>
        <Field label="Email" name="email" :err="errors.email"><input class="input" v-model="form.email" type="email" /></Field>
        <div class="cf__row">
          <Field label="Phone" name="phone"><input class="input" v-model="form.phone" /></Field>
          <Field label="Job title" name="job_title"><input class="input" v-model="form.job_title" /></Field>
        </div>
        <Field label="Company" name="company"><input class="input" v-model="form.company" /></Field>
        <Field label="Notes" name="notes"><textarea class="textarea" v-model="form.notes" rows="3"></textarea></Field>
      </template>

      <!-- COMPANY -->
      <template v-else-if="ui.createType === 'company'">
        <Field label="Company name" name="name" :err="errors.name"><input class="input" v-model="form.name" /></Field>
        <div class="cf__row">
          <Field label="Website" name="website"><input class="input" v-model="form.website" placeholder="https://" /></Field>
          <Field label="Sector" name="sector"><input class="input" v-model="form.sector" /></Field>
        </div>
        <Field label="Location" name="location"><input class="input" v-model="form.location" /></Field>
        <Field label="Notes" name="notes"><textarea class="textarea" v-model="form.notes" rows="3"></textarea></Field>
      </template>

      <!-- TASK -->
      <template v-else-if="ui.createType === 'task'">
        <Field label="Title" name="title" :err="errors.title"><input class="input" v-model="form.title" placeholder="Follow up with…" /></Field>
        <div class="cf__row">
          <Field label="Due date" name="due_date"><input class="input" v-model="form.due_date" type="date" /></Field>
          <Field label="Priority" name="priority">
            <select class="select" v-model="form.priority"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>
          </Field>
        </div>
        <Field label="Notes" name="notes"><textarea class="textarea" v-model="form.notes" rows="3"></textarea></Field>
      </template>

      <!-- DEAL -->
      <template v-else-if="ui.createType === 'deal'">
        <Field label="Title" name="title" :err="errors.title"><input class="input" v-model="form.title" placeholder="e.g. Cafe website" /></Field>
        <div class="cf__row">
          <Field label="Stage" name="stage" :err="errors.stage">
            <select class="select" v-model="form.stage">
              <option v-for="s in stages" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </Field>
          <Field label="Value (£)" name="value"><input class="input" v-model="form.value" type="number" min="0" /></Field>
        </div>
        <Field label="Probability (%)" name="probability"><input class="input" v-model="form.probability" type="number" min="0" max="100" /></Field>
      </template>

      <!-- EMAIL -->
      <template v-else-if="ui.createType === 'email'">
        <Field label="To" name="to" :err="errors.to"><input class="input" v-model="form.to" type="email" placeholder="client@example.co.uk" /></Field>
        <Field label="Subject" name="subject" :err="errors.subject"><input class="input" v-model="form.subject" /></Field>
        <Field label="Message" name="body" :err="errors.body"><textarea class="textarea" v-model="form.body" rows="8"></textarea></Field>
        <p class="cf__hint">Messages are added to the send queue and delivered by the mail provider. You’ll see the status in the Email log.</p>
      </template>

      <p v-if="generalError" class="cf__err" role="alert">{{ generalError }}</p>
    </form>

    <template #footer>
      <button v-if="ui.createType === 'email'" class="btn btn--sm" :disabled="saving" @click="sendTest">Send test to me</button>
      <button class="btn btn--sm" @click="close">Cancel</button>
      <button class="btn btn--primary btn--sm" :disabled="saving" @click="submit">
        {{ saving ? 'Saving…' : (ui.createType === 'email' ? 'Queue email' : 'Create') }}
      </button>
    </template>
  </Sheet>
</template>

<script lang="ts">
import { defineComponent, h } from 'vue'
// Tiny inline field wrapper (label + slot + error) to keep the template lean.
const Field = defineComponent({
  name: 'Field',
  props: { label: String, name: String, err: String },
  setup(props, { slots }) {
    return () => h('div', { class: ['field', props.err ? 'field--error' : ''] }, [
      h('label', { class: 'label', for: props.name }, props.label),
      slots.default?.(),
      props.err ? h('p', { class: 'field__err' }, props.err) : null,
    ])
  },
})
export default { components: { Field } }
</script>

<style scoped>
.cf { display: grid; gap: var(--sp-3); }
.cf__row { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.cf__hint { font-size: var(--text-xs); color: var(--ink-3); line-height: 1.5; }
.cf__err { color: var(--danger-ink); font-size: var(--text-sm); font-weight: 500; background: var(--danger-soft); padding: 8px var(--sp-3); border-radius: var(--r-sm); }
:deep(.field--error .input), :deep(.field--error .select), :deep(.field--error .textarea) { border-color: var(--danger); }
:deep(.field__err) { color: var(--danger-ink); font-size: var(--text-xs); margin-top: 3px; }
@media (max-width: 560px) { .cf__row { grid-template-columns: 1fr; } }
</style>
