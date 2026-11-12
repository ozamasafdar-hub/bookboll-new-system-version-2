<script setup>
import { ref, computed, onMounted } from 'vue'
import { Settings as SettingsIcon, Bell, Shield, DollarSign, Globe as Globe2, Mail, Building2, User, FileText, AlertTriangle, Info, Check, Crown, Gift } from 'lucide-vue-next'
import { pushToast } from '../lib/toast.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { changeOwnPassword, authError } from '../lib/auth.js'
import { fetchSettings, updateSettings, asBool, asNumber } from '../lib/settings.js'
import { useRouter } from 'vue-router'
import FeatureSearch from '../components/FeatureSearch.vue'
import { askConfirm } from '../lib/confirm.js'
import { hasPaidWebsiteDiscount } from '../lib/listingPayments.js'
import { loadProviderSettings, saveBusinessProfile, saveAccountDetails, saveNotificationPreferences } from '../lib/providerSettings.js'
import {
  planState, ensurePlan, openUpgrade, endPlan, keepPlan,
  PREMIUM_YEARLY_TOTAL,
} from '../lib/premium.js'

const router = useRouter()
const { isSuperAdmin, isCountryAdmin, currentUser } = useCurrentUser()

const isAdminView = computed(() => isSuperAdmin.value || isCountryAdmin.value)
const websiteDiscount = computed(() => hasPaidWebsiteDiscount(planState.value))

const tab = ref('platform')
const loading = ref(false)
const savingAll = ref(false)
const savedAll = ref(false)

const platform = ref({ name: 'Bookboll', tagline: 'Book your game', currency: '' })
const notifications = ref({
  newBooking: true,
  paymentFailed: true,
  providerSignup: true,
  daily: false,
})

const policies = ref({
  cancellation_hours: 48,
  booking_hold_minutes: 10,
  noshow_policy: 'Customer forfeits deposit. Provider receives nothing. Provider may ban the customer from the dashboard.',
  trial_cancel_policy: 'Customer must cancel before the trial period ends. First billing charge applies if not cancelled in time.',
})
const policiesSaving = ref(false)
const policiesSaved = ref(false)
const platformSaving = ref(false)
const platformSaved = ref(false)
const notificationsSaving = ref(false)
const notificationsSaved = ref(false)

function applySettingsMap(map) {
  const s = map || {}
  platform.value = {
    name: s.platform_name || platform.value.name,
    tagline: s.platform_tagline || platform.value.tagline,
    currency: s.platform_default_currency || platform.value.currency,
  }
  policies.value = {
    cancellation_hours: asNumber(s.cancellation_hours, policies.value.cancellation_hours),
    booking_hold_minutes: asNumber(
      s.booking_hold_minutes ?? s.default_bookings_slot_lock_time,
      policies.value.booking_hold_minutes,
    ),
    noshow_policy: s.noshow_policy ?? policies.value.noshow_policy,
    trial_cancel_policy: s.trial_cancel_policy ?? policies.value.trial_cancel_policy,
  }
  notifications.value = {
    newBooking: asBool(s.notify_new_booking, true),
    paymentFailed: asBool(s.notify_payment_failed, true),
    providerSignup: asBool(s.notify_provider_signup, true),
    daily: asBool(s.notify_daily_summary, false),
  }
}

async function loadAdminSettings() {
  loading.value = true
  const res = await fetchSettings()
  loading.value = false
  if (!res.success) {
    pushToast(res.message || 'Could not load settings.', 'error')
    return
  }
  applySettingsMap(res.data?.settings || {})
}

function platformRows() {
  return [
    { key: 'platform_name', value: String(platform.value.name || '').trim() || 'Bookboll' },
    { key: 'platform_tagline', value: String(platform.value.tagline || '').trim() },
    { key: 'platform_default_currency', value: String(platform.value.currency || '').trim().toUpperCase() },
  ]
}

function policyRows() {
  return [
    { key: 'cancellation_hours', value: String(Math.max(0, Number(policies.value.cancellation_hours) || 0)) },
    { key: 'booking_hold_minutes', value: String(Math.max(1, Number(policies.value.booking_hold_minutes) || 10)) },
    { key: 'noshow_policy', value: policies.value.noshow_policy || '' },
    { key: 'trial_cancel_policy', value: policies.value.trial_cancel_policy || '' },
  ]
}

function notificationRows() {
  return [
    { key: 'notify_new_booking', value: notifications.value.newBooking ? '1' : '0' },
    { key: 'notify_payment_failed', value: notifications.value.paymentFailed ? '1' : '0' },
    { key: 'notify_provider_signup', value: notifications.value.providerSignup ? '1' : '0' },
    { key: 'notify_daily_summary', value: notifications.value.daily ? '1' : '0' },
  ]
}

