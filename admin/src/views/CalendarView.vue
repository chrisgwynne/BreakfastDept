<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import type { CalendarEvent, Contact, ListResponse } from '@/lib/types'
import PageHeader from '@/components/PageHeader.vue'
import DataState from '@/components/DataState.vue'
import Sheet from '@/components/Sheet.vue'
import NavIcon from '@/components/NavIcon.vue'

const auth = useAuth()
const ui = useUi()
const route = useRoute()
const canManage = computed(() => auth.can('calendar.manage') || auth.can('admin'))

type View = 'month' | 'week' | 'day' | 'agenda'
const view = ref<View>('month')
const cursor = ref(new Date())
const events = ref<CalendarEvent[]>([])
const loading = ref(true)
const error = ref('')

const TYPES = [
  ['call', 'Call'], ['meeting', 'Meeting'], ['review', 'Design review'], ['deadline', 'Deadline'],
  ['follow_up', 'Follow-up'], ['reminder', 'Reminder'], ['task', 'Task block'], ['milestone', 'Milestone'], ['other', 'Other'],
]
const TYPE_LABEL: Record<string, string> = Object.fromEntries(TYPES)
const REMINDERS = [[0, 'No reminder'], [10, '10 min before'], [30, '30 min before'], [60, '1 hour before'], [1440, '1 day before']]

// -- range for the current view -----------------------------------------
function startOfDay(d: Date): Date { const x = new Date(d); x.setHours(0, 0, 0, 0); return x }
function addDays(d: Date, n: number): Date { const x = new Date(d); x.setDate(x.getDate() + n); return x }
function startOfWeek(d: Date): Date { const x = startOfDay(d); const day = (x.getDay() + 6) % 7; return addDays(x, -day) } // Monday
function sameDay(a: Date, b: Date): boolean { return a.toDateString() === b.toDateString() }

const range = computed(() => {
  const c = cursor.value
  if (view.value === 'day') return { from: startOfDay(c), to: addDays(startOfDay(c), 1) }
  if (view.value === 'week') return { from: startOfWeek(c), to: addDays(startOfWeek(c), 7) }
  if (view.value === 'agenda') return { from: startOfDay(c), to: addDays(startOfDay(c), 60) }
  // month: pad to full weeks
  const first = new Date(c.getFullYear(), c.getMonth(), 1)
  const from = startOfWeek(first)
  return { from, to: addDays(from, 42) }
})

async function load() {
  loading.value = true; error.value = ''
  try {
    const r = range.value
    const res = await api.get<{ items: CalendarEvent[] }>(`/calendar/events?from=${r.from.toISOString()}&to=${r.to.toISOString()}`)
    events.value = res.items
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Could not load the calendar.'
  } finally {
    loading.value = false
  }
}
watch([view, cursor], load)

// -- grid builders -------------------------------------------------------
const monthDays = computed(() => {
  const from = range.value.from
  return Array.from({ length: 42 }, (_, i) => addDays(from, i))
})
const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => addDays(startOfWeek(cursor.value), i)))

function eventsOn(day: Date): CalendarEvent[] {
  return events.value
    .filter((e) => sameDay(new Date(e.starts_at), day))
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
}
const agendaEvents = computed(() => [...events.value].sort((a, b) => a.starts_at.localeCompare(b.starts_at)))

const title = computed(() => {
  const c = cursor.value
  if (view.value === 'day') return c.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  if (view.value === 'week') { const s = startOfWeek(c); const e = addDays(s, 6); return `${s.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} – ${e.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}` }
  return c.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
})

function move(dir: number) {
  const c = new Date(cursor.value)
  if (view.value === 'day') c.setDate(c.getDate() + dir)
  else if (view.value === 'week') c.setDate(c.getDate() + dir * 7)
  else if (view.value === 'agenda') c.setDate(c.getDate() + dir * 30)
  else c.setMonth(c.getMonth() + dir)
  cursor.value = c
}
function today() { cursor.value = new Date() }

