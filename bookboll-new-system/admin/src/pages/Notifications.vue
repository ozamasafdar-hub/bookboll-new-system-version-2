<script setup>
/**
 * Notifications.vue — automated Email / SMS / WhatsApp templates for booking and
 * membership lifecycle events (the Bookboll take on SimplyBook's "Email & SMS settings").
 *
 * Dual-mode like Banners/Tracking:
 *  - Super admin (platform): edits the MARKETPLACE DEFAULT templates every business inherits.
 *  - Provider: edits their own copy (defaults pre-filled; saving stores their version).
 *
 * Matrix: one row per event × two audience groups (customer / your team) × three channel
 * toggles. Clicking a row expands the editor: audience + channel tabs, subject/body,
 * click-to-insert [token] chips and a live preview rendered with sample data.
 */
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import FeatureSearch from '../components/FeatureSearch.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import { useProviderSmtp } from '../lib/emailSender.js'
import {
  NOTIFICATION_EVENTS, CHANNELS, AUDIENCES, TEMPLATE_VARS,
  builtinSettings, renderPreview, smsSegments,
  loadPlatformNotificationSettings, loadProviderNotificationSettings,
  savePlatformNotificationSettings, saveProviderNotificationSettings,
} from '../lib/notificationTemplates.js'
import {
  Bell, Mail, MessageSquare, MessageCircle, ChevronDown, ChevronRight, Eye, Loader,
  Copy, Check, Hash, Info, CalendarCheck, CalendarClock, CalendarX, Clock, Receipt,
  GraduationCap, Hourglass, Repeat, UserX, Star, RotateCcw, MailCheck, CreditCard, HeartHandshake,
} from 'lucide-vue-next'

const { isSuperAdmin, isCountryAdmin, role, currentOwnerUserId } = useCurrentUser()
const isPlatformMode = computed(() =>
  (isSuperAdmin.value || role.value === 'platform_staff' || isCountryAdmin.value) && !currentOwnerUserId.value)

const router = useRouter()
// Which address automated emails go out from (optional own SMTP vs Bookboll's shared sender).
const { smtp, configured: smtpConfigured, automated: smtpAutomated, load: loadSmtp } = useProviderSmtp()

const EVENT_ICONS = { CalendarCheck, Clock, CalendarClock, CalendarX, Receipt, GraduationCap, Hourglass, Repeat, UserX, Star, CreditCard, HeartHandshake }
const CHANNEL_ICONS = { email: Mail, sms: MessageSquare, whatsapp: MessageCircle }

const settings = ref(builtinSettings())
const loading  = ref(true)
const saving   = ref(false)
const search = ref('')
const eventGroup = ref('all')
const visibleEvents = computed(() => NOTIFICATION_EVENTS.filter(event => {
  const membership = event.key.startsWith('membership_') || event.key === 'trial_ending'
  return (eventGroup.value === 'all' || (eventGroup.value === 'memberships' ? membership : !membership)) &&
    (event.label + ' ' + event.desc).toLowerCase().includes(search.value.trim().toLowerCase())
}))

async function load() {
  loading.value = true
  if (isPlatformMode.value) {
    settings.value = await loadPlatformNotificationSettings()
  } else {
    const { settings: s } = await loadProviderNotificationSettings(currentOwnerUserId.value)
    settings.value = s
  }
  await nextTick()          // let the deep watcher flush while still "loading"
  loading.value = false
  clearDirty()
}
onMounted(() => { load(); if (!isPlatformMode.value) loadSmtp() })
watch(currentOwnerUserId, load)

// Dirty tracking (any edit after load)
watch(settings, () => { if (!loading.value) markDirty() }, { deep: true })

const tpl = (evKey, aud, ch) => settings.value.events?.[evKey]?.[aud]?.[ch] || null