async function saveRows(rows) {
  const res = await updateSettings(rows)
  if (!res.success) {
    pushToast(res.message || 'Could not save settings.', 'error')
    return false
  }
  return true
}

async function savePlatform() {
  platformSaving.value = true
  const ok = await saveRows(platformRows())
  platformSaving.value = false
  if (!ok) return
  platformSaved.value = true
  pushToast('Platform settings saved', 'success')
  setTimeout(() => { platformSaved.value = false }, 2500)
}

async function savePolicies() {
  policiesSaving.value = true
  const ok = await saveRows(policyRows())
  policiesSaving.value = false
  if (!ok) return
  policiesSaved.value = true
  pushToast('Policies saved', 'success')
  setTimeout(() => { policiesSaved.value = false }, 2500)
}

async function saveNotifications() {
  notificationsSaving.value = true
  const ok = await saveRows(notificationRows())
  notificationsSaving.value = false
  if (!ok) return
  notificationsSaved.value = true
  pushToast('Notification preferences saved', 'success')
  setTimeout(() => { notificationsSaved.value = false }, 2500)
}

async function saveAllChanges() {
  savingAll.value = true
  const ok = await saveRows([
    ...platformRows(),
    ...policyRows(),
    ...notificationRows(),
  ])
  savingAll.value = false
  if (!ok) return
  savedAll.value = true
  pushToast('All settings saved', 'success')
  setTimeout(() => { savedAll.value = false }, 2500)
}