function fmtTime(iso: string): string {
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '' : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}
function fmtDay(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
}

// -- editor --------------------------------------------------------------
const editorOpen = ref(false)
const editing = ref<CalendarEvent | null>(null)
const saving = ref(false)
const editErr = ref('')
const fieldErrors = ref<Record<string, string>>({})
type Form = {
  title: string; event_type: string; status: string; all_day: boolean
  starts_at: string; ends_at: string; location: string; meeting_link: string; description: string
  recurrence: string; recurrence_interval: number; recurrence_count: number; reminder_minutes: number
  contact_uuid: string
}
const form = ref<Form>(blank())
function blank(): Form {
  return { title: '', event_type: 'meeting', status: 'confirmed', all_day: false, starts_at: '', ends_at: '', location: '', meeting_link: '', description: '', recurrence: '', recurrence_interval: 1, recurrence_count: 0, reminder_minutes: 0, contact_uuid: '' }
}
function toLocalInput(iso: string): string {
  const d = iso ? new Date(iso) : new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
function fromLocalInput(v: string): string { return v ? new Date(v).toISOString() : '' }

function newEvent(day?: Date) {
  const base = day ? new Date(day) : new Date()
  if (!day) base.setMinutes(0, 0, 0)
  base.setHours(base.getHours() || 9)
  const end = new Date(base); end.setHours(base.getHours() + 1)
  editing.value = null
  form.value = { ...blank(), starts_at: toLocalInput(base.toISOString()), ends_at: toLocalInput(end.toISOString()) }
  fieldErrors.value = {}; editErr.value = ''
  editorOpen.value = true
}
function openEvent(e: CalendarEvent) {
  editing.value = e
  form.value = {
    title: e.title, event_type: e.event_type, status: e.status, all_day: e.all_day,
    starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at), location: e.location,
    meeting_link: e.meeting_link, description: e.description, recurrence: e.recurrence,
    recurrence_interval: e.recurrence_interval || 1, recurrence_count: e.recurrence_count || 0,
    reminder_minutes: e.reminder_minutes || 0, contact_uuid: e.contact_uuid,
  }
  fieldErrors.value = {}; editErr.value = ''
  editorOpen.value = true
}

async function save() {
  if (saving.value) return
  saving.value = true; editErr.value = ''; fieldErrors.value = {}
  const payload = {
    ...form.value,
    starts_at: fromLocalInput(form.value.starts_at),
    ends_at: fromLocalInput(form.value.ends_at),
    contact_uuid: form.value.contact_uuid || null,
  }
  try {
    if (editing.value) {
      await api.patch(`/calendar/events/${editing.value.id}`, { ...payload, scope: 'series' })
      ui.toast('Event updated')
    } else {
      await api.post('/calendar/events', payload)
      ui.toast('Event created')
    }
    editorOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) { fieldErrors.value = e.fields; editErr.value = 'Please fix the highlighted fields.' }
    else editErr.value = e instanceof ApiError ? e.message : 'Could not save the event.'
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!editing.value || saving.value) return
  saving.value = true
  try {
    await api.del(`/calendar/events/${editing.value.id}`, { scope: 'series' })
    ui.toast('Event deleted')
    editorOpen.value = false
    await load()
  } catch (e) {
    editErr.value = e instanceof ApiError ? e.message : 'Could not delete the event.'
  } finally {
    saving.value = false
  }
}

// -- contact picker (optional client link) -------------------------------
const contacts = ref<Contact[]>([])
async function loadContacts() {
  try { contacts.value = (await api.get<ListResponse<Contact>>('/contacts')).items } catch { /* non-critical */ }
}

onMounted(() => {
  // Deep link: /calendar?new=1&contact=uuid (from a client workspace).
  loadContacts()
  load()
  if (route.query.new) {
    newEvent()
    if (typeof route.query.contact === 'string') form.value.contact_uuid = route.query.contact
  }
})
</script>

