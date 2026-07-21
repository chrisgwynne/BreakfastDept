/**
 * Breakfast CRM — Panel plugin.
 *
 * Registers the Vue views used by the custom "CRM" Panel area. Each view loads
 * its data from the permission-guarded plugin API (site/plugins/.../api/crm.php);
 * the server enforces access on every request, so these components never carry
 * authority of their own — hiding a button is never the access control.
 *
 * Uses Kirby's bundled Panel component library (k-*) and template strings, so
 * no build step is required.
 */
(function () {
  "use strict";

  var money = function (pence, currency) {
    var n = (pence || 0) / 100;
    try {
      return new Intl.NumberFormat("en-GB", { style: "currency", currency: currency || "GBP", maximumFractionDigits: 0 }).format(n);
    } catch (e) {
      return "£" + n.toFixed(0);
    }
  };

  var Dashboard = {
    props: { canManage: Boolean },
    data: function () {
      return { loading: true, d: null, error: null };
    },
    created: function () {
      var self = this;
      this.$api
        .get("breakfast/crm/dashboard")
        .then(function (d) { self.d = d; self.loading = false; })
        .catch(function (e) { self.error = e.message || "Failed to load"; self.loading = false; });
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>CRM<template #buttons>
          <k-button-group>
            <k-button icon="email" link="/crm/enquiries">Enquiries</k-button>
            <k-button icon="chart" link="/crm/pipeline">Pipeline</k-button>
            <k-button icon="check" link="/crm/tasks">Tasks</k-button>
          </k-button-group>
        </template></k-header>
        <k-text v-if="error"><k-box theme="negative">{{ error }}</k-box></k-text>
        <k-text v-else-if="loading">Loading…</k-text>
        <template v-else>
          <div class="k-crm-grid">
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ d.new_enquiries }}</div><div class="k-crm-stat__label">New enquiries</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ d.open_opportunities }}</div><div class="k-crm-stat__label">Open opportunities</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ pipelineValue }}</div><div class="k-crm-stat__label">Pipeline value</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ d.overdue_tasks }}</div><div class="k-crm-stat__label">Overdue tasks</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ d.upcoming_tasks }}</div><div class="k-crm-stat__label">Upcoming tasks</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ d.failed_jobs }}</div><div class="k-crm-stat__label">Failed jobs</div></div>
          </div>
          <k-headline class="k-mt-huge">Recent activity</k-headline>
          <k-table :columns="activityColumns" :rows="d.recent_activity" />
        </template>
      </k-panel-inside>
    `,
    computed: {
      pipelineValue: function () { return money(this.d ? this.d.pipeline_value : 0); },
      activityColumns: function () {
        return { summary: { label: "Activity" }, type: { label: "Type", width: "1/6" }, created_at: { label: "When", type: "date", width: "1/6" } };
      },
    },
  };

  var Enquiries = {
    props: { canManage: Boolean },
    data: function () { return { rows: [], loading: true, status: "" }; },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        self.loading = true;
        this.$api.get("breakfast/crm/enquiries", { status: this.status }).then(function (r) {
          self.rows = (r.data || []).map(function (e) {
            return {
              reference: e.reference,
              summary: e.summary,
              status: e.status,
              created_at: e.created_at,
              link: "/crm/enquiries/" + e.uuid,
            };
          });
          self.loading = false;
        });
      },
      filter: function (status) { this.status = status; this.load(); },
    },
    computed: {
      columns: function () {
        return {
          reference: { label: "Ref", type: "url", width: "1/6" },
          summary: { label: "Summary" },
          status: { label: "Status", width: "1/6" },
          created_at: { label: "Received", type: "date", width: "1/6" },
        };
      },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>Enquiries</k-header>
        <k-button-group class="k-mb-medium">
          <k-button @click="filter('')">All</k-button>
          <k-button @click="filter('new')">New</k-button>
          <k-button @click="filter('qualified')">Qualified</k-button>
          <k-button @click="filter('spam')">Spam</k-button>
        </k-button-group>
        <k-text v-if="loading">Loading…</k-text>
        <k-empty v-else-if="!rows.length" icon="email">No enquiries yet.</k-empty>
        <div v-else>
          <div v-for="r in rows" :key="r.reference" class="k-crm-card">
            <k-link :to="r.link"><strong>{{ r.reference }}</strong></k-link> — {{ r.summary }}
            <span class="k-crm-badge" :class="{'k-crm-badge--new': r.status==='new','k-crm-badge--spam': r.status==='spam'}">{{ r.status }}</span>
          </div>
        </div>
      </k-panel-inside>
    `,
  };

  var Enquiry = {
    props: { uuid: String, canManage: Boolean },
    data: function () { return { e: null, loading: true, note: "" }; },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/crm/enquiries/" + this.uuid).then(function (r) { self.e = r.data; self.loading = false; });
      },
      setStatus: function (status) {
        var self = this;
        this.$api.post("breakfast/crm/enquiries/" + this.uuid + "/status", { status: status }).then(function () { self.load(); self.$panel.notification.success("Status updated"); });
      },
      addNote: function () {
        var self = this;
        if (!this.note.trim()) return;
        this.$api.post("breakfast/crm/enquiries/" + this.uuid + "/note", { note: this.note }).then(function () { self.note = ""; self.load(); });
      },
      convert: function () {
        var self = this;
        this.$api.post("breakfast/crm/enquiries/" + this.uuid + "/convert").then(function () { self.$panel.notification.success("Opportunity created"); self.load(); });
      },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>{{ e ? e.reference : 'Enquiry' }}<template #buttons>
          <k-button icon="angle-left" link="/crm/enquiries">Back</k-button>
        </template></k-header>
        <k-text v-if="loading">Loading…</k-text>
        <template v-else-if="e">
          <k-box theme="info" class="k-mb-medium"><strong>{{ e.summary }}</strong><br/>{{ e.form_type }} · {{ e.status }} · {{ e.spam_status }}</k-box>
          <k-headline>Details</k-headline>
          <k-table :columns="{ k:{label:'Field',width:'1/4'}, v:{label:'Value'} }" :rows="payloadRows" />
          <div v-if="canManage" class="k-mt-medium">
            <k-button-group>
              <k-button icon="check" @click="setStatus('reviewing')">Reviewing</k-button>
              <k-button icon="star" @click="setStatus('qualified')">Qualified</k-button>
              <k-button icon="cart" @click="convert">Create opportunity</k-button>
              <k-button icon="cancel" theme="negative" @click="setStatus('spam')">Spam</k-button>
            </k-button-group>
            <k-textarea-field label="Add a note" v-model="note" class="k-mt-medium" />
            <k-button icon="add" @click="addNote">Save note</k-button>
          </div>
          <k-headline class="k-mt-huge">Activity</k-headline>
          <k-table :columns="{ summary:{label:'Activity'}, actor_type:{label:'By',width:'1/6'}, created_at:{label:'When',type:'date',width:'1/5'} }" :rows="e.activity || []" />
        </template>
      </k-panel-inside>
    `,
    computed: {
      payloadRows: function () {
        if (!this.e || !this.e.payload) return [];
        var p = this.e.payload, out = [];
        Object.keys(p).forEach(function (k) { out.push({ k: k, v: String(p[k]) }); });
        return out;
      },
    },
  };

  var Pipeline = {
    props: { canManage: Boolean },
    data: function () { return { stages: [], rows: [], loading: true }; },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/crm/opportunities").then(function (r) { self.rows = r.data || []; self.stages = r.stages || []; self.loading = false; });
      },
      move: function (opp, stage) {
        var self = this;
        this.$api.post("breakfast/crm/opportunities/" + opp.uuid + "/stage", { stage: stage }).then(function () { self.load(); });
      },
      inStage: function (stage) { return this.rows.filter(function (o) { return o.stage === stage; }); },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>Pipeline</k-header>
        <k-text v-if="loading">Loading…</k-text>
        <div v-else class="k-crm-pipeline">
          <div v-for="stage in stages" :key="stage" class="k-crm-column">
            <h3>{{ stage }} ({{ inStage(stage).length }})</h3>
            <div v-for="o in inStage(stage)" :key="o.uuid" class="k-crm-card">
              <strong>{{ o.title }}</strong><br/>
              <small>{{ o.contact_name }}</small>
              <div v-if="canManage" class="k-mt-small">
                <select @change="move(o, $event.target.value)">
                  <option value="">move…</option>
                  <option v-for="s in stages" :key="s" :value="s">{{ s }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </k-panel-inside>
    `,
  };

  var Tasks = {
    props: { canManage: Boolean },
    data: function () { return { rows: [], loading: true, title: "" }; },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/crm/tasks", { status: "open" }).then(function (r) { self.rows = r.data || []; self.loading = false; });
      },
      create: function () {
        var self = this;
        if (!this.title.trim()) return;
        this.$api.post("breakfast/crm/tasks", { title: this.title }).then(function () { self.title = ""; self.load(); });
      },
      complete: function (t) {
        var self = this;
        this.$api.patch("breakfast/crm/tasks/" + t.uuid, { status: "done" }).then(function () { self.load(); });
      },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>Tasks</k-header>
        <div v-if="canManage" class="k-mb-medium" style="display:flex;gap:.5rem;align-items:end">
          <k-text-field label="New task" v-model="title" style="flex:1" />
          <k-button icon="add" @click="create">Add</k-button>
        </div>
        <k-text v-if="loading">Loading…</k-text>
        <k-empty v-else-if="!rows.length" icon="check">No open tasks.</k-empty>
        <div v-else>
          <div v-for="t in rows" :key="t.uuid" class="k-crm-card">
            <strong>{{ t.title }}</strong>
            <span v-if="t.due_date"> · due {{ t.due_date.substring(0,10) }}</span>
            <k-button v-if="canManage" size="xs" icon="check" @click="complete(t)">Done</k-button>
          </div>
        </div>
      </k-panel-inside>
    `,
  };

  var Contacts = {
    data: function () { return { rows: [], loading: true, q: "" }; },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/crm/contacts", { q: this.q }).then(function (r) {
          self.rows = (r.data || []).map(function (c) { return { name: c.display_name, email: c.email, company: c.company_name, status: c.status }; });
          self.loading = false;
        });
      },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>Contacts</k-header>
        <k-text v-if="loading">Loading…</k-text>
        <k-empty v-else-if="!rows.length" icon="users">No contacts yet.</k-empty>
        <k-table v-else :columns="{ name:{label:'Name'}, email:{label:'Email'}, company:{label:'Company'}, status:{label:'Status',width:'1/6'} }" :rows="rows" />
      </k-panel-inside>
    `,
  };

  var Mail = {
    props: { canSend: Boolean },
    data: function () {
      return { loading: true, status: null, deliveries: [], compose: { to: "", subject: "", body: "" }, notice: "" };
    },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/crm/mail/status").then(function (r) { self.status = r.data; });
        this.$api.get("breakfast/crm/email/deliveries").then(function (r) {
          self.deliveries = (r.data || []).map(function (m) {
            return { subject: m.subject, recipient: m.recipient_email, status: m.status, type: m.message_type, sent_at: m.sent_at };
          });
          self.loading = false;
        });
      },
      send: function () {
        var self = this;
        this.notice = "";
        this.$api.post("breakfast/crm/email/send", this.compose).then(function (r) {
          if (r && r.error) { self.notice = "Rejected: " + r.error; }
          else { self.notice = "Queued."; self.compose = { to: "", subject: "", body: "" }; self.load(); }
        });
      },
    },
    template: `
      <k-panel-inside class="k-crm">
        <k-header>Email delivery</k-header>
        <k-text v-if="loading">Loading…</k-text>
        <template v-else>
          <div class="k-crm-grid" v-if="status">
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ status.provider }}</div><div class="k-crm-stat__label">Provider</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ status.queue_depth }}</div><div class="k-crm-stat__label">Queue depth</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ status.recent_failures }}</div><div class="k-crm-stat__label">Delivery failures</div></div>
            <div class="k-crm-stat"><div class="k-crm-stat__value">{{ status.suppressions.transactional }}</div><div class="k-crm-stat__label">Suppressed (txn)</div></div>
          </div>
          <k-box v-if="status && !status.api_configured && status.brevo_enabled" theme="notice" class="k-mt-medium">Brevo enabled but API key missing.</k-box>

          <div v-if="canSend" class="k-mt-medium">
            <k-headline>Compose a one-to-one email</k-headline>
            <k-text-field label="To" v-model="compose.to" />
            <k-text-field label="Subject" v-model="compose.subject" class="k-mt-small" />
            <k-textarea-field label="Message" v-model="compose.body" class="k-mt-small" />
            <k-button icon="email" @click="send" class="k-mt-small">Queue email</k-button>
            <k-text v-if="notice" class="k-mt-small"><strong>{{ notice }}</strong></k-text>
          </div>

          <k-headline class="k-mt-huge">Recent deliveries</k-headline>
          <k-empty v-if="!deliveries.length" icon="email">No email yet.</k-empty>
          <k-table v-else :columns="{ subject:{label:'Subject'}, recipient:{label:'To'}, type:{label:'Type',width:'1/6'}, status:{label:'Status',width:'1/6'} }" :rows="deliveries" />
        </template>
      </k-panel-inside>
    `,
  };

  // -- Breakfast Admin: branded dashboard (the home screen) ---------------
  var BreakfastDashboard = {
    props: { greetingName: String },
    data: function () {
      return { loading: true, error: null, d: null };
    },
    computed: {
      greeting: function () {
        var h = new Date().getHours();
        var part = h < 12 ? "Good morning" : h < 18 ? "Good afternoon" : "Good evening";
        return part + ", " + (this.greetingName || "there");
      },
    },
    created: function () {
      var self = this;
      this.$api
        .get("breakfast/admin/overview")
        .then(function (r) { self.d = r.data || r; self.loading = false; })
        .catch(function (e) { self.error = e.message || "Failed to load"; self.loading = false; });
    },
    methods: {
      go: function (view) { this.$go(view); },
      money: money,
    },
    template: `
      <k-panel-inside class="k-breakfast">
        <k-header class="k-breakfast-header">{{ greeting }}
          <template #buttons>
            <k-button-group>
              <k-button icon="email" link="crm/enquiries" variant="filled" theme="notice">Leads</k-button>
              <k-button icon="preview" link="previews" variant="filled">Previews</k-button>
              <k-button icon="page" link="site" variant="filled">Website</k-button>
            </k-button-group>
          </template>
        </k-header>

        <k-text v-if="error" theme="negative">{{ error }}</k-text>
        <k-loader v-else-if="loading" />

        <template v-else-if="d">
          <k-grid v-if="d.metrics" style="gap:1rem" class="k-breakfast-metrics">
            <k-column width="1/4"><div class="k-breakfast-stat" @click="go('crm/enquiries')"><div class="k-breakfast-stat__value">{{ d.metrics.new_leads }}</div><div class="k-breakfast-stat__label">New leads</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat" @click="go('crm/pipeline')"><div class="k-breakfast-stat__value">{{ d.metrics.open_opportunities }}</div><div class="k-breakfast-stat__label">Open opportunities</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat" @click="go('crm/pipeline')"><div class="k-breakfast-stat__value">{{ money(d.metrics.pipeline_value) }}</div><div class="k-breakfast-stat__label">Pipeline value</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat" @click="go('crm/tasks')"><div class="k-breakfast-stat__value">{{ d.metrics.overdue_tasks }}</div><div class="k-breakfast-stat__label">Overdue tasks</div></div></k-column>
          </k-grid>

          <k-grid style="gap:1.5rem" class="k-mt-large">
            <k-column width="1/2" v-if="d.lead_inbox">
              <k-headline>Lead inbox</k-headline>
              <k-empty v-if="!d.lead_inbox.length" icon="email">No new enquiries.</k-empty>
              <k-list v-else>
                <k-list-item v-for="e in d.lead_inbox" :key="e.uuid" :text="e.summary || e.name || 'Enquiry'" :info="e.email" icon="email" :link="'crm/enquiries/' + e.uuid" />
              </k-list>
            </k-column>

            <k-column width="1/2" v-if="d.pipeline">
              <k-headline>Pipeline</k-headline>
              <div class="k-breakfast-pipeline">
                <div v-for="stage in d.pipeline.stages" :key="stage" class="k-breakfast-pill">
                  <span>{{ stage }}</span>
                  <strong>{{ (d.pipeline.by_stage[stage] && d.pipeline.by_stage[stage].count) || 0 }}</strong>
                </div>
              </div>
            </k-column>
          </k-grid>

          <k-grid style="gap:1.5rem" class="k-mt-large">
            <k-column width="1/2" v-if="d.tasks">
              <k-headline>Needs doing</k-headline>
              <k-empty v-if="!d.tasks.overdue.length && !d.tasks.upcoming.length" icon="check">Nothing outstanding.</k-empty>
              <k-list v-else>
                <k-list-item v-for="t in d.tasks.overdue" :key="'o'+t.uuid" :text="t.title" info="Overdue" icon="alert" theme="negative" link="crm/tasks" />
                <k-list-item v-for="t in d.tasks.upcoming" :key="'u'+t.uuid" :text="t.title" :info="t.due_date || 'No date'" icon="clock" link="crm/tasks" />
              </k-list>
            </k-column>

            <k-column width="1/2" v-if="d.previews">
              <k-headline>Client previews</k-headline>
              <div class="k-breakfast-pipeline">
                <div class="k-breakfast-pill"><span>Active</span><strong>{{ d.previews.active }}</strong></div>
                <div class="k-breakfast-pill"><span>Draft</span><strong>{{ d.previews.draft }}</strong></div>
                <div class="k-breakfast-pill"><span>Expired</span><strong>{{ d.previews.expired }}</strong></div>
              </div>
              <k-button icon="preview" link="previews" class="k-mt-small">Open Client Previews</k-button>
            </k-column>
          </k-grid>

          <template v-if="d.website">
            <k-headline class="k-mt-large">Recent website changes</k-headline>
            <k-list>
              <k-list-item v-for="p in d.website" :key="p.url" :text="p.title" :info="p.status + ' · ' + p.modified" icon="page" :link="p.url" />
            </k-list>
          </template>

          <template v-if="d.system">
            <k-headline class="k-mt-large">System health</k-headline>
            <k-grid style="gap:1rem">
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.system.queue_depth }}</div><div class="k-breakfast-stat__label">Queue depth</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.system.failed_jobs }}</div><div class="k-breakfast-stat__label">Failed jobs</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.system.mail_provider }}</div><div class="k-breakfast-stat__label">Mail provider</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.system.version }}</div><div class="k-breakfast-stat__label">Version</div></div></k-column>
            </k-grid>
          </template>
        </template>
      </k-panel-inside>
    `,
  };

  // -- Breakfast Admin: operations (integrations / mail / hermes / queue) --
  var BreakfastOps = {
    props: { section: String, title: String },
    data: function () {
      return { loading: true, error: null, d: null, rows: [] };
    },
    created: function () {
      this.load();
    },
    methods: {
      load: function () {
        var self = this;
        this.loading = true;
        if (this.section === "audit") {
          this.$api.get("breakfast/admin/audit").then(function (r) { self.rows = r.data || []; self.loading = false; }).catch(function (e) { self.error = e.message; self.loading = false; });
        } else if (this.section === "reports") {
          this.$api.get("breakfast/admin/reports").then(function (r) { self.d = r.data || r; self.loading = false; }).catch(function (e) { self.error = e.message; self.loading = false; });
        } else {
          this.$api.get("breakfast/admin/ops").then(function (r) { self.d = r.data || r; self.loading = false; }).catch(function (e) { self.error = e.message; self.loading = false; });
        }
      },
      retry: function (uuid) {
        var self = this;
        this.$api.post("breakfast/crm/jobs/" + uuid + "/retry").then(function () { self.load(); });
      },
      money: money,
    },
    template: `
      <k-panel-inside class="k-breakfast">
        <k-header>{{ title }}</k-header>
        <k-text v-if="error" theme="negative">{{ error }}</k-text>
        <k-loader v-else-if="loading" />
        <template v-else-if="d">
          <template v-if="section === 'reports'">
            <k-grid style="gap:1rem">
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.total_enquiries }}</div><div class="k-breakfast-stat__label">Enquiries</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.open_opportunities }}</div><div class="k-breakfast-stat__label">Open opportunities</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ money(d.pipeline_value) }}</div><div class="k-breakfast-stat__label">Pipeline value</div></div></k-column>
              <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.contacts }}</div><div class="k-breakfast-stat__label">Contacts</div></div></k-column>
            </k-grid>
          </template>
          <template v-else>
            <k-headline>Email &amp; Brevo</k-headline>
            <k-stats :reports="[
              { label: 'Provider', value: d.mail.provider },
              { label: 'Brevo', value: d.mail.brevo_enabled ? 'enabled' : 'off' },
              { label: 'Recent failures', value: d.mail.recent_failures },
              { label: 'Suppressed', value: d.mail.suppressions.transactional }
            ]" />
            <k-headline class="k-mt-large">Hermes</k-headline>
            <k-stats :reports="[
              { label: 'Status', value: d.hermes.enabled ? 'enabled' : 'disabled' },
              { label: 'Credentials', value: d.hermes.credentials },
              { label: 'Replay window', value: d.hermes.replayWindow + 's' }
            ]" />
            <k-headline class="k-mt-large">Queue</k-headline>
            <k-stats :reports="[
              { label: 'Depth', value: d.queue.depth },
              { label: 'Failed', value: d.queue.failed }
            ]" />
            <k-empty v-if="!d.queue.jobs.length" icon="check" class="k-mt-small">No failed jobs.</k-empty>
            <k-list v-else class="k-mt-small">
              <k-list-item v-for="j in d.queue.jobs" :key="j.uuid" :text="j.type" :info="j.last_error || 'failed'" icon="alert" theme="negative">
                <template #options><k-button icon="refresh" @click="retry(j.uuid)">Retry</k-button></template>
              </k-list-item>
            </k-list>
          </template>
        </template>
        <template v-else-if="rows">
          <k-empty v-if="!rows.length" icon="protected">No audit entries.</k-empty>
          <k-table v-else :columns="{ created_at:{label:'When',width:'1/5'}, actor:{label:'Actor',width:'1/5'}, action:{label:'Action'}, detail:{label:'Detail'} }" :rows="rows" />
        </template>
      </k-panel-inside>
    `,
  };

  // -- Breakfast Admin: Client Previews list ------------------------------
  var BreakfastPreviews = {
    props: { canCreate: Boolean },
    data: function () {
      return { loading: true, error: null, rows: [], creating: false, form: { name: "", slug: "" }, notice: null };
    },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.loading = true;
        this.$api.get("breakfast/previews").then(function (r) { self.rows = r.data || []; self.loading = false; }).catch(function (e) { self.error = e.message; self.loading = false; });
      },
      create: function () {
        var self = this;
        this.notice = null;
        this.$api.post("breakfast/previews", this.form).then(function (r) {
          if (r.status === "error") { self.notice = "Could not create: " + r.error; return; }
          self.creating = false; self.form = { name: "", slug: "" };
          self.$go("previews/" + r.data.uuid);
        }).catch(function (e) { self.notice = e.message; });
      },
    },
    template: `
      <k-panel-inside class="k-breakfast">
        <k-header>Client Previews
          <template #buttons>
            <k-button v-if="canCreate" icon="add" variant="filled" theme="notice" @click="creating = !creating">New preview</k-button>
          </template>
        </k-header>
        <k-box v-if="creating" theme="none" class="k-mb-medium" style="border:2px solid #1c293c;border-radius:8px;padding:1rem">
          <k-text-field label="Internal name" v-model="form.name" />
          <k-text-field label="Public slug (lowercase, a-z0-9-)" v-model="form.slug" class="k-mt-small" />
          <k-text v-if="notice" theme="negative" class="k-mt-small">{{ notice }}</k-text>
          <k-button icon="check" variant="filled" @click="create" class="k-mt-small">Create draft</k-button>
        </k-box>
        <k-text v-if="error" theme="negative">{{ error }}</k-text>
        <k-loader v-else-if="loading" />
        <template v-else>
          <k-empty v-if="!rows.length" icon="preview">No previews yet.</k-empty>
          <k-table v-else
            :columns="{ name:{label:'Name'}, public_slug:{label:'Slug'}, status:{label:'Status',width:'1/6'}, visibility:{label:'Access',width:'1/6'} }"
            :rows="rows"
            @cell="$go('previews/' + $event.row.uuid)" />
        </template>
      </k-panel-inside>
    `,
  };

  // -- Breakfast Admin: Client Preview detail -----------------------------
  var BreakfastPreview = {
    props: { uuid: String, can: Object },
    data: function () {
      return { loading: true, error: null, d: null, files: [], viewer: null, notice: null, pw: "", newSlug: "" };
    },
    created: function () { this.load(); },
    methods: {
      load: function () {
        var self = this;
        this.$api.get("breakfast/previews/" + this.uuid).then(function (r) {
          if (r.status === "error") { self.error = r.error; self.loading = false; return; }
          self.d = r.data; self.newSlug = r.data.public_slug; self.loading = false; self.loadFiles();
        }).catch(function (e) { self.error = e.message; self.loading = false; });
      },
      loadFiles: function () {
        var self = this;
        if (!this.d || !this.d.current_version_uuid) { this.files = []; return; }
        this.$api.get("breakfast/previews/" + this.uuid + "/files").then(function (r) { self.files = (r.data && r.data.files) || []; });
      },
      act: function (path, body) {
        var self = this; this.notice = null;
        return this.$api.post("breakfast/previews/" + this.uuid + path, body || {}).then(function (r) {
          if (r.status === "error") { self.notice = r.error; return null; }
          self.load(); return r;
        }).catch(function (e) { self.notice = e.message; return null; });
      },
      upload: function (ev) {
        var self = this;
        var file = ev.target.files && ev.target.files[0];
        if (!file) return;
        var fd = new FormData(); fd.append("file", file);
        this.notice = "Uploading and validating…";
        fetch("/api/breakfast/previews/" + this.uuid + "/upload", {
          method: "POST", credentials: "same-origin",
          headers: { "x-csrf": (window.panel && window.panel.system && window.panel.system.csrf) || "" },
          body: fd,
        }).then(function (res) { return res.json(); }).then(function (r) {
          var data = r.data || r;
          if (data && data.ok === false) {
            self.notice = "Validation failed: " + ((data.report && data.report.errors) || []).join(", ");
          } else { self.notice = "Uploaded. Review and publish the new version."; }
          self.load();
        }).catch(function (e) { self.notice = "Upload failed: " + e.message; });
      },
      publish: function (v) { this.act("/publish", { version_uuid: v }); },
      rollback: function (v) { this.act("/rollback", { version_uuid: v }); },
      setPassword: function () { this.act("/password", { password: this.pw }).then(function () {}); this.pw = ""; },
      clearPassword: function () { this.act("/password", { clear: "true" }); },
      setVisibility: function (val) { this.act("/visibility", { visibility: val }); },
      changeSlug: function () { this.act("/slug", { slug: this.newSlug }); },
      setExpiry: function (val) { this.act("/expiry", { expires_at: val }); },
      disable: function (enable) { this.act("/disable", { enable: enable ? "true" : "false" }); },
      archive: function () { this.act("/archive", {}); },
      remove: function () { var self = this; this.$api.delete("breakfast/previews/" + this.uuid).then(function () { self.$go("previews"); }); },
      viewFile: function (path) {
        var self = this;
        this.$api.get("breakfast/previews/" + this.uuid + "/files", { path: path }).then(function (r) { self.viewer = r.data; });
      },
      emailDraft: function () {
        var self = this;
        this.$api.post("breakfast/previews/" + this.uuid + "/email-draft", {}).then(function (r) {
          if (r.status === "error") { self.notice = "Draft: " + r.error; return; }
          // Hand the prepared draft to the CRM email composer for human review + send.
          self.$go("crm/mail");
          self.notice = "Draft prepared for " + r.data.to + ". Review and send it from Email.";
        }).catch(function (e) { self.notice = e.message; });
      },
      copyUrl: function () { if (this.d) { navigator.clipboard && navigator.clipboard.writeText(this.d.public_url); this.notice = "URL copied."; } },
    },
    template: `
      <k-panel-inside class="k-breakfast">
        <k-text v-if="error" theme="negative">{{ error }}</k-text>
        <k-loader v-else-if="loading" />
        <template v-else-if="d">
          <k-header>{{ d.name }}
            <template #buttons>
              <k-button-group>
                <k-button icon="url" @click="copyUrl">Copy URL</k-button>
                <k-button icon="open" :link="d.public_url" target="_blank">Open</k-button>
                <k-button v-if="can.emailDraft" icon="email" @click="emailDraft">Email draft</k-button>
              </k-button-group>
            </template>
          </k-header>
          <k-text v-if="notice"><strong>{{ notice }}</strong></k-text>
          <k-box v-if="!d.isolated" theme="warning" class="k-mb-small">Served without a separate preview origin — set CLIENT_PREVIEW_BASE_URL in production for full isolation.</k-box>

          <k-grid style="gap:1rem">
            <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.status }}</div><div class="k-breakfast-stat__label">Status</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ d.visibility }}</div><div class="k-breakfast-stat__label">Access</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ (d.analytics && d.analytics.views) || 0 }}</div><div class="k-breakfast-stat__label">Views</div></div></k-column>
            <k-column width="1/4"><div class="k-breakfast-stat"><div class="k-breakfast-stat__value">{{ (d.analytics && d.analytics.approx_visitors) || 0 }}</div><div class="k-breakfast-stat__label">Approx. visitors</div></div></k-column>
          </k-grid>

          <k-headline class="k-mt-large">Upload &amp; versions</k-headline>
          <p v-if="can.upload"><input type="file" accept=".zip" @change="upload"></p>
          <k-empty v-if="!d.versions.length" icon="upload">No versions yet — upload a ZIP.</k-empty>
          <k-list v-else>
            <k-list-item v-for="v in d.versions" :key="v.uuid"
              :text="'v' + v.version_number + ' · ' + v.state + ' · ' + v.file_count + ' files'"
              :info="v.entry_file"
              :icon="v.uuid === d.current_version_uuid ? 'check' : 'circle-outline'">
              <template #options>
                <k-button v-if="can.publish && v.state === 'ready'" icon="upload" @click="publish(v.uuid)">Publish</k-button>
                <k-button v-if="can.rollback && v.uuid !== d.current_version_uuid && v.state !== 'ready'" icon="undo" @click="rollback(v.uuid)">Roll back</k-button>
              </template>
            </k-list-item>
          </k-list>

          <k-grid style="gap:1.5rem" class="k-mt-large">
            <k-column width="1/2">
              <k-headline>Access &amp; visibility</k-headline>
              <k-button-group class="k-mb-small">
                <k-button icon="preview" @click="setVisibility('unlisted')">Unlisted</k-button>
                <k-button v-if="can.publish" icon="live" @click="setVisibility('public')">Public</k-button>
              </k-button-group>
              <div v-if="can.managePassword">
                <k-text-field label="Set password (min 6 chars)" type="password" v-model="pw" />
                <k-button-group class="k-mt-small">
                  <k-button icon="lock" @click="setPassword">Set password</k-button>
                  <k-button icon="unlock" @click="clearPassword">Clear</k-button>
                </k-button-group>
                <k-text class="k-mt-small" v-if="d.password_protected"><em>Password protected. Send the password to the client separately — never in the invitation email.</em></k-text>
              </div>
            </k-column>
            <k-column width="1/2">
              <k-headline>Lifecycle</k-headline>
              <k-text-field v-if="can.changeSlug" label="Slug" v-model="newSlug" />
              <k-button v-if="can.changeSlug" icon="edit" class="k-mt-small" @click="changeSlug">Change slug</k-button>
              <k-button-group class="k-mt-medium">
                <k-button v-if="can.disable && d.status === 'active'" icon="toggle-off" @click="disable(false)">Disable</k-button>
                <k-button v-if="can.disable && d.status === 'disabled'" icon="toggle-on" @click="disable(true)">Enable</k-button>
                <k-button v-if="can.archive" icon="archive" @click="archive">Archive</k-button>
                <k-button v-if="can.delete" icon="trash" theme="negative" @click="remove">Delete</k-button>
              </k-button-group>
            </k-column>
          </k-grid>

          <k-headline class="k-mt-large">Files (read-only)</k-headline>
          <k-empty v-if="!files.length" icon="folder">No files.</k-empty>
          <k-list v-else>
            <k-list-item v-for="f in files" :key="f.path" :text="f.path" :info="f.mime + ' · ' + f.bytes + ' bytes'" icon="file" @click="viewFile(f.path)" />
          </k-list>
          <k-box v-if="viewer" theme="none" class="k-mt-small" style="border:2px solid #1c293c;border-radius:8px;padding:1rem">
            <k-headline>{{ viewer.path }}</k-headline>
            <k-text v-if="viewer.kind === 'binary'"><em>Binary/image — open it on the preview origin.</em></k-text>
            <pre v-else style="white-space:pre-wrap;overflow:auto;max-height:20rem;font-family:JetBrains Mono,monospace;font-size:.8rem">{{ viewer.content }}</pre>
            <k-text v-if="viewer.truncated" theme="info">Truncated for display.</k-text>
          </k-box>
        </template>
      </k-panel-inside>
    `,
  };

  window.panel.plugin("breakfast/platform", {
    components: {
      "k-breakfast-dashboard": BreakfastDashboard,
      "k-breakfast-ops": BreakfastOps,
      "k-breakfast-previews": BreakfastPreviews,
      "k-breakfast-preview": BreakfastPreview,
      "k-crm-dashboard": Dashboard,
      "k-crm-enquiries": Enquiries,
      "k-crm-enquiry": Enquiry,
      "k-crm-contacts": Contacts,
      "k-crm-pipeline": Pipeline,
      "k-crm-tasks": Tasks,
      "k-crm-mail": Mail,
    },
  });
})();