// ── Provider admin state ──────────────────────────────────────
// Plan & billing — a real Stripe subscription, so this panel shows what is actually
// billed and lets the provider stop it. Cancelling takes effect at the end of the
// paid period; nothing is cut off early.
const planBusy = ref(false)
const planTrialActive = computed(() => {
  const t = planState.value.trial_ends_at
  return !!t && new Date(t) > new Date()
})
function fmtPlanDate(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
async function doEndPlan() {
  if (planBusy.value) return
  if (!await askConfirm({
    title: 'Cancel Premium?',
    message: 'You keep every Premium feature until the end of your current billing period. After that the account moves back to the free plan.',
    confirmText: 'Cancel Premium', danger: true,
  })) return
  planBusy.value = true
  await endPlan()
  planBusy.value = false
}
async function doKeepPlan() {
  if (planBusy.value) return
  planBusy.value = true
  await keepPlan()
  planBusy.value = false
}

const providerTab = ref('profile')
const providerForm = ref({ business_name: '', description: '', phone: '', full_name: '', account_phone: '', email: '' })
const saving = ref(false)
const saved = ref(false)

// Loaded from the server, not from the signed-in user payload: business name,
// phone and description are the BUSINESS's, and the old form read them off the
// user, where those fields do not exist.
const providerLoading = ref(false)
const providerUnsupported = ref(false)
const providerError = ref('')
const providerFieldErrors = ref({})
const canEditBusiness = ref(false)
const notificationPrefs = ref({})
const notificationCatalogue = ref([])
const notificationAlwaysOn = ref([])

const providerSaveHint = computed(() => {
  if (providerUnsupported.value) return 'Updates are not available on this server yet'
  if (providerTab.value === 'profile' && !canEditBusiness.value) return 'Only the business owner can change these details'
  return 'Save changes'
})

function applyProviderSettings(settings) {
  canEditBusiness.value = settings.can_edit_business
  providerForm.value = {
    business_name: settings.business?.name || '',
    description: settings.business?.description || '',
    phone: settings.business?.phone || '',
    full_name: settings.account.name,
    account_phone: settings.account.phone,
    email: settings.account.email,
  }
  notificationPrefs.value = { ...settings.notifications.preferences }
  notificationCatalogue.value = settings.notifications.available
  notificationAlwaysOn.value = settings.notifications.always_on
}

async function loadProvider() {
  providerLoading.value = true
  providerError.value = ''
  providerFieldErrors.value = {}
  const result = await loadProviderSettings()
  providerUnsupported.value = result.unsupported === true
  if (result.ok) applyProviderSettings(result.settings)
  else if (!result.unsupported) providerError.value = result.error
  providerLoading.value = false
}

async function saveProviderSection() {
  if (saving.value || providerUnsupported.value) return
  saving.value = true
  providerError.value = ''
  providerFieldErrors.value = {}

  const result = providerTab.value === 'profile'
    ? await saveBusinessProfile({ name: providerForm.value.business_name, phone: providerForm.value.phone, description: providerForm.value.description })
    : providerTab.value === 'account'
      ? await saveAccountDetails({ name: providerForm.value.full_name, phone: providerForm.value.account_phone })
      : await saveNotificationPreferences(notificationPrefs.value, notificationCatalogue.value)

  saving.value = false
  providerFieldErrors.value = result.fields || {}

  if (!result.ok) {
    // Only after persistence is confirmed is this a success — the read-back
    // check is why these controls were switched off in the first place.
    providerError.value = result.error
    pushToast(result.error, 'error')
    return
  }

  if (result.notifications) notificationPrefs.value = { ...result.notifications.preferences }
  saved.value = true
  setTimeout(() => { saved.value = false }, 2500)
  pushToast(providerTab.value === 'notifications' ? 'Notification preferences saved' : 'Saved', 'success')
}

onMounted(async () => {
  ensurePlan()
  if (isAdminView.value) await loadAdminSettings()
  else await loadProvider()
})

// ── Change password (Laravel /change-password) ────────────────
const pwCurrent = ref('')
const pwNew = ref('')
const pwConfirm = ref('')
const pwSaving = ref(false)
async function changePassword() {
  const p = pwNew.value
  if (!pwCurrent.value) { pushToast('Enter your current password.', 'error'); return }
  if (p.length < 10) { pushToast('Use at least 10 characters.', 'error'); return }
  if (p !== pwConfirm.value) { pushToast('The two passwords don\'t match.', 'error'); return }
  pwSaving.value = true
  const ok = await changeOwnPassword(p, pwCurrent.value)
  pwSaving.value = false
  if (!ok) { pushToast(authError.value || 'Could not change the password.', 'error'); return }
  pwCurrent.value = ''; pwNew.value = ''; pwConfirm.value = ''
  pushToast('Password changed — use the new one from your next sign-in.', 'success')
}

</script>

<template>
  <div class="page">

    <!-- ── Super / Country Admin Settings ── -->
    <template v-if="isAdminView">
      <div class="page-header">
        <div>
          <h1 class="page-title">Settings</h1>
          <div class="page-subtitle">Platform-wide configuration and preferences</div>
        </div>
        <button class="btn btn-primary" :disabled="savingAll || loading" @click="saveAllChanges">
          {{ savingAll ? 'Saving…' : savedAll ? 'Saved!' : 'Save All Changes' }}
        </button>
      </div>

      <FeatureSearch />

      <div class="grid" style="grid-template-columns: 240px 1fr;">
        <div class="card" style="padding: 12px;">
          <div class="set-nav">
            <button type="button" class="set-item" :class="{active: tab==='platform'}" @click="tab='platform'"><SettingsIcon :size="16" />Platform</button>
            <button type="button" class="set-item" :class="{active: tab==='commission'}" @click="tab='commission'"><DollarSign :size="16" />Commission</button>
            <button type="button" class="set-item" :class="{active: tab==='policies'}" @click="tab='policies'"><FileText :size="16" />Policies</button>
            <button type="button" class="set-item" :class="{active: tab==='notifications'}" @click="tab='notifications'"><Bell :size="16" />Notifications</button>
            <button type="button" class="set-item" :class="{active: tab==='security'}" @click="tab='security'"><Shield :size="16" />Security</button>
            <button type="button" class="set-item" :class="{active: tab==='email'}" @click="tab='email'"><Mail :size="16" />Email Templates</button>
            <button type="button" class="set-item" :class="{active: tab==='regional'}" @click="tab='regional'"><Globe2 :size="16" />Regional</button>
          </div>
        </div>

        <div class="card" style="padding: 28px;">
          <div v-if="tab==='platform'">
            <h3>Platform Settings</h3>
            <p v-if="loading" class="muted">Loading…</p>
            <template v-else>
              <div class="form-group"><label class="form-label">Platform Name</label><input v-model="platform.name" /></div>
              <div class="form-group"><label class="form-label">Tagline</label><input v-model="platform.tagline" /></div>
              <div class="form-group"><label class="form-label">Default Currency</label><input v-model="platform.currency" /></div>
              <div style="display:flex; align-items:center; gap:12px; margin-top:8px;">
                <button class="btn btn-primary" :disabled="platformSaving" @click="savePlatform">
                  {{ platformSaving ? 'Saving…' : 'Save Platform' }}
                </button>
                <span v-if="platformSaved" style="display:flex;align-items:center;gap:5px;color:var(--primary);font-size:13px;font-weight:600;">
                  <Check :size="14" /> Saved
                </span>
              </div>
            </template>
          </div>
          <div v-if="tab==='commission'">
            <h3>Commission Configuration</h3>
            <p class="muted">These are the standard booking commission rates. Bookboll keeps the online deposit fee, or deducts commission from a full online payment. Own-website bookings use separate Free and paid Premium rates set for the venue’s city, country or global defaults. Marketplace rates stay the same; connecting payouts does not waive commission.</p>

            <div class="info-banner" style="margin-bottom:20px;">
              <Info :size="16" />
              <div>
                <strong>Rates are managed per country.</strong> Go to
                <button class="inline-link" @click="router.push('/countries')">Countries &amp; Cities</button>
                to set default facility and program rates per country. You can also override the rate on individual listings directly in the listing form.
              </div>
            </div>

            <div class="policy-card">
              <div class="policy-card-title"><DollarSign :size="14" /> How the deposit works</div>
              <ul class="policy-list">
                <li><strong>Provider listings:</strong> Bookboll collects the commission % as an online deposit. The customer pays the remaining balance directly to the provider on arrival.</li>
                <li><strong>Bookboll-owned listings:</strong> Bookboll collects 100% of the payment online — no split is required.</li>
                <li>Commission changes only affect future bookings. Historical bookings retain the rate at time of creation.</li>
              </ul>
            </div>
          </div>

          <!-- POLICIES TAB -->
          <div v-if="tab==='policies'">
            <h3>Booking Policies</h3>
            <p class="muted">Configure cancellation, no-show, and subscription trial rules. These are applied platform-wide.</p>

            <!-- Facility Cancellation -->
            <div class="policy-card">
              <div class="policy-card-title"><AlertTriangle :size="14" /> Facility Booking Cancellation</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Cancellation window (hours before booking)</label>
                <div style="display:flex; align-items:center; gap:10px;">
                  <input type="number" min="0" max="168" v-model.number="policies.cancellation_hours" style="width:100px;" />
                  <span class="muted" style="font-size:13px;">hours — customer must cancel at least this far in advance to be eligible for a refund</span>
                </div>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>Cancellations made within <strong>{{ policies.cancellation_hours }} hours</strong> of the booking are marked as <strong>No-Show</strong> — no refund is issued.</span>
              </div>
            </div>

            <!-- Checkout hold timer -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><AlertTriangle :size="14" /> Checkout Hold Timer</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Reserve selected slots for (minutes)</label>
                <div style="display:flex; align-items:center; gap:10px;">
                  <input type="number" min="1" max="60" v-model.number="policies.booking_hold_minutes" style="width:100px;" />
                  <span class="muted" style="font-size:13px;">minutes — how long a customer's chosen time slots stay reserved while they check out</span>
                </div>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>While booking, a customer's selected slots are held for <strong>{{ policies.booking_hold_minutes }} minutes</strong> so no one else can take them. If they don't finish in time, the slots are released automatically. Applies to all listings.</span>
              </div>
            </div>

            <!-- No-Show Policy -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><AlertTriangle :size="14" /> No-Show Policy</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">No-show policy text (shown to customers at booking)</label>
                <textarea v-model="policies.noshow_policy" rows="3"></textarea>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>When a no-show occurs: the customer's deposit is forfeited. The provider receives nothing for that slot. The provider may ban the customer from their listings via the admin dashboard.</span>
              </div>
            </div>

            <!-- Trial / Program Cancellation -->
            <div class="policy-card" style="margin-top:14px;">
              <div class="policy-card-title"><FileText :size="14" /> Membership Program / Trial Policy</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Trial cancellation policy text</label>
                <textarea v-model="policies.trial_cancel_policy" rows="3"></textarea>
              </div>
              <div class="policy-info-box">
                <Info :size="13" />
                <span>Customers must cancel <strong>before the trial period ends</strong> to avoid being charged. If the trial is not cancelled in time, the first billing cycle is charged automatically.</span>
              </div>
            </div>

            <div style="display:flex; align-items:center; gap:12px; margin-top:20px;">
              <button class="btn btn-primary" :disabled="policiesSaving" @click="savePolicies">
                {{ policiesSaving ? 'Saving…' : 'Save Policies' }}
              </button>
              <span v-if="policiesSaved" style="display:flex;align-items:center;gap:5px;color:var(--primary);font-size:13px;font-weight:600;">
                <Check :size="14" /> Saved
              </span>
            </div>
          </div>
          <div v-if="tab==='notifications'">
            <h3>Notifications</h3>
            <p class="muted">Choose which platform events should notify Bookboll staff.</p>
            <label class="toggle"><input type="checkbox" v-model="notifications.newBooking" />New booking alerts</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.paymentFailed" />Payment failure alerts</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.providerSignup" />New provider signups</label>
            <label class="toggle"><input type="checkbox" v-model="notifications.daily" />Daily summary emails</label>
            <div style="display:flex; align-items:center; gap:12px; margin-top:16px;">
              <button class="btn btn-primary" :disabled="notificationsSaving" @click="saveNotifications">
                {{ notificationsSaving ? 'Saving…' : 'Save Notifications' }}
              </button>
              <span v-if="notificationsSaved" style="display:flex;align-items:center;gap:5px;color:var(--primary);font-size:13px;font-weight:600;">
                <Check :size="14" /> Saved
              </span>
            </div>
          </div>
          <div v-if="tab==='security'">
            <h3>Security</h3>
            <p class="muted">Change the password for the account you're signed in with ({{ currentUser?.email }}).</p>

            <div class="policy-card">
              <div class="policy-card-title"><Shield :size="14" /> Change my password</div>
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Current password</label>
                <input type="password" v-model="pwCurrent" autocomplete="current-password" placeholder="Your current password" style="max-width:340px;" />
              </div>
              <div class="form-group">
                <label class="form-label">New password</label>
                <input type="password" v-model="pwNew" autocomplete="new-password" placeholder="At least 10 characters" style="max-width:340px;" />
              </div>
              <div class="form-group">
                <label class="form-label">Repeat new password</label>
                <input type="password" v-model="pwConfirm" autocomplete="new-password" placeholder="Same password again" style="max-width:340px;" />
              </div>
              <button class="btn btn-primary" :disabled="pwSaving || !pwNew || !pwCurrent" @click="changePassword">{{ pwSaving ? 'Changing…' : 'Change password' }}</button>
              <div class="policy-info-box" style="margin-top:12px;">
                <Info :size="13" />
                <span>Takes effect immediately — you stay signed in here, and the <strong>new</strong> password is required from your next sign-in on any device.</span>
              </div>
            </div>
          </div>
          <div v-if="tab==='email'"><h3>Email Templates</h3><p class="muted">Customize booking confirmations, receipts and reminders.</p></div>
          <div v-if="tab==='regional'"><h3>Regional</h3><p class="muted">Tax rates, timezones and language preferences per country.</p></div>
        </div>
      </div>
    </template>

    <!-- ── Provider Admin Settings ── -->
    <template v-else>
      <div class="page-header">
        <div>
          <h1 class="page-title">Settings</h1>
          <div class="page-subtitle">Manage your business profile and preferences</div>
        </div>
        <button
          v-if="['profile', 'account', 'notifications'].includes(providerTab)"
          class="btn btn-primary"
          :disabled="saving || providerLoading || providerUnsupported || (providerTab === 'profile' && !canEditBusiness)"
          :title="providerSaveHint"
          @click="saveProviderSection"
        >
          {{ saving ? 'Saving…' : providerUnsupported ? 'Save unavailable' : 'Save changes' }}
        </button>
      </div>

      <FeatureSearch />

      <div class="grid" style="grid-template-columns: 240px 1fr;">
        <div class="card" style="padding: 12px;">
          <div class="set-nav">
            <button type="button" class="set-item" :class="{active: providerTab==='profile'}" @click="providerTab='profile'"><Building2 :size="16" />Business Profile</button>
            <button type="button" class="set-item" :class="{active: providerTab==='account'}" @click="providerTab='account'"><User :size="16" />My Account</button>
            <button type="button" class="set-item" :class="{active: providerTab==='notifications'}" @click="providerTab='notifications'"><Bell :size="16" />Notifications</button>
            <button type="button" class="set-item" :class="{active: providerTab==='security'}" @click="providerTab='security'"><Shield :size="16" />Security</button>
            <button type="button" class="set-item" :class="{active: providerTab==='plan'}" @click="providerTab='plan'"><Crown :size="16" />Plan &amp; billing</button>
          </div>
        </div>

        <div class="card" style="padding: 28px;">
          <p v-if="providerLoading" class="muted" role="status">Loading your settings…</p>

          <!-- An API without these endpoints keeps the old read-only behaviour
               rather than offering a save that cannot work. -->
          <p v-else-if="providerUnsupported && providerTab !== 'security' && providerTab !== 'plan'" class="info-banner">
            <Info :size="16" />Profile and notification updates are not available on this server yet. You can edit your public website name and content in My Website.
          </p>

          <p v-else-if="providerError && providerTab !== 'security' && providerTab !== 'plan'" class="info-banner" role="alert">
            <Info :size="16" />{{ providerError }}
          </p>

          <div v-if="providerTab==='profile' && !providerLoading && !providerUnsupported">
            <h3>Business Profile</h3>
            <p class="muted">Your business information on Bookboll.</p>
            <p class="info-banner"><Info :size="16" />This is your business name. Your public website name and content are edited in My Website.</p>
            <p v-if="!canEditBusiness" class="info-banner"><Shield :size="16" /><div>Only the business owner can change these details.</div></p>
            <div class="form-group">
              <label class="form-label" for="pv-biz-name">Business Name</label>
              <input id="pv-biz-name" :readonly="!canEditBusiness" aria-label="Business name" v-model="providerForm.business_name" placeholder="e.g. Desert Sports Club" :aria-invalid="!!providerFieldErrors.name" :aria-describedby="providerFieldErrors.name ? 'pv-biz-name-error' : undefined" />
              <p v-if="providerFieldErrors.name" id="pv-biz-name-error" class="field-error-text" role="alert">{{ providerFieldErrors.name }}</p>
            </div>
            <div class="form-group">
              <label class="form-label" for="pv-biz-phone">Phone Number</label>
              <input id="pv-biz-phone" :readonly="!canEditBusiness" aria-label="Phone number" v-model="providerForm.phone" placeholder="+971 50 000 0000" :aria-invalid="!!providerFieldErrors.phone" :aria-describedby="providerFieldErrors.phone ? 'pv-biz-phone-error' : undefined" />
              <p v-if="providerFieldErrors.phone" id="pv-biz-phone-error" class="field-error-text" role="alert">{{ providerFieldErrors.phone }}</p>
            </div>
            <div class="form-group">
              <label class="form-label" for="pv-biz-desc">Description</label>
              <textarea id="pv-biz-desc" :readonly="!canEditBusiness" aria-label="Business description" v-model="providerForm.description" rows="4" placeholder="Tell customers about your facilities, sports offered, and what makes you unique…" :aria-invalid="!!providerFieldErrors.description" :aria-describedby="providerFieldErrors.description ? 'pv-biz-desc-error' : undefined"></textarea>
              <p v-if="providerFieldErrors.description" id="pv-biz-desc-error" class="field-error-text" role="alert">{{ providerFieldErrors.description }}</p>
            </div>
          </div>

          <div v-if="providerTab==='account' && !providerLoading && !providerUnsupported">
            <h3>My Account</h3>
            <p class="muted">Your personal details on this account.</p>
            <div class="form-group">
              <label class="form-label" for="pv-acc-name">Full Name</label>
              <input id="pv-acc-name" aria-label="Full name" v-model="providerForm.full_name" :aria-invalid="!!providerFieldErrors.name" :aria-describedby="providerFieldErrors.name ? 'pv-acc-name-error' : undefined" />
              <p v-if="providerFieldErrors.name" id="pv-acc-name-error" class="field-error-text" role="alert">{{ providerFieldErrors.name }}</p>
            </div>
            <div class="form-group">
              <label class="form-label" for="pv-acc-phone">Phone Number</label>
              <input id="pv-acc-phone" aria-label="Your phone number" v-model="providerForm.account_phone" placeholder="+971 50 000 0000" :aria-invalid="!!providerFieldErrors.phone" :aria-describedby="providerFieldErrors.phone ? 'pv-acc-phone-error' : undefined" />
              <p v-if="providerFieldErrors.phone" id="pv-acc-phone-error" class="field-error-text" role="alert">{{ providerFieldErrors.phone }}</p>
            </div>
            <div class="form-group"><label class="form-label">Email</label><input :value="providerForm.email" disabled style="opacity:0.6; cursor:not-allowed;" /></div>
            <div class="info-banner"><Shield :size="16" /><div>Email changes require contacting support to verify your identity.</div></div>
          </div>

          <div v-if="providerTab==='notifications' && !providerLoading && !providerUnsupported">
            <h3>Notifications</h3>
            <p class="muted">Choose which messages you receive. These are your own settings — other people on this business choose separately.</p>
            <label v-for="row in notificationCatalogue" :key="row.key" class="toggle">
              <input type="checkbox" :checked="notificationPrefs[row.key] === true" @change="notificationPrefs[row.key] = $event.target.checked" />{{ row.label }}
            </label>
            <p v-if="!notificationCatalogue.length" class="muted">This server does not offer any personal notification settings yet.</p>

            <!-- Alerts nobody may switch off, named rather than quietly absent. -->
            <div v-if="notificationAlwaysOn.length" class="info-banner">
              <Shield :size="16" />
              <div>Always sent: <template v-for="(row, i) in notificationAlwaysOn" :key="row.kind">{{ i ? ' · ' : '' }}{{ row.reason }}</template></div>
            </div>
          </div>

          <div v-if="providerTab==='plan'">
            <h3>Plan &amp; billing</h3>
            <p class="muted">Your Bookboll for Business subscription.</p>

            <div class="plan-box" :class="{ prem: planState.is_premium }">
              <div class="plan-hd">
                <span class="plan-chip" :class="{ prem: planState.is_premium }">
                  <Crown v-if="planState.is_premium" :size="12" />{{ planState.is_premium ? 'Premium' : 'Free' }}
                </span>
                <span v-if="planState.is_premium && planState.billing" class="plan-cycle">
                  {{ planState.billing === 'yearly' ? `$${PREMIUM_YEARLY_TOTAL}/year` : 'Existing monthly subscription' }}
                </span>
              </div>

              <p v-if="!planState.is_premium" class="muted" style="margin:0 0 14px;">
                Your Free plan has no subscription fee. Premium is US$588 per year, billed annually with no free trial. Paid Premium halves your own-website booking commission and adds marketing, exports, promo codes and more.
              </p>
              <template v-else>
                <div v-if="planTrialActive" class="plan-line">
                  <Gift :size="14" /> Existing trial ends <b>{{ fmtPlanDate(planState.trial_ends_at) }}</b> — no subscription charge yet. Booking commissions apply.
                </div>
                <div v-if="planState.cancel_at_period_end" class="plan-line warn">
                  <AlertTriangle :size="14" /> Ending on <b>{{ fmtPlanDate(planState.renews_at) }}</b>. You keep everything until then.
                </div>
                <div v-else-if="planState.renews_at" class="plan-line">
                  <Check :size="14" /> Renews on <b>{{ fmtPlanDate(planState.renews_at) }}</b>.
                </div>
                <div v-if="planState.status === 'past_due'" class="plan-line warn">
                  <AlertTriangle :size="14" /> Your last payment didn't go through — update your card to avoid losing Premium.
                </div>
              </template>

              <div class="plan-actions">
                <button v-if="!planState.is_premium" class="btn btn-primary" @click="openUpgrade('')">
                  <Crown :size="15" /> Upgrade to Premium
                </button>
                <button v-else-if="planState.cancel_at_period_end" class="btn btn-primary" :disabled="planBusy" @click="doKeepPlan">
                  {{ planBusy ? 'Working…' : 'Keep Premium' }}
                </button>
                <button v-else-if="planState.has_subscription" class="btn btn-secondary" :disabled="planBusy" @click="doEndPlan">
                  {{ planBusy ? 'Working…' : 'Cancel Premium' }}
                </button>
              </div>
            </div>

            <section class="plan-commission" aria-labelledby="plan-commission-title">
              <h4 id="plan-commission-title">Your booking commissions</h4>
              <dl>
                <div><dt>Your own website</dt><dd>{{ websiteDiscount ? 'Paid Premium rate' : 'Free plan rate' }}<small>Set for each venue’s market</small></dd></div>
                <div><dt>Bookboll marketplace</dt><dd>Standard rate<small>The same on Free and Premium</small></dd></div>
              </dl>
              <p>Your listing’s payment settings show its rate. The Premium website rate applies during your active paid subscription.</p>
              <p>With <b>deposit + on arrival</b>, Bookboll keeps the online deposit fee and you collect the remaining balance. With <b>full payment online</b>, the fee is deducted before your share reaches your Bookings wallet.</p>
              <p>Commission applies with or without connected payouts. Only full online payment requires a payout connection.</p>
            </section>

            <div v-if="planState.domain?.domain" class="plan-box" style="margin-top:14px;">
              <div class="plan-hd"><span class="plan-chip">Custom domain</span></div>
              <div class="plan-line"><Globe2 :size="14" /> <b>{{ planState.domain.domain }}</b> — {{ planState.domain.status === 'active' ? 'live' : 'waiting on DNS' }}</div>
              <div class="plan-line muted-line">One-time setup fee of ${{ planState.domain.fee }} paid on {{ fmtPlanDate(planState.domain.paid_at) }}. No monthly cost.</div>
            </div>
          </div>

          <div v-if="providerTab==='security'">
            <h3>Security</h3>
            <p class="muted">Keep your account safe.</p>
            <div class="form-group"><label class="form-label">Current Password</label><input v-model="pwCurrent" aria-label="Current password" type="password" autocomplete="current-password" /></div>
            <div class="form-group"><label class="form-label">New Password</label><input v-model="pwNew" aria-label="New password" type="password" autocomplete="new-password" placeholder="At least 10 characters" /></div>
            <div class="form-group"><label class="form-label">Confirm New Password</label><input v-model="pwConfirm" aria-label="Confirm new password" type="password" autocomplete="new-password" /></div>
            <button class="btn btn-primary" :disabled="pwSaving" @click="changePassword">{{ pwSaving ? 'Updating…' : 'Update password' }}</button>
          </div>
        </div>
      </div>
    </template>

  </div>
</template>

<style scoped>
h3 { font-size: 17px; font-weight: 600; margin-bottom: 6px; }
.muted { color: var(--text-muted); font-size: 13px; margin-bottom: 16px; }
.plan-box { border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.plan-commission { margin-top: 20px; padding: 20px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); }
.plan-commission h4 { font-size: 16px; margin: 0 0 16px; color: var(--text); }
.plan-commission dl { margin: 0; }
.plan-commission dl > div { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px 20px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.plan-commission dt { font-size: 13px; color: var(--text-secondary); }
.plan-commission dd { margin: 0; font-size: 14px; font-weight: 650; color: var(--text); }
.plan-commission small { display: block; color: var(--text-muted); font-size: 12px; font-weight: 400; margin-top: 5px; }
.plan-commission p { margin: 14px 0 0; color: var(--text-secondary); font-size: 13px; line-height: 1.7; }
.plan-box.prem { border-color: #fbbf24; background: linear-gradient(180deg, var(--warning-bg, #fffbeb) 0%, transparent 70%); }
.plan-hd { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.plan-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; background: var(--bg); color: var(--text-muted); font-size: 12px; font-weight: 600; }
.plan-chip.prem { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.plan-cycle { font-size: 13px; color: var(--text-muted); }
.plan-line { display: flex; align-items: center; gap: 8px; font-size: 13.5px; padding: 5px 0; }
.plan-line.warn { color: var(--warning-ink, #b45309); }
.plan-line.muted-line { color: var(--text-muted); font-size: 12.5px; }
.plan-actions { margin-top: 16px; }
.set-nav { display: flex; flex-direction: column; gap: 2px; }
.set-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; font-size: 14px; color: var(--text-muted); cursor: pointer; transition: all 0.15s; }
.set-item:hover { background: var(--bg); color: var(--text); }
.set-item.active { background: var(--primary-soft, #ccfbf1); color: var(--primary-ink); font-weight: 500; }
.toggle { display: flex; align-items: center; gap: 10px; padding: 14px; border-bottom: 1px solid var(--border); font-size: 14px; cursor: pointer; }
.toggle input { width: auto; }
.info-banner { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--primary-soft, #ccfbf1); border-radius: 8px; color: var(--primary-ink, #0f766e); font-size: 13px; margin-top: 12px; }
.inline-link { background: none; border: none; color: var(--primary-ink); text-decoration: underline; cursor: pointer; font-size: 13px; padding: 0; }
.policy-card { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 16px 18px; }
.policy-card-title { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.policy-list { margin: 10px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.7; color: var(--text-muted); }
.policy-info-box { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 10px 12px; background: var(--info-bg, #eff6ff); border: 1px solid var(--info-border, #bfdbfe); border-radius: 8px; font-size: 12.5px; color: var(--info-ink, #1e40af); line-height: 1.5; }
@media (max-width: 900px) {
  .grid { grid-template-columns: 1fr !important; }
  /* Sidebar nav becomes a horizontally scrollable pill row (scrolls inside its card,
     no body overflow) instead of a tall stacked list pushing content down. */
  .set-nav { flex-direction: row; overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 6px; padding-bottom: 4px; scrollbar-width: none; }
  .set-nav::-webkit-scrollbar { display: none; }
  .set-item { white-space: nowrap; flex: 0 0 auto; }
}

/* Phone */
@media (max-width: 600px) {
  /* Beat the cards' inline padding (28px eats too much of a 360px viewport). */
  .grid > .card { padding: 16px !important; }
  /* Full-width, comfortably tappable primary Save button. */
  .page-header .btn { width: 100%; min-height: 44px; justify-content: center; }
  /* Number input + long hint span: allow the hint to drop below the field. */
  .policy-card .form-group > div { flex-wrap: wrap; align-items: flex-start !important; }
  /* Tap targets. */
  .set-item { padding: 12px 14px; }
  .toggle { min-height: 44px; }
  .toggle input { width: 18px; height: 18px; }
}
</style>