<template>
  <div>
    <PageHeader eyebrow="Schedule" title="Calendar" sub="Calls, meetings, deadlines and follow-ups — linked to your clients.">
      <template #actions>
        <button v-if="canManage" class="btn btn--sm btn--primary" @click="newEvent()"><NavIcon name="plus" /> New event</button>
      </template>
    </PageHeader>

    <div class="bar">
      <div class="nav">
        <button class="btn btn--sm" @click="move(-1)" aria-label="Previous">‹</button>
        <button class="btn btn--sm" @click="today">Today</button>
        <button class="btn btn--sm" @click="move(1)" aria-label="Next">›</button>
        <strong class="bar__title">{{ title }}</strong>
      </div>
      <div class="views">
        <button v-for="v in (['month','week','day','agenda'] as const)" :key="v" class="tab" :class="{ 'tab--active': view === v }" @click="view = v">{{ v[0].toUpperCase() + v.slice(1) }}</button>
      </div>
    </div>

    <DataState :loading="loading" :error="error" :empty="false" @retry="load">
      <!-- Month -->
      <div v-if="view === 'month'" class="month card">
        <div class="month__head"><span v-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d">{{ d }}</span></div>
        <div class="month__grid">
          <div v-for="(day, i) in monthDays" :key="i" class="cell"
               :class="{ 'cell--out': day.getMonth() !== cursor.getMonth(), 'cell--today': sameDay(day, new Date()) }"
               @click="canManage && newEvent(day)">
            <span class="cell__n">{{ day.getDate() }}</span>
            <button v-for="e in eventsOn(day)" :key="e.id + e.occurrence_date" class="chip" :class="`chip--${e.event_type}`"
                    @click.stop="openEvent(e)"><span class="chip__t">{{ e.all_day ? '' : fmtTime(e.starts_at) }}</span> {{ e.title }}</button>
          </div>
        </div>
      </div>

      <!-- Week / Day -->
      <div v-else-if="view === 'week' || view === 'day'" class="cols card">
        <div v-for="day in (view === 'day' ? [cursor] : weekDays)" :key="day.toISOString()" class="col">
          <div class="col__head" :class="{ 'col__head--today': sameDay(day, new Date()) }">
            <span class="col__dow">{{ day.toLocaleDateString('en-GB', { weekday: 'short' }) }}</span>
            <span class="col__d">{{ day.getDate() }}</span>
          </div>
          <div class="col__body" @click="canManage && newEvent(day)">
            <button v-for="e in eventsOn(day)" :key="e.id + e.occurrence_date" class="evt" :class="`chip--${e.event_type}`" @click.stop="openEvent(e)">
              <strong>{{ e.all_day ? 'All day' : fmtTime(e.starts_at) }}</strong> {{ e.title }}
            </button>
            <p v-if="!eventsOn(day).length" class="col__empty">—</p>
          </div>
        </div>
      </div>

      <!-- Agenda -->
      <div v-else class="agenda card">
        <button v-for="e in agendaEvents" :key="e.id + e.occurrence_date" class="arow" @click="openEvent(e)">
          <span class="arow__date">{{ fmtDay(e.starts_at) }}</span>
          <span class="arow__time">{{ e.all_day ? 'All day' : fmtTime(e.starts_at) }}</span>
          <span class="arow__dot" :class="`chip--${e.event_type}`"></span>
          <span class="arow__title truncate">{{ e.title }}</span>
          <span class="arow__type faint">{{ TYPE_LABEL[e.event_type] || e.event_type }}</span>
        </button>
        <p v-if="!agendaEvents.length" class="col__empty">Nothing scheduled in the next 60 days.</p>
      </div>
    </DataState>

    <!-- Editor -->
    <Sheet :open="editorOpen" eyebrow="Calendar" :title="editing ? 'Edit event' : 'New event'" @close="editorOpen = false">
      <div class="field"><label class="label">Title</label><input class="input" v-model="form.title" placeholder="e.g. Discovery call with…" />
        <p v-if="fieldErrors.title" class="ferr">{{ fieldErrors.title }}</p></div>
      <div class="two">
        <div class="field"><label class="label">Type</label><select class="input" v-model="form.event_type"><option v-for="[v,l] in TYPES" :key="v" :value="v">{{ l }}</option></select></div>
        <div class="field"><label class="label">Status</label><select class="input" v-model="form.status"><option value="tentative">Tentative</option><option value="confirmed">Confirmed</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
      </div>
      <label class="toggle"><input type="checkbox" v-model="form.all_day" /> <span>All day</span></label>
      <div class="two">
        <div class="field"><label class="label">Starts</label><input class="input" type="datetime-local" v-model="form.starts_at" />
          <p v-if="fieldErrors.starts_at" class="ferr">{{ fieldErrors.starts_at }}</p></div>
        <div class="field"><label class="label">Ends</label><input class="input" type="datetime-local" v-model="form.ends_at" />
          <p v-if="fieldErrors.ends_at" class="ferr">{{ fieldErrors.ends_at }}</p></div>
      </div>
      <div class="field"><label class="label">Client (optional)</label>
        <select class="input" v-model="form.contact_uuid"><option value="">— none —</option><option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name || c.email }}</option></select></div>
      <div class="two">
        <div class="field"><label class="label">Location</label><input class="input" v-model="form.location" /></div>
        <div class="field"><label class="label">Meeting link</label><input class="input" v-model="form.meeting_link" placeholder="https://…" /></div>
      </div>
      <div class="two">
        <div class="field"><label class="label">Repeats</label><select class="input" v-model="form.recurrence"><option value="">Does not repeat</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>
        <div class="field"><label class="label">Reminder</label><select class="input" v-model.number="form.reminder_minutes"><option v-for="[v,l] in REMINDERS" :key="v" :value="v">{{ l }}</option></select></div>
      </div>
      <div v-if="form.recurrence" class="two">
        <div class="field"><label class="label">Every</label><input class="input" type="number" min="1" v-model.number="form.recurrence_interval" /></div>
        <div class="field"><label class="label">For (occurrences, 0 = forever)</label><input class="input" type="number" min="0" v-model.number="form.recurrence_count" /></div>
      </div>
      <div class="field"><label class="label">Notes</label><textarea class="textarea" v-model="form.description" rows="3"></textarea></div>
      <p v-if="editErr" class="ferr" role="alert">{{ editErr }}</p>

      <template #footer>
        <button v-if="editing && canManage" class="btn btn--sm btn--danger" :disabled="saving" @click="remove">Delete</button>
        <div class="grow"></div>
        <button class="btn btn--sm" @click="editorOpen = false">Cancel</button>
        <button v-if="canManage" class="btn btn--primary btn--sm" :disabled="saving" @click="save">{{ saving ? 'Saving…' : (editing ? 'Save' : 'Create event') }}</button>
      </template>
    </Sheet>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3); margin-bottom: var(--sp-4); flex-wrap: wrap; }