// ── Expandable editor ─────────────────────────────────────────────────────────
const openEvent  = ref('')          // event key currently expanded ('' = none)
const editorAud  = ref('client')
const editorCh   = ref('email')
function toggleEditor(ev) {
  if (openEvent.value === ev.key) { openEvent.value = ''; return }
  openEvent.value = ev.key
  editorAud.value = ev.audiences.includes(editorAud.value) ? editorAud.value : ev.audiences[0]
  editorCh.value  = 'email'
}
const openEventDef = computed(() => NOTIFICATION_EVENTS.find(e => e.key === openEvent.value) || null)
const activeTpl = computed(() => openEvent.value ? tpl(openEvent.value, editorAud.value, editorCh.value) : null)

// Click a var chip → insert at the cursor of the last-focused textarea/input.
let lastField = null
function trackFocus(e) { lastField = e.target }
function insertVar(code) {
  const el = lastField
  const t = activeTpl.value
  if (!t) return
  if (el && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') && document.body.contains(el)) {
    const start = el.selectionStart ?? el.value.length
    const end   = el.selectionEnd ?? el.value.length
    const next = el.value.slice(0, start) + code + el.value.slice(end)
    // Write through the model (subject vs body by data attribute)
    if (el.dataset.field === 'subject') t.subject = next
    else t.body = next
    requestAnimationFrame(() => { el.focus(); el.selectionStart = el.selectionEnd = start + code.length })
  } else {
    t.body = (t.body || '') + code
  }
}

// Live preview of the active template with sample data.
const showPreview = ref(true)
const previewSubject = computed(() => renderPreview(activeTpl.value?.subject || ''))
const previewBody    = computed(() => renderPreview(activeTpl.value?.body || ''))
const previewSegs    = computed(() => smsSegments(activeTpl.value?.body || ''))

// Reset the ACTIVE template back to the built-in default wording.
function resetActive() {
  const d = builtinSettings().events?.[openEvent.value]?.[editorAud.value]?.[editorCh.value]
  const t = activeTpl.value
  if (!d || !t) return
  t.subject = d.subject || ''
  t.body = d.body || ''
  pushToast('Template reset to the default wording', 'success')
}