.nav { display: flex; align-items: center; gap: var(--sp-2); }
.bar__title { font-size: var(--text-lg); margin-left: var(--sp-2); }
.views { display: flex; gap: 2px; }
.tab { padding: 6px var(--sp-3); font-size: var(--text-sm); font-weight: 550; color: var(--ink-3); border-radius: var(--r-sm); }
.tab--active { color: var(--ink); background: var(--surface-2); }

.month__head { display: grid; grid-template-columns: repeat(7, 1fr); padding: var(--sp-2) 0; border-bottom: 1px solid var(--line); }
.month__head span { text-align: center; font-size: var(--text-xs); font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.month__grid { display: grid; grid-template-columns: repeat(7, 1fr); }
.cell { min-height: 96px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 4px; display: flex; flex-direction: column; gap: 2px; cursor: pointer; }
.cell:nth-child(7n) { border-right: none; }
.cell--out { background: var(--paper-2); } .cell--out .cell__n { color: var(--ink-4); }
.cell--today .cell__n { background: var(--purple); color: #fff; border-radius: 50%; width: 22px; height: 22px; display: grid; place-items: center; }
.cell__n { font-size: var(--text-sm); font-weight: 600; }
.chip { text-align: left; font-size: 11px; padding: 2px 6px; border-radius: 4px; background: var(--purple-soft); color: var(--purple-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border-left: 3px solid var(--purple); }
.chip__t { font-variant-numeric: tabular-nums; opacity: 0.75; }

.cols { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; overflow-x: auto; }
.col { border-right: 1px solid var(--line); min-width: 120px; }
.col:last-child { border-right: none; }
.col__head { text-align: center; padding: var(--sp-2); border-bottom: 1px solid var(--line); }
.col__head--today { background: var(--purple-soft); }
.col__dow { display: block; font-size: var(--text-xs); color: var(--ink-3); text-transform: uppercase; }
.col__d { font-size: var(--text-lg); font-weight: 600; }
.col__body { min-height: 220px; padding: 6px; display: grid; gap: 4px; align-content: start; cursor: pointer; }
.evt { text-align: left; font-size: 12px; padding: 5px 7px; border-radius: 6px; background: var(--purple-soft); color: var(--purple-ink); border-left: 3px solid var(--purple); }
.col__empty { text-align: center; color: var(--ink-4); font-size: var(--text-sm); padding: var(--sp-3); }

.agenda { padding: 6px; }
.arow { display: grid; grid-template-columns: 120px 64px 10px 1fr auto; align-items: center; gap: var(--sp-3); width: 100%; padding: 11px var(--sp-3); border-radius: var(--r-sm); text-align: left; }
.arow:hover { background: var(--surface-2); } .arow + .arow { border-top: 1px solid var(--line); }
.arow__date { font-size: var(--text-sm); font-weight: 600; } .arow__time { font-size: var(--text-sm); color: var(--ink-3); font-variant-numeric: tabular-nums; }
.arow__dot { width: 10px; height: 10px; border-radius: 50%; background: var(--purple); }
.arow__type { font-size: var(--text-xs); }

/* type accents */
.chip--call, .evt.chip--call, .arow__dot.chip--call { --purple: var(--info-ink); background: var(--info-soft); color: var(--info-ink); }
.chip--deadline, .evt.chip--deadline, .arow__dot.chip--deadline { --purple: var(--danger-ink); background: var(--danger-soft); color: var(--danger-ink); }
.chip--follow_up, .evt.chip--follow_up, .arow__dot.chip--follow_up { --purple: var(--warning-ink); background: var(--warning-soft); color: var(--warning-ink); }
.chip--milestone, .evt.chip--milestone, .arow__dot.chip--milestone { --purple: var(--success-ink); background: var(--success-soft); color: var(--success-ink); }
.arow__dot.chip--call, .arow__dot.chip--deadline, .arow__dot.chip--follow_up, .arow__dot.chip--milestone { background: currentColor; }

.two { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-3); }
.toggle { display: flex; align-items: center; gap: 8px; font-size: var(--text-sm); margin: var(--sp-2) 0; }
.toggle input { width: 16px; height: 16px; }
.ferr { color: var(--danger-ink); font-size: var(--text-xs); margin-top: 4px; }
.grow { flex: 1; }
@media (max-width: 620px) { .two { grid-template-columns: 1fr; } .cell { min-height: 64px; } .arow { grid-template-columns: 90px 1fr auto; } .arow__time, .arow__dot { display: none; } }
</style>