// ── Template-vars reference: click to copy ────────────────────────────────────
const copiedVar = ref('')
async function copyVar(code) {
  try { await navigator.clipboard.writeText(code) } catch { /* clipboard blocked */ }
  copiedVar.value = code
  setTimeout(() => { if (copiedVar.value === code) copiedVar.value = '' }, 1200)
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function save() {
  saving.value = true
  const { error } = isPlatformMode.value
    ? await savePlatformNotificationSettings(settings.value)
    : await saveProviderNotificationSettings(currentOwnerUserId.value, settings.value)
  saving.value = false
  if (error) {
    pushToast(error.message || 'Could not save.', 'error')
    return
  }
  clearDirty()
  pushToast(isPlatformMode.value ? 'Marketplace default templates saved.' : 'Notification templates saved.', 'success')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><Bell :size="22" style="vertical-align:-3px" /> Notifications</h1>
        <p class="page-sub">
          {{ isPlatformMode
            ? 'Marketplace default templates — every business starts with these until they customise their own.'
            : 'The automatic messages your customers and team receive — customise the wording per channel.' }}
        </p>
      </div>
      <button class="btn btn-primary" :disabled="saving" @click="save">
        <Loader v-if="saving" :size="15" class="nt-spin" /> {{ saving ? 'Saving…' : 'Save templates' }}
      </button>
    </div>

    <FeatureSearch />

    <!-- Channel setup and availability -->
    <div class="nt-channels">
      <span class="nt-chan"><Mail :size="14" /> Email — <b>included</b></span>
      <span class="nt-chan dim"><MessageSquare :size="14" /> SMS — <b>delivery coming soon</b></span>
      <span class="nt-chan"><MessageCircle :size="14" /> WhatsApp — <b>requires setup</b></span>
    </div>

    <!-- Sending address for automated emails (optional own SMTP) -->
    <div v-if="!isPlatformMode" class="nt-sender" role="link" tabindex="0" @keydown.enter="router.push('/email-sender')" :class="{ own: smtpAutomated }" @click="router.push('/email-sender')">
      <MailCheck :size="16" class="nt-sender-ic" />
      <div class="nt-sender-txt">
        <template v-if="smtpAutomated">
          Automated emails are sent from <b>{{ smtp.from_email }}</b> — your own address.
        </template>
        <template v-else-if="smtpConfigured">
          Your email sender is connected, but automated emails still use <b>Bookboll's shared address</b>. Turn on “use for automated” to switch.
        </template>
        <template v-else>
          Automated emails are sent from <b>Bookboll's shared address</b>. Connect your own email so they come from you.
        </template>
      </div>
      <span class="nt-sender-cta">{{ smtpAutomated ? 'Manage' : 'Set up' }} <ChevronRight :size="14" /></span>
    </div>

    <div class="nt-models">
      <section class="nt-model-card"><span class="nt-model-tag">Marketplace retention</span><h2>Comeback offer</h2><p>A personal code after the customer's first completed marketplace booking at each venue. Valid for 7 days at the same venue.</p><p>Email and WhatsApp follow customer consent. The discount is capped at Bookboll's fee, protecting the provider's share.</p><router-link v-if="isSuperAdmin || isCountryAdmin" class="btn btn-primary" to="/countries?section=comeback">Manage comeback offer</router-link><p v-else class="nt-help">Managed by Bookboll under Countries &amp; cities → Comeback offer.</p><small>Global default → country override → city override</small></section>
      <section class="nt-model-card"><span class="nt-model-tag">Manual membership management</span><h2>Members contact the provider</h2><p>Providers add members, record offline payments, manage attendance and cancellations in Members. Customer memberships have no automatic charges, trial conversions or payment retries.</p><p>Automated member messages are not sent by the manual member register. Contact members directly using their saved details.</p><router-link class="btn btn-secondary" to="/subscriptions">Manage members</router-link><small>The monthly marketplace listing fee is paid by the business, separately from its members.</small></section>
    </div>
    <div class="nt-tools">
      <label class="nt-search">Find a notification<input v-model="search" type="search" placeholder="Search confirmations, reminders, payments…" /></label>
      <label>Show<select v-model="eventGroup"><option value="all">All notifications</option><option value="bookings">Bookings &amp; payments</option></select></label>
    </div>
    <p class="nt-help">Choose a notification to edit its message. Use the switches to choose who receives it.</p>
    <!-- ── Event × audience × channel matrix ── -->
    <div class="card">
      <div class="table-scroll">
        <table class="nt-table">
          <thead>
            <tr>
              <th class="nt-th-event">Notification</th>
              <th v-for="aud in AUDIENCES" :key="aud.key" class="nt-th-group" colspan="3">{{ aud.label }}</th>
            </tr>
            <tr class="nt-subhead">
              <th></th>
              <template v-for="aud in AUDIENCES" :key="aud.key + '-ch'">
                <th v-for="ch in CHANNELS" :key="aud.key + ch.key" class="nt-th-ch">
                  <component :is="CHANNEL_ICONS[ch.key]" :size="13" /> {{ ch.label }}
                </th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!visibleEvents.length"><td colspan="7" class="nt-no-results">No notifications match your search.</td></tr>
            <template v-for="ev in visibleEvents" :key="ev.key">
              <tr class="nt-row" :class="{ open: openEvent === ev.key }" @click="toggleEditor(ev)">
                <td class="nt-ev">
                  <component :is="openEvent === ev.key ? ChevronDown : ChevronRight" :size="15" class="nt-caret" />
                  <component :is="EVENT_ICONS[ev.icon] || Bell" :size="15" class="nt-ev-ic" />
                  <div class="nt-ev-txt">
                    <button type="button" class="nt-event-button" :aria-expanded="openEvent === ev.key" @click.stop="toggleEditor(ev)">{{ ev.label }}</button>
                    <small>{{ ev.desc }}</small>
                  </div>
                </td>
                <template v-for="aud in AUDIENCES" :key="ev.key + aud.key">
                  <td v-for="ch in CHANNELS" :key="ev.key + aud.key + ch.key" class="nt-cell" @click.stop>
                    <span class="nt-mobile-channel" aria-hidden="true">{{ aud.key === 'client' ? 'Customer' : 'Team' }}<b>{{ ch.label }}</b></span>
                    <label v-if="tpl(ev.key, aud.key, ch.key)" class="nt-switch" :title="`${ch.label} ${aud.key === 'client' ? 'to the customer' : 'to your team'}`">
                      <input type="checkbox" v-model="tpl(ev.key, aud.key, ch.key).on" :aria-label="`${ev.label}: ${ch.label} ${aud.key === 'client' ? 'to the customer' : 'to your team'}`" />
                      <span class="nt-slider"></span>
                    </label>
                    <span v-else class="nt-na">—</span>
                  </td>
                </template>
              </tr>

              <!-- Inline reminder lead-time controls -->
              <tr v-if="ev.remind && openEvent !== ev.key" class="nt-lead-row">
                <td colspan="7" class="nt-lead">
                  <Clock :size="13" /> Send
                  <select v-model.number="settings.remind_before_days" class="nt-lead-sel" @click.stop>
                    <option :value="0.125">3 hours</option>
                    <option :value="0.5">12 hours</option>
                    <option :value="1">1 day</option>
                    <option :value="2">2 days</option>
                    <option :value="3">3 days</option>
                  </select>
                  before the session
                </td>
              </tr>
              <tr v-if="ev.remindTrial && openEvent !== ev.key" class="nt-lead-row">
                <td colspan="7" class="nt-lead">
                  <Hourglass :size="13" /> Send
                  <select v-model.number="settings.trial_ending_days" class="nt-lead-sel" @click.stop>
                    <option :value="1">1 day</option>
                    <option :value="2">2 days</option>
                    <option :value="3">3 days</option>
                    <option :value="5">5 days</option>
                    <option :value="7">7 days</option>
                  </select>
                  before the trial ends
                </td>
              </tr>
              <tr v-if="ev.remindDunning && openEvent !== ev.key" class="nt-lead-row">
                <td colspan="7" class="nt-lead">
                  <CreditCard :size="13" /> Give members
                  <select v-model.number="settings.dunning_retry_days" class="nt-lead-sel" @click.stop>
                    <option :value="1">1 day</option>
                    <option :value="2">2 days</option>
                    <option :value="3">3 days</option>
                    <option :value="5">5 days</option>
                    <option :value="7">7 days</option>
                  </select>
                  to update their card
                </td>
              </tr>
              <tr v-if="ev.remindRenewal && openEvent !== ev.key" class="nt-lead-row">
                <td colspan="7" class="nt-lead">
                  <Hourglass :size="13" /> Send
                  <select v-model.number="settings.renewal_reminder_days" class="nt-lead-sel" @click.stop>
                    <option :value="1">1 day</option>
                    <option :value="2">2 days</option>
                    <option :value="3">3 days</option>
                    <option :value="5">5 days</option>
                    <option :value="7">7 days</option>
                  </select>
                  before the charge
                </td>
              </tr>

              <!-- ── Expanded editor ── -->
              <tr v-if="openEvent === ev.key" class="nt-editor-row">
                <td colspan="7">
                  <div class="nt-editor">
                    <div class="nt-ed-tabs">
                      <div class="nt-ed-aud">
                        <button
                          v-for="aud in AUDIENCES.filter(a => ev.audiences.includes(a.key))" :key="aud.key"
                          class="nt-tab" :class="{ active: editorAud === aud.key }" @click="editorAud = aud.key"
                        >{{ aud.label }}</button>
                      </div>
                      <div class="nt-ed-ch">
                        <button
                          v-for="ch in CHANNELS" :key="ch.key"
                          class="nt-tab nt-tab-ch" :class="{ active: editorCh === ch.key, off: !tpl(ev.key, editorAud, ch.key)?.on }"
                          @click="editorCh = ch.key"
                        >
                          <component :is="CHANNEL_ICONS[ch.key]" :size="13" /> {{ ch.label }}
                          <em v-if="!tpl(ev.key, editorAud, ch.key)?.on">off</em>
                        </button>
                      </div>
                    </div>

                    <div v-if="activeTpl" class="nt-ed-grid">
                      <div class="nt-ed-form">
                        <label v-if="editorCh === 'email'" class="nt-field">
                          <span>Subject</span>
                          <input class="nt-input" data-field="subject" v-model="activeTpl.subject" @focus="trackFocus" placeholder="Email subject…" />
                        </label>
                        <label class="nt-field">
                          <span>{{ editorCh === 'email' ? 'Message' : editorCh === 'sms' ? 'SMS text' : 'WhatsApp text' }}</span>
                          <textarea
                            class="nt-input nt-body" data-field="body" v-model="activeTpl.body" @focus="trackFocus"
                            :rows="editorCh === 'email' ? 10 : 4"
                            :placeholder="editorCh === 'email' ? 'Write the email…' : 'Short message text…'"
                          ></textarea>
                        </label>
                        <div class="nt-ed-hints">
                          <span v-if="editorCh !== 'email'" class="nt-seg">{{ previewSegs }} SMS part{{ previewSegs === 1 ? '' : 's' }} with sample data</span>
                          <button class="nt-reset" @click="resetActive"><RotateCcw :size="12" /> Reset to default wording</button>
                        </div>
                        <!-- Insertable variables -->
                        <div class="nt-vars-mini">
                          <span class="nt-vars-mini-t"><Hash :size="12" /> Click to insert:</span>
                          <template v-for="g in TEMPLATE_VARS" :key="g.group">
                            <button v-for="v in g.vars" :key="v.code" class="nt-var" :title="v.label" @click="insertVar(v.code)">{{ v.code }}</button>
                          </template>
                        </div>
                      </div>

                      <!-- Live preview -->
                      <div class="nt-ed-prev">
                        <div class="nt-prev-head"><Eye :size="13" /> Preview with sample data
                          <button class="nt-prev-toggle" @click="showPreview = !showPreview">{{ showPreview ? 'hide' : 'show' }}</button>
                        </div>
                        <div v-if="showPreview" class="nt-prev-box" :class="editorCh">
                          <div v-if="editorCh === 'email'" class="nt-prev-subject">{{ previewSubject || '(no subject)' }}</div>
                          <div class="nt-prev-body">{{ previewBody || '(empty message)' }}</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Template vars reference ── -->
    <details class="card nt-vars-card">
      <summary class="nt-vars-title"><Hash :size="15" /> Template variables <span>Show reference</span></summary>
      <div class="nt-vars-sub">Use these tags anywhere in a subject or message — they’re replaced with the real values when the message is sent. Click a tag to copy it.</div>
      <div v-for="g in TEMPLATE_VARS" :key="g.group" class="nt-vars-group">
        <div class="nt-vars-g">{{ g.group }}</div>
        <div class="nt-vars-list">
          <button v-for="v in g.vars" :key="v.code" class="nt-var nt-var-lg" @click="copyVar(v.code)">
            <code>{{ v.code }}</code><span>{{ v.label }}</span>
            <Check v-if="copiedVar === v.code" :size="12" class="nt-var-ok" /><Copy v-else :size="12" class="nt-var-cp" />
          </button>
        </div>
      </div>
    </details>

    <div class="nt-foot-note"><Info :size="13" /> Connect WhatsApp in Marketing to enable delivery. SMS templates can be saved, but SMS delivery is not available yet.</div>
  </div>
</template>

<style scoped>
.nt-models { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin:24px 0; }
.nt-model-card { padding:22px; border:1px solid var(--border); border-radius:14px; background:var(--surface); }
.nt-model-tag { color:var(--primary-ink); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.nt-model-card h2 { font-size:18px; margin:10px 0; }
.nt-model-card p { font-size:13px; line-height:1.65; color:var(--text-muted); margin:10px 0; }
.nt-model-card .btn { margin-top:6px; }
.nt-model-card small { display:block; margin-top:12px; color:var(--text-muted); line-height:1.5; }
@media(max-width:760px) { .nt-models { grid-template-columns:1fr; } }

.page-sub { color: var(--text-muted); font-size: 13.5px; margin-top: 4px; max-width: 640px; }
.nt-spin { animation: ntspin 1s linear infinite; }
@keyframes ntspin { to { transform: rotate(360deg); } }

/* Channel strip */
.nt-channels { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 14px 0; }
.nt-chan { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text); background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 999px; padding: 6px 12px; }
.nt-chan.dim { color: var(--text-muted); background: var(--bg); }
.nt-chan-note { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); margin-left: auto; }

/* Sending-address banner */
.nt-sender { display: flex; align-items: center; gap: 11px; padding: 11px 15px; margin-bottom: 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .12s, background .12s; }
.nt-sender:hover { border-color: var(--primary); background: var(--surface-hover, #f8fafc); }
.nt-sender.own { border-color: var(--primary-border, #99f6e4); background: var(--primary-soft, #f0fdfa); }
.nt-sender-ic { color: var(--primary-ink); flex-shrink: 0; }
.nt-sender-txt { flex: 1; min-width: 0; font-size: 13px; color: var(--text); }
.nt-sender-cta { display: inline-flex; align-items: center; gap: 3px; font-size: 12.5px; font-weight: 700; color: var(--primary-ink); flex-shrink: 0; }

/* Matrix */
.nt-table { width: 100%; border-collapse: collapse; }
.nt-th-event { min-width: 260px; }
.nt-th-group { text-align: center; border-left: 2px solid var(--border); font-size: 11.5px; }
.nt-subhead th { padding-top: 4px; padding-bottom: 8px; }
.nt-th-ch { text-align: center; font-size: 10.5px; white-space: nowrap; }
.nt-th-ch svg { vertical-align: -2px; }
.nt-row { cursor: pointer; }
.nt-row.open { background: var(--surface-hover, #f8fafc); }
.nt-ev { display: flex; align-items: flex-start; gap: 8px; padding: 13px 16px; }
.nt-caret { color: var(--text-light, #94a3b8); margin-top: 3px; flex-shrink: 0; }
.nt-ev-ic { color: var(--primary-ink); margin-top: 2px; flex-shrink: 0; }
.nt-ev-txt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.nt-ev-txt b { font-size: 13.5px; }
.nt-ev-txt small { font-size: 11.5px; color: var(--text-muted); line-height: 1.4; }
.nt-cell { text-align: center; cursor: default; }
.nt-cell:nth-child(2), .nt-cell:nth-child(5) { border-left: 2px solid var(--border); }
.nt-na { color: var(--text-light, #cbd5e1); }

/* Toggle switch */
.nt-switch { position: relative; display: inline-block; width: 32px; height: 18px; }
.nt-switch input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; margin: 0; z-index: 1; cursor: pointer; }
.nt-slider { position: absolute; inset: 0; border-radius: 999px; background: var(--border, #e2e8f0); transition: background .15s; cursor: pointer; }
.nt-slider::before { content: ""; position: absolute; width: 14px; height: 14px; left: 2px; top: 2px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.2); transition: transform .15s; }
.nt-switch input:checked + .nt-slider { background: var(--primary); }
.nt-switch input:checked + .nt-slider::before { transform: translateX(14px); }

/* Reminder lead rows */
.nt-lead-row td { padding: 0 16px 10px 46px; border-bottom: 1px solid var(--border); }
.nt-lead { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--text-muted); }
.nt-lead-sel { border: 1px solid var(--border); border-radius: 8px; padding: 4px 8px; font-size: 12.5px; background: var(--surface, #ffffff); }

/* Editor */
.nt-editor-row td { padding: 0; background: var(--surface-hover, #f8fafc); border-bottom: 1px solid var(--border); }
.nt-editor { padding: 16px; }
.nt-ed-tabs { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.nt-ed-aud, .nt-ed-ch { display: flex; gap: 6px; flex-wrap: wrap; }
.nt-tab { border: 1.5px solid var(--border); background: var(--surface, #ffffff); border-radius: 999px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.nt-tab.active { border-color: var(--primary); color: var(--primary-ink); background: var(--primary-soft, #f0fdfa); }
.nt-tab-ch em { font-style: normal; font-size: 10px; font-weight: 800; text-transform: uppercase; color: var(--text-light, #94a3b8); }
.nt-ed-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 16px; }
@media (max-width: 900px) {
  .nt-ed-grid { grid-template-columns: 1fr; }
  /* The matrix is ~600px+ wide (event col + 6 toggle cols): scroll it inside
     its own container instead of blowing out / clipping the page. */
  .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
}
.nt-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 10px; }
.nt-field > span { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); }
.nt-input { width: 100%; border: 1px solid var(--border); border-radius: 10px; padding: 9px 12px; font-size: 13.5px; background: var(--surface, #ffffff); font-family: inherit; }
.nt-input:focus { outline: none; border-color: var(--primary); }
.nt-body { resize: vertical; line-height: 1.55; }
.nt-ed-hints { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.nt-seg { font-size: 11.5px; color: var(--text-muted); }
.nt-reset { display: inline-flex; align-items: center; gap: 5px; border: none; background: transparent; color: var(--text-muted); font-size: 11.5px; cursor: pointer; text-decoration: underline; }
.nt-reset:hover { color: var(--text); }

/* Var chips */
.nt-vars-mini { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.nt-vars-mini-t { font-size: 11px; font-weight: 700; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; margin-right: 3px; }
.nt-var { border: 1px solid var(--border); background: var(--surface, #ffffff); border-radius: 7px; padding: 3px 8px; font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--primary-ink, #0f766e); cursor: pointer; }
.nt-var:hover { border-color: var(--primary); background: var(--primary-soft, #f0fdfa); }

/* Preview */
.nt-ed-prev { min-width: 0; }
.nt-prev-head { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); margin-bottom: 8px; }
.nt-prev-toggle { margin-left: auto; border: none; background: transparent; color: var(--primary-ink); font-size: 11.5px; font-weight: 700; cursor: pointer; }
.nt-prev-box { border: 1px solid var(--border); border-radius: 12px; background: var(--surface, #ffffff); padding: 14px 16px; }
.nt-prev-box.sms, .nt-prev-box.whatsapp { border-radius: 16px; background: var(--positive-bg, #f0fdf4); border-color: var(--positive-border, #bbf7d0); max-width: 340px; }
.nt-prev-box.sms { background: var(--info-bg, #eff6ff); border-color: var(--info-border, #bfdbfe); }
.nt-prev-subject { font-weight: 800; font-size: 13.5px; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
.nt-prev-body { font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; color: var(--text); }

/* Vars reference card */
.nt-vars-card { margin-top: 16px; padding: 18px 20px; }
.nt-vars-title { display: flex; align-items: center; gap: 7px; font-size: 15px; font-weight: 800; }
.nt-vars-sub { font-size: 12.5px; color: var(--text-muted); margin: 4px 0 12px; }
.nt-vars-group { margin-bottom: 12px; }
.nt-vars-g { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-bottom: 6px; }
.nt-vars-list { display: flex; flex-wrap: wrap; gap: 6px; }
.nt-var-lg { display: inline-flex; align-items: center; gap: 7px; padding: 5px 10px; }
.nt-var-lg code { font-size: 11.5px; color: var(--primary-ink, #0f766e); }
.nt-var-lg span { font-size: 11.5px; color: var(--text-muted); font-family: inherit; }
.nt-var-cp { color: var(--text-light, #cbd5e1); }
.nt-var-ok { color: var(--positive-ink, #16a34a); }

.nt-foot-note { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); margin-top: 14px; }

/* ── Phone (≤600px) — narrow-screen (360px) fixes; desktop untouched ── */
@media (max-width: 600px) {
  /* Stack the header; full-width, comfortably tall primary action. */
  .page-header { flex-direction: column; align-items: stretch; gap: 10px; }
  .page-header .btn { width: 100%; min-height: 44px; justify-content: center; }

  /* When the channel strip wraps, the note takes its own left-aligned line. */
  .nt-chan-note { margin-left: 0; width: 100%; }

  /* The expanded editor + reminder lead controls pin to the viewport while the
     wide matrix scrolls underneath — no sideways scrolling to edit a template.
     100vw − 32px ≈ page gutters (12px × 2) + card borders, with a little slack. */
  .nt-editor { position: sticky; left: 0; max-width: calc(100vw - 32px); box-sizing: border-box; padding: 12px; }
  .nt-lead { position: sticky; left: 0; max-width: calc(100vw - 32px); flex-wrap: wrap; row-gap: 6px; }

  /* 16px fields stop iOS auto-zoom (scoped sizes beat the global phone rule). */
  .nt-input, .nt-lead-sel { font-size: 16px; }

  /* Segments label + reset button can exceed the narrow editor — let them wrap. */
  .nt-ed-hints { flex-wrap: wrap; row-gap: 6px; }

  /* Comfortable tap targets. */
  .nt-tab { padding: 10px 14px; }
  .nt-reset, .nt-prev-toggle { padding: 8px 0; }

  /* Keep type readable — nothing under 11px. */
  .nt-th-ch { font-size: 11px; }
  .nt-tab-ch em { font-size: 11px; }

  /* The channel on/off toggle is the primary control — enlarge it for
     easier tapping in the dense matrix (desktop keeps 32×18). Thumb travel
     = 40 − 20 − (2+2 edge gaps) = 16px, so it still seats in both states. */
  .nt-switch { width: 40px; height: 24px; }
  .nt-slider::before { width: 20px; height: 20px; }
  .nt-switch input:checked + .nt-slider::before { transform: translateX(16px); }
}

.nt-tools { display: flex; align-items: end; gap: 16px; margin: 24px 0 8px; }
.nt-tools label { display: flex; flex-direction: column; gap: 7px; font-size: 13px; font-weight: 600; }
.nt-search { flex: 1; }
.nt-tools select { min-width: 200px; }
.nt-help { color: var(--text-muted); font-size: 13px; margin-bottom: 16px; }
.nt-event-button { text-align: start; background: none; border: 0; color: var(--text); padding: 0; font-size: 14px; font-weight: 650; cursor: pointer; }
.nt-event-button:hover { color: var(--primary-ink); }
.nt-no-results { padding: 32px; text-align: center; color: var(--text-muted); }
.nt-switch:has(input:focus-visible) { outline: 2px solid var(--primary-ink); outline-offset: 4px; border-radius: 12px; }
.nt-mobile-channel { display: none; }
.nt-field > span { font-size: 13px; text-transform: none; letter-spacing: 0; }
.nt-vars-title { cursor: pointer; min-height: 32px; }
.nt-vars-title span { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--text-muted); }
.nt-vars-card[open] .nt-vars-title span { display: none; }
@media (max-width: 600px) { .nt-tools { align-items: stretch; flex-direction: column; } .nt-tools select { width: 100%; } }
@media (max-width: 600px) {
  /* Keep each notification and its recipients together, without a wide matrix. */
  .table-scroll { overflow-x: visible; }
  .nt-table, .nt-table tbody { display: block; min-width: 0; width: 100%; }
  .nt-table thead { display: none; }
  .nt-row { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); padding: 14px; border-bottom: 1px solid var(--border); }
  .nt-row:not(:first-child) { border-top: 8px solid var(--bg); }
  .nt-ev { grid-column: 1 / -1; border: 0; padding: 0 0 14px; white-space: normal; }
  .nt-ev-txt small { font-size: 12px; line-height: 1.5; margin-top: 4px; }
  .nt-row .nt-cell { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 4px; border: 0; border-top: 1px solid var(--border); min-width: 0; }
  .nt-mobile-channel { display: flex; flex-direction: column; gap: 3px; font-size: 11px; color: var(--text-muted); }
  .nt-mobile-channel b { font-size: 12px; font-weight: 600; color: var(--text); }
  .nt-editor-row, .nt-editor-row > td, .nt-lead-row, .nt-no-results { display: block; width: 100%; }
  .nt-editor { position: static; max-width: none; padding: 16px; }
  .nt-lead-row .nt-lead { position: static; max-width: none; padding: 12px 16px; white-space: normal; }
  .nt-ed-form { min-width: 0; }
}
</style>
