<script setup>
/**
 * Signup.vue — provider onboarding wizard.
 *
 * Three simple steps:
 *   1. Business details  → the email is PROVED first (POST /business/email/send-otp,
 *      then /verify-otp), and only then does POST /api/business/register create the
 *      user + businesses row + provider_admin + Sanctum token (stored in localStorage).
 *      Taking the proof before the write means `users.email_verified_at` is true from
 *      the moment the row exists — there is no unconfirmed-provider state to clean up.
 *   2. Set up website    → PUT /api/business/brand (Laravel) saves brand_* fields.
 *      Skippable — Skip advances without writing; finish later in My Website.
 *   3. Done              → welcome + the live website link → admin login (email prefilled).
 *
 * Kept deliberately light: only step 1 is required; step 2 pre-fills smart defaults
 * (brand name = business name, subdomain = username, teal theme).
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { registerBusiness, checkUsername as apiCheckUsername, loadCountries, updateBusinessBrand, firstApiError, getApiToken, clearApiToken, sendEmailOtp, verifyEmailOtp } from '../lib/businessAuth.js'
import { uploadImage } from '../lib/imageUpload.js'
import { brandPreviewUrl, brandHost, DEFAULT_PRIMARY, DEFAULT_ACCENT } from '../lib/brand.js'
import { BRAND_PALETTES as colourPalettes } from '../lib/brandPalettes.js'
import { Eye, EyeOff, Loader, Check, ArrowRight, ArrowLeft, Globe, Upload, X, Rocket, Copy, ExternalLink, RotateCcw, Home, Dumbbell, GraduationCap, Sparkles, Mail, ShieldCheck, MapPin, CalendarCheck } from 'lucide-vue-next'
import BrandMark from '../components/BrandMark.vue'
import { adminBrand, adminLogoSquareStyle } from '../lib/adminBrand.js'
import MultiSelect from '../components/MultiSelect.vue'
import { BUSINESS_TYPES } from '../lib/businessTypes.js'
import { adminLoginUrl } from '../lib/crossApp.js'

const accountPanel = ref(1)
const attempted = ref(false)
const formElement = ref(null)
const progressStep = computed(() => step.value === 1 ? accountPanel.value : step.value + 1)
const signupSteps = ['Your business', 'Your account', 'Your website', 'Ready to set up']
const step = ref(1)   // 1 = business · 2 = website · 3 = done

// ── Step 1: business details ──────────────────────────────────────────────────
const fullName     = ref('')
const businessName = ref('')
const email        = ref('')
const password     = ref('')
const countryId    = ref('')
const showPw       = ref(false)
const countries    = ref([])
const creating     = ref(false)
const err1         = ref('')

onMounted(async () => {
  const res = await loadCountries()
  countries.value = res.success ? (res.data?.countries || []) : []
})

// ── Email ownership, proved BEFORE the account is created ────────────────────
// The code is checked here on step 1 rather than after signing up, so a provider row
// is never written for an address nobody can read. registerBusiness() then carries the
// token this produces; without it the API refuses to create anything.
const otp            = ref('')
const otpSent        = ref(false)
const otpSending     = ref(false)
const otpChecking    = ref(false)
const emailVerified  = ref(false)
const emailToken     = ref('')
const otpErr         = ref('')
const otpNote        = ref('')
const resendIn       = ref(0)       // cooldown seconds remaining
let resendTimer

const emailLooksValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim()))

function startResendCountdown(seconds = 60) {
  clearInterval(resendTimer)
  resendIn.value = seconds
  resendTimer = setInterval(() => {
    if (--resendIn.value <= 0) clearInterval(resendTimer)
  }, 1000)
}
onBeforeUnmount(() => { clearInterval(resendTimer); clearTimeout(userTimer) })

// Editing the address throws away everything proved about the old one — otherwise a
// visitor could verify one mailbox and register a different address with the token.
watch(email, () => {
  clearInterval(resendTimer); resendIn.value = 0
  otpSent.value = false
  emailVerified.value = false
  emailToken.value = ''
  otp.value = ''
  otpErr.value = ''
  otpNote.value = ''
})

async function requestOtp() {
  otpErr.value = ''
  otpNote.value = ''
  if (!emailLooksValid.value) { otpErr.value = 'Enter a valid email address first.'; return }
  otpSending.value = true
  const requestedEmail = email.value.trim()
  const res = await sendEmailOtp(requestedEmail)
  otpSending.value = false
  if (email.value.trim() !== requestedEmail) return
  if (!res.success) {
    const wait = Number(res.errors?.retry_after || res.data?.retry_after || 0)
    if (wait > 0) startResendCountdown(wait)
    otpErr.value = firstApiError(res, 'Could not send the code — please try again.')
    return
  }
  otpSent.value = true
  otpNote.value = res.data?.workspace_code
    ? `Workspace verification code: ${res.data.workspace_code} · Email delivery is disabled here.`
    : `Code sent to ${email.value.trim()}`
  startResendCountdown(Number(res.data?.resend_after) || 60)
}

async function confirmOtp() {
  otpErr.value = ''
  if (otp.value.length !== 6) { otpErr.value = 'Enter the 6-digit code from your email.'; return }
  if (otpChecking.value) return
  otpChecking.value = true
  const requestedEmail = email.value.trim()
  const res = await verifyEmailOtp(requestedEmail, otp.value)
  otpChecking.value = false
  if (email.value.trim() !== requestedEmail) return
  if (!res.success) {
    otpErr.value = firstApiError(res, 'That code is not right.')
    return
  }
  emailToken.value = res.data?.email_verification_token || ''
  emailVerified.value = !!emailToken.value
  otpNote.value = ''
  clearInterval(resendTimer); resendIn.value = 0
}

/** Digits only, and auto-submit the moment six are in — nobody wants to press Verify. */
/** Verified locks the field; this is the way back out of it. */
function changeEmail() {
  emailVerified.value = false
  emailToken.value = ''
  otpSent.value = false
  otp.value = ''
  otpErr.value = ''
  otpNote.value = ''
  clearInterval(resendTimer); resendIn.value = 0
}

function onOtpInput(e) {
  otp.value = (e.target.value || '').replace(/\D/g, '').slice(0, 6)
  otpErr.value = ''
  if (otp.value.length === 6) confirmOtp()
}

// ── Business username = the PERMANENT web address (username.bookboll.com) ─────
// Picked once here; locked afterwards (wizard step 2 + My Website show it read-only).
const username     = ref('')
const userStatus   = ref('')   // '' | 'checking' | 'ok' | 'taken' | 'invalid'
const businessTypes = ref([])  // multi-select — what kind of business this is
const typeOptions = BUSINESS_TYPES.map(t => ({ value: t.key, label: t.label }))

function normUser() {
  username.value = (username.value || '').toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 30)
}
let userTimer
watch(username, (v) => {
  userStatus.value = ''
  clearTimeout(userTimer)
  if (!v) return
  if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(v)) { userStatus.value = 'invalid'; return }
  userStatus.value = 'checking'
  userTimer = setTimeout(checkUsername, 400)
})
async function checkUsername() {
  const slug = username.value
  if (!slug || userStatus.value === 'invalid') return
  const res = await apiCheckUsername(slug)
  if (slug !== username.value) return
  if (!res.success) { userStatus.value = 'unavailable'; return } // registration still performs the authoritative check
  userStatus.value = res.data?.available ? 'ok' : 'taken'
}

const fieldErrors = computed(() => ({
  fullName: fullName.value.trim() ? '' : 'Enter your full name.',
  businessName: businessName.value.trim() ? '' : 'Enter your business name.',
  username: !username.value.trim() ? 'Choose a web address.' : userStatus.value === 'taken' ? 'This address is taken. Try another.' : userStatus.value === 'invalid' ? 'Use letters, numbers and hyphens, with a letter or number at each end.' : '',
  businessTypes: businessTypes.value.length ? '' : 'Choose at least one business type.',
  email: emailLooksValid.value ? '' : 'Enter a valid email address.',
  password: password.value.length >= 8 ? '' : 'Use at least 8 characters.',
}))
function invalid(key) { return attempted.value && !!fieldErrors.value[key] }
async function focusInvalid() {
  await nextTick()
  const el = formElement.value?.querySelector('[aria-invalid="true"]')
  el?.scrollIntoView({ block: 'center', behavior: 'auto' })
  el?.focus({ preventScroll: true })
}
function continueBusiness() {
  attempted.value = true
  if (['businessName','username','businessTypes'].some(key => fieldErrors.value[key])) { focusInvalid(); return }
  if (userStatus.value === 'checking') { err1.value = 'Checking your web address. Please try again in a moment.'; return }
  err1.value = ''; attempted.value = false; accountPanel.value = 2
  nextTick(() => formElement.value?.querySelector('input')?.focus())
}
async function createAccount() {
  if (creating.value) return
  attempted.value = true
  if (Object.values(fieldErrors.value).some(Boolean)) { focusInvalid(); return }
  err1.value = ''
  if (!fullName.value.trim())     { err1.value = 'Please enter your full name.'; return }
  if (!businessName.value.trim()) { err1.value = 'Please enter your business name.'; return }
  if (!username.value.trim())     { err1.value = 'Pick your business username — it becomes your web address.'; return }
  if (userStatus.value === 'invalid') { err1.value = 'Usernames can only use letters, numbers and hyphens.'; return }
  if (userStatus.value === 'taken')   { err1.value = 'That username is already taken — try another.'; return }
  if (userStatus.value === 'checking') { err1.value = 'Still checking your username — one second, then try again.'; return }
  if (!businessTypes.value.length) { err1.value = 'Select what kind of business you run (you can pick several).'; return }
  if (!email.value.trim())        { err1.value = 'Please enter your email address.'; return }
  if (!emailVerified.value)       { err1.value = 'Verify your email address before creating your account.'; return }
  if (password.value.length < 8)  { err1.value = 'Password must be at least 8 characters.'; return }

  creating.value = true
  const res = await registerBusiness({
    name: fullName.value.trim(),
    business_name: businessName.value.trim(),
    username: username.value.trim(),
    business_types: [...businessTypes.value],
    email: email.value.trim(),
    password: password.value,
    password_confirmation: password.value,
    country_id: countryId.value ? Number(countryId.value) : null,
    email_verification_token: emailToken.value,
  })
  creating.value = false
  if (!res.success) {
    err1.value = firstApiError(res, 'Sign up failed — please try again.')
    return
  }

  // Pre-fill the website step. The web address IS the username — locked from here on.
  brandName.value = businessName.value.trim()
  subdomain.value = username.value.trim()
  step.value = 2
}

// ── Step 2: website setup ─────────────────────────────────────────────────────
const brandName   = ref('')
const subdomain   = ref('')   // = the business username; LOCKED (shown read-only in step 2)
const brandColor  = ref(DEFAULT_PRIMARY)   // primary
const accentColor = ref(DEFAULT_ACCENT)    // accent (gradient + nav highlight)
const tagline     = ref('')
const logoUrl    = ref('')
const logoBusy   = ref(false)
const logoInput  = ref(null)
const saving     = ref(false)
const err2       = ref('')

function resetColors() { brandColor.value = DEFAULT_PRIMARY; accentColor.value = DEFAULT_ACCENT }

// Preview nav — illustrative. The real site shows ONLY the modules the provider has
// content for (venues if they add listings, memberships if they add programs); events
// and loyalty/profile features never appear on a white-label site.
const previewNav = [
  { icon: Home, label: 'Home' },
  { icon: Dumbbell, label: 'Venues' },
  { icon: GraduationCap, label: 'Memberships' },
]

const liveHost   = computed(() => brandHost(subdomain.value || 'yourbusiness'))

function pickLogo() { logoInput.value?.click() }
async function onLogo(e) {
  const file = e.target.files?.[0]
  if (!file) return
  err2.value = ''
  logoBusy.value = true
  const r = await uploadImage(file, 'business')
  logoBusy.value = false
  if (r.error) { err2.value = r.error; return }
  logoUrl.value = r.url
}
function removeLogo() { logoUrl.value = ''; if (logoInput.value) logoInput.value.value = '' }

async function finishWebsite() {
  err2.value = ''
  if (!brandName.value.trim()) {
    err2.value = 'Please enter a website / brand name.'
    return
  }
  if (!getApiToken()) {
    err2.value = 'Your session expired — go back and create your account again.'
    return
  }

  if (logoBusy.value || saving.value) return
  if (![brandColor.value, accentColor.value].every(color => /^#[0-9a-f]{6}$/i.test(color))) {
    err2.value = 'Choose valid colours or reset to Bookboll teal.'
    return
  }
  saving.value = true
  const res = await updateBusinessBrand({
    brand_name: brandName.value.trim(),
    brand_tagline: tagline.value.trim() || null,
    brand_logo_url: logoUrl.value.trim() || null,
    brand_primary: brandColor.value || null,
    brand_accent: accentColor.value || null,
  })
  saving.value = false

  if (!res.success) {
    err2.value = firstApiError(res, 'Could not save your website settings — please try again.')
    return
  }

  step.value = 3
}

function skipWebsite() { step.value = 3 }

// ── Step 3: done ──────────────────────────────────────────────────────────────
const finalUrl = computed(() => subdomain.value ? brandPreviewUrl(subdomain.value) : '')
async function copyUrl() {
  if (!finalUrl.value) return
  try { await navigator.clipboard.writeText(finalUrl.value) } catch { /* ignore */ }
}
function openUrl() { if (finalUrl.value) window.open(finalUrl.value, '_blank', 'noopener') }
async function goDashboard() {
  // The signup token has done its job (it only authorises step 2's brand save).
  // The admin app signs people in itself and cannot take this token, so keeping
  // it here just leaves this origin believing it holds a session it can't use —
  // which is what previously made /signup unreachable on a second visit.
  clearApiToken()
  window.location.href = adminLoginUrl(email.value ? `email=${encodeURIComponent(email.value)}` : '')
}
</script>

<template>
  <div class="auth-shell" :class="{ 'website-step': step === 2 }">
    <aside class="ob-sidebar">
      <router-link to="/" class="ob-back"><ArrowLeft :size="16" /> Bookboll for Business</router-link>
      <div><h2>Let's get your<br />business ready.</h2><p>Your booking website and business tools, together in one account.</p></div>
      <ol aria-label="Signup progress"><li v-for="(label,i) in signupSteps" :key="label" :class="{ current: progressStep === i + 1, complete: progressStep > i + 1 }" :aria-current="progressStep === i + 1 ? 'step' : undefined"><span><Check v-if="progressStep > i + 1" :size="16" /><template v-else>{{ i + 1 }}</template></span><div><strong>{{ label }}</strong><small>{{ ['Business type, country and web address', 'Your details and verified email', 'Logo and colours; you can do this later', 'Add listings from your dashboard'][i] }}</small></div></li></ol>
      <div class="ob-sidebar-note"><ShieldCheck :size="20" /><p>No setup or monthly fee on Free.<br />Booking commissions apply.</p></div>
    </aside>
    <div class="auth-card" :class="{ wide: step === 2 }">
      <!-- Brand -->
      <div class="auth-brand">
        <div class="auth-logo" :style="adminBrand.logo ? { backgroundImage: `url(${adminBrand.logo})`, backgroundSize: 'cover', backgroundPosition: 'center' } : adminLogoSquareStyle"><BrandMark v-if="!adminBrand.logo" /></div>
        <div>
          <div class="auth-brand-name">Bookboll</div>
          <div class="auth-brand-sub">Get your business online</div>
        </div>
      </div>

      <div class="ob-mobile-progress">Step {{ progressStep }} of 4 · {{ signupSteps[progressStep - 1] }}</div>
      <!-- ── STEP 1 — business details ─────────────────────────────────── -->
      <template v-if="step === 1">
        <h1 class="auth-heading">{{ accountPanel === 1 ? 'Tell us about your business' : 'Create your account' }}</h1>
        <p class="auth-sub">{{ accountPanel === 1 ? 'Choose how customers will find you. You can add locations and listings after signup.' : 'Verify your email to keep your business account secure.' }}</p>

        <form ref="formElement" @submit.prevent="accountPanel === 1 ? continueBusiness() : createAccount()" class="auth-form" novalidate>
          <template v-if="accountPanel === 1">
            <div class="auth-field"><label for="signup-business" class="auth-label">Business name</label><input id="signup-business" v-model="businessName" class="auth-input" placeholder="e.g. City Padel Club" autocomplete="organization" required :aria-invalid="invalid('businessName')" :aria-describedby="invalid('businessName') ? 'error-businessName' : undefined" /><span v-if="invalid('businessName')" id="error-businessName" class="ob-field-error">{{ fieldErrors.businessName }}</span></div>
            <div class="auth-field"><label for="signup-username" class="auth-label">Your booking website address</label><div class="ob-sub" :class="{ invalid: invalid('username') }"><input id="signup-username" v-model="username" @input="normUser" class="auth-input ob-sub-in" placeholder="yourclub" spellcheck="false" autocomplete="off" required :aria-invalid="invalid('username')" aria-describedby="signup-address-help" /><span class="ob-sub-suffix">.bookboll.com</span></div><div id="signup-address-help" class="ob-sub-status" aria-live="polite"><span v-if="invalid('username')" class="ob-bad">{{ fieldErrors.username }}</span><span v-else-if="userStatus === 'checking'" class="ob-muted">Checking availability…</span><span v-else-if="userStatus === 'ok'" class="ob-ok"><Check :size="13" /> Available</span><span v-else-if="userStatus === 'taken'" class="ob-bad">Already taken. Try another address.</span><span v-else class="ob-muted">Choose carefully. This address cannot be changed later.</span></div></div>
            <fieldset class="ob-type-field"><legend class="auth-label">What kind of business do you run?</legend><p>Select all that apply.</p><div class="ob-type-options"><label v-for="t in typeOptions" :key="t.value" :class="{ selected: businessTypes.includes(t.value) }"><input type="checkbox" v-model="businessTypes" :value="t.value" :aria-invalid="invalid('businessTypes')" :aria-describedby="invalid('businessTypes') ? 'error-businessTypes' : undefined" />{{ t.label }}</label></div><span v-if="invalid('businessTypes')" id="error-businessTypes" class="ob-field-error">{{ fieldErrors.businessTypes }}</span></fieldset>
            <div class="auth-field"><label for="signup-country" class="auth-label">Country <span class="auth-opt">(optional)</span></label><select id="signup-country" v-model="countryId" class="auth-input auth-select"><option value="">Select your country</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
          </template>
          <template v-else>
            <div class="ob-business-summary"><span><strong>{{ businessName }}</strong><small>{{ username }}.bookboll.com</small></span><button type="button" @click="accountPanel = 1; attempted = false; err1 = ''">Edit</button></div>
            <div class="auth-field"><label for="signup-name" class="auth-label">Your full name</label><input id="signup-name" v-model="fullName" class="auth-input" placeholder="Your first and last name" autocomplete="name" required :aria-invalid="invalid('fullName')" :aria-describedby="invalid('fullName') ? 'error-fullName' : undefined" /><span v-if="invalid('fullName')" id="error-fullName" class="ob-field-error">{{ fieldErrors.fullName }}</span></div>
          <!-- Email + proof. The address is confirmed here, before Continue, so no
               account is ever created for a mailbox nobody can open. -->
          <div class="auth-field">
            <label for="signup-email" class="auth-label">Email</label>
            <div class="ob-mail">
              <input id="signup-email" v-model="email" required :aria-invalid="invalid('email')" :aria-describedby="invalid('email') ? 'error-email' : undefined" type="email" class="auth-input ob-mail-in"
                     :class="{ 'is-verified': emailVerified }"
                     :readonly="emailVerified" placeholder="you@yourbusiness.com" autocomplete="email" />
              <span v-if="emailVerified" class="ob-mail-ok"><ShieldCheck :size="15" /> Verified</span>
              <button v-else type="button" class="ob-mail-btn"
                      :disabled="!emailLooksValid || otpSending || resendIn > 0"
                      @click="requestOtp">
                <Loader v-if="otpSending" :size="14" class="auth-spin" />
                <Mail v-else :size="14" />
                {{ otpSending ? 'Sending…' : resendIn > 0 ? `Resend in ${resendIn}s` : otpSent ? 'Resend code' : 'Verify email' }}
              </button>
            </div>

            <span v-if="invalid('email')" id="error-email" class="ob-field-error">{{ fieldErrors.email }}</span>
            <!-- The code box only appears once something has actually been sent. -->
            <div v-if="otpSent && !emailVerified" class="ob-otp">
              <div class="ob-otp-head">
                <span class="ob-otp-t">Enter the 6-digit code</span>
                <button type="button" class="ob-otp-edit" @click="otpSent = false; otp = ''">Change email</button>
              </div>
              <div class="ob-otp-row">
                <input aria-label="Email verification code" :value="otp" @input="onOtpInput" class="auth-input ob-otp-in"
                       inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                       placeholder="••••••" spellcheck="false" />
                <button type="button" class="ob-otp-btn" :disabled="otp.length !== 6 || otpChecking" @click="confirmOtp">
                  <Loader v-if="otpChecking" :size="14" class="auth-spin" />
                  <Check v-else :size="14" />
                  {{ otpChecking ? 'Checking…' : 'Confirm' }}
                </button>
              </div>
              <div class="ob-otp-foot">
                <span v-if="otpErr" class="ob-bad">{{ otpErr }}</span>
                <span v-else-if="otpNote" class="ob-ok"><Check :size="13" /> {{ otpNote }} — it expires in 10 minutes</span>
                <span v-else class="ob-muted">Check your inbox, and your spam folder.</span>
              </div>
            </div>
            <div v-else-if="emailVerified" class="ob-sub-status">
              <span class="ob-ok"><Check :size="13" /> This address is confirmed</span>
              <button type="button" class="ob-otp-edit" @click="changeEmail">Use a different email</button>
            </div>
            <div v-else-if="otpErr" class="ob-sub-status"><span class="ob-bad">{{ otpErr }}</span></div>
            <div v-else class="ob-sub-status"><span class="ob-muted">We'll send a 6-digit code to confirm this address.</span></div>
          </div>

            <div class="auth-field"><label for="signup-password" class="auth-label">Password</label><div class="auth-pw-wrap"><input id="signup-password" v-model="password" :type="showPw ? 'text' : 'password'" class="auth-input" placeholder="At least 8 characters" autocomplete="new-password" required :aria-invalid="invalid('password')" :aria-describedby="invalid('password') ? 'error-password' : undefined" /><button type="button" class="auth-pw-eye" :aria-label="showPw ? 'Hide password' : 'Show password'" @click="showPw = !showPw"><component :is="showPw ? EyeOff : Eye" :size="18" /></button></div><span v-if="invalid('password')" id="error-password" class="ob-field-error">{{ fieldErrors.password }}</span></div>
          </template>
          <div v-if="err1" class="auth-error" role="alert">{{ err1 }}</div>

          <p v-if="accountPanel === 2" class="auth-terms">By creating your account you agree to the <router-link to="/terms" class="auth-link">Provider Terms &amp; Conditions</router-link>.</p>

          <button type="submit" class="auth-btn" :disabled="creating">
            <Loader v-if="creating" :size="16" class="auth-spin" />
            {{ creating ? 'Creating account…' : accountPanel === 1 ? 'Continue to your account' : 'Create account' }}
            <ArrowRight v-if="!creating" :size="16" />
          </button>
        </form>

        <div class="auth-footer">
          Already have an account? <a :href="adminLoginUrl()" class="auth-link">Sign in</a>
        </div>
      </template>

      <!-- ── STEP 2 — website setup ────────────────────────────────────── -->
      <template v-else-if="step === 2">
        <h1 class="auth-heading">Make it your website</h1>
        <p class="auth-sub">Add your name, logo and colours. Your preview updates as you go, and everything can be changed later.</p>

        <div class="ob-grid">
          <!-- Form side -->
          <div class="ob-form">
            <section class="ob-brand-group" aria-labelledby="ob-identity-title">
              <h2 id="ob-identity-title">Your business identity</h2>
              <p>The name and logo customers will recognise.</p>
            <!-- Logo & name -->
            <div class="ob-logo-row">
              <div class="ob-logo-prev" :class="{ img: logoUrl }" :style="logoUrl ? { backgroundImage: `url(${logoUrl})` } : { background: `linear-gradient(135deg, ${accentColor}, ${brandColor})` }">
                <span v-if="!logoUrl">{{ (brandName || 'Y').trim().charAt(0).toUpperCase() }}</span>
              </div>
              <div class="ob-logo-actions">
                <input ref="logoInput" type="file" accept="image/*" class="ob-file" @change="onLogo" />
                <button type="button" class="ob-logo-btn" @click="pickLogo" :disabled="logoBusy">
                  <Loader v-if="logoBusy" :size="15" class="auth-spin" /><Upload v-else :size="15" />
                  {{ logoBusy ? 'Uploading…' : (logoUrl ? 'Replace logo' : 'Upload logo') }}
                </button>
                <button v-if="logoUrl" type="button" class="ob-logo-x" @click="removeLogo"><X :size="13" /> Remove</button>
              </div>
            </div>

            <div class="auth-field">
              <label for="website-name" class="auth-label">Website name</label>
              <input id="website-name" aria-label="Website name" v-model="brandName" type="text" class="auth-input" placeholder="Your business name" maxlength="60" />
            </div>

            <div class="auth-field">
              <label class="auth-label">Tagline <span class="auth-opt">(optional)</span></label>
              <input aria-label="Tagline" v-model="tagline" type="text" class="auth-input" placeholder="e.g. Book courts & academies in seconds" maxlength="90" />
            </div>

            </section>
            <section class="ob-brand-group" aria-labelledby="ob-colours-title">
              <h2 id="ob-colours-title">Make it yours</h2>
              <p>Try Qatar’s burgundy, desert sands or oasis greens. You can also use your own colours.</p>
              <div class="ob-palettes" aria-label="Brand colour palettes">
                <button v-for="palette in colourPalettes" :key="palette.name" type="button" :aria-pressed="brandColor === palette.primary && accentColor === palette.accent" @click="brandColor = palette.primary; accentColor = palette.accent">
                  <span :style="{ background: palette.primary }"></span><span :style="{ background: palette.accent }"></span>{{ palette.name }}
                </button>
              </div>
            <!-- Brand colours -->
            <div class="auth-field">
              <span class="auth-label">Custom colours</span>
              <div class="ob-colors">
                <div class="ob-color">
                  <input type="color" class="ob-swatch" aria-label="Primary colour" v-model="brandColor" />
                  <div class="ob-color-meta"><span>Primary</span><input class="auth-input ob-hex" aria-label="Primary colour hex code" v-model="brandColor" spellcheck="false" maxlength="7" /></div>
                </div>
                <div class="ob-color">
                  <input type="color" class="ob-swatch" aria-label="Accent colour" v-model="accentColor" />
                  <div class="ob-color-meta"><span>Accent</span><input class="auth-input ob-hex" aria-label="Accent colour hex code" v-model="accentColor" spellcheck="false" maxlength="7" /></div>
                </div>
              </div>
              <button type="button" class="ob-reset" @click="resetColors"><RotateCcw :size="12" /> Reset to Bookboll teal</button>
            </div>

            </section>
          </div>

          <!-- Live preview side — a storefront mock reflecting every choice -->
          <div class="ob-preview" :style="{ '--pv': brandColor || DEFAULT_PRIMARY, '--pa': accentColor || DEFAULT_ACCENT }">
            <div class="ob-pv-label"><Eye :size="16" /> Your website preview<span>Example</span></div>
            <div class="ob-pv-card">
              <div class="ob-pv-chrome"><span></span><span></span><span></span><div class="ob-pv-addr"><Globe :size="10" /> {{ liveHost }}</div></div>
              <div class="ob-pv-bar">
                <div class="ob-pv-logo">
                  <img v-if="logoUrl" :src="logoUrl" alt="" />
                  <span v-else>{{ (brandName || 'Y').trim().charAt(0).toUpperCase() }}</span>
                </div>
                <span class="ob-pv-name">{{ brandName || 'Your business' }}</span>
                <span class="ob-pv-signin">Sign in</span>
              </div>
              <div class="ob-pv-nav">
                <span v-for="n in previewNav" :key="n.label" class="ob-pv-navlink" :class="{ active: n.label === 'Home' }"><component :is="n.icon" :size="11" /> {{ n.label }}</span>
              </div>
              <div class="ob-pv-hero">
                <div class="ob-pv-h1">{{ tagline || 'Book with ' + (brandName || 'us') }}</div>
                <div class="ob-pv-cta">Book now</div>
              </div>
              <div class="ob-pv-cards">
                <div class="ob-pv-tile"><div class="ob-pv-tile-img"></div><div class="ob-pv-tile-l"></div><div class="ob-pv-tile-s"></div></div>
                <div class="ob-pv-tile"><div class="ob-pv-tile-img"></div><div class="ob-pv-tile-l"></div><div class="ob-pv-tile-s"></div></div>
              </div>
            </div>
            <div class="ob-site-address"><Globe :size="18" /><div><strong>Your reserved web address</strong><span>{{ liveHost }}</span><small>Connect your own domain later in My Website.</small></div></div>
            <p class="ob-preview-note"><Sparkles :size="16" /> Your listings and memberships appear here when you add them in your dashboard.</p>
          </div>
        </div>

        <div v-if="err2" class="auth-error" role="alert">{{ err2 }}</div>

        <div class="ob-actions">
          <button type="button" class="auth-btn-ghost" :disabled="saving || logoBusy" @click="skipWebsite">Skip for now</button>
          <button type="button" class="auth-btn" :disabled="saving || logoBusy" @click="finishWebsite">
            <Loader v-if="saving" :size="16" class="auth-spin" />
            {{ saving ? 'Saving…' : 'Save website & continue' }}
            <ArrowRight v-if="!saving" :size="16" />
          </button>
        </div>
      </template>

      <!-- ── STEP 3 — done ─────────────────────────────────────────────── -->
      <template v-else>
        <div class="success-state">
          <div class="success-icon"><Rocket :size="28" /></div>
          <h1 class="success-title">Your account is ready.</h1>
          <p class="success-body">
            Your business account is ready. Your dashboard will walk you through the rest:
            add your <b>location &amp; first listing</b>, connect a <b>payout method</b>, and go live.
          </p>

          <ol class="ob-next-steps"><li><MapPin :size="19" /><span><strong>Add your location</strong><small>Show customers where to find you.</small></span></li><li><CalendarCheck :size="19" /><span><strong>Create a listing or membership</strong><small>Set availability, prices and payment options.</small></span></li><li><Globe :size="19" /><span><strong>Review and publish</strong><small>Your dashboard guides you through the remaining setup.</small></span></li></ol>
          <div v-if="finalUrl" class="ob-done-site">
            <div class="ob-done-site-l"><Globe :size="16" /> <span>{{ finalUrl }}</span></div>
            <div class="ob-done-site-actions">
              <button type="button" class="auth-btn-ghost sm" @click="copyUrl"><Copy :size="14" /> Copy</button>
              <button type="button" class="auth-btn-ghost sm" @click="openUrl"><ExternalLink :size="14" /> Open</button>
            </div>
          </div>

          <button type="button" class="auth-btn" @click="goDashboard">
            Go to my dashboard <ArrowRight :size="16" />
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.auth-shell {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background:
    radial-gradient(900px 520px at 12% -10%, rgba(20,184,166,.20), transparent 60%),
    radial-gradient(760px 480px at 100% 110%, rgba(13,148,136,.22), transparent 62%),
    linear-gradient(135deg, #0b1220 0%, #16233a 58%, #0f766e22 100%);
  padding: 40px 24px;
}
/* 640px rather than 520: step 1 is a seven-field form, and the extra 120px is what
   lets the paired rows (name/business, country/password) breathe instead of reading
   as two cramped columns. Step 2 still widens further for the live site preview. */
.auth-card {
  width: 100%; max-width: 640px; background: var(--surface, #ffffff); border-radius: 22px;
  padding: 38px 44px 32px;
  border: 1px solid rgba(255,255,255,.08);
  box-shadow: 0 1px 0 rgba(255,255,255,.6) inset, 0 30px 70px rgba(2,6,23,.45);
  transition: max-width .22s ease;
}
.auth-card.wide { max-width: 1020px; }
.auth-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
.auth-logo {
  width: 44px; height: 44px; border-radius: 12px;
  background: linear-gradient(135deg, #14b8a6, #0f766e);
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 16px;
}
.auth-brand-name { font-size: 18px; font-weight: 700; color: #0f172a; }
.auth-brand-sub  { font-size: 12px; color: var(--text-muted, #64748b); }

/* Progress */
.ob-steps { display: flex; align-items: center; gap: 10px; margin-bottom: 22px; }
.ob-step { display: flex; align-items: center; gap: 8px; }
.ob-dot {
  width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800; background: var(--border, #e2e8f0); color: var(--text-light, #94a3b8);
}
.ob-step.on .ob-dot { background: #0f766e; color: #fff; }
.ob-step.done .ob-dot { background: #14b8a6; color: #fff; }
.ob-step-l { font-size: 13px; font-weight: 700; color: var(--text-light, #94a3b8); }
.ob-step.on .ob-step-l, .ob-step.done .ob-step-l { color: #0f172a; }
.ob-line { flex: 1; height: 2px; background: var(--border, #e2e8f0); border-radius: 2px; }
.ob-line.done { background: #14b8a6; }

.auth-heading { font-size: 25px; font-weight: 800; color: #0f172a; margin: 0 0 5px; letter-spacing: -.01em; }
.auth-sub { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 0 0 24px; line-height: 1.55; }

.auth-form { display: flex; flex-direction: column; gap: 16px; }
.auth-row  { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 560px) { .auth-row { grid-template-columns: 1fr; } }

.auth-field { display: flex; flex-direction: column; gap: 6px; }
.auth-label { font-size: 13px; font-weight: 600; color: #374151; }
.auth-opt { font-weight: 500; color: var(--text-light, #94a3b8); }
.auth-input {
  width: 100%; padding: 12px 14px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 11px;
  font-size: 14.5px; color: #0f172a; background: #fbfdfe; box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.auth-input:hover:not(:focus):not([readonly]) { border-color: #cbd5e1; }
.auth-input:focus { background: var(--surface, #ffffff); }
.auth-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20,184,166,.12); }
.auth-select { cursor: pointer; }

.auth-pw-wrap { position: relative; }
.auth-pw-wrap .auth-input { padding-right: 42px; }
.auth-pw-eye {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); display: flex;
}
.auth-pw-eye:hover { color: #0f172a; }

.auth-hint { margin-top: 6px; font-size: 12px; line-height: 1.4; color: var(--text-muted, #64748b); }

/* ── Email verification (step 1) ────────────────────────────────────────────── */
.ob-mail { display: flex; gap: 8px; align-items: stretch; }
.ob-mail-in { flex: 1; min-width: 0; }
.ob-mail-in.is-verified { border-color: #5eead4; background: #f0fdfa; color: #0f766e; font-weight: 600; }
.ob-mail-btn {
  flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
  padding: 0 15px; border: 1.5px solid #99f6e4; border-radius: 11px;
  background: #f0fdfa; color: #0f766e; font-size: 13.5px; font-weight: 700; cursor: pointer;
  transition: background .15s, border-color .15s, opacity .15s;
}
.ob-mail-btn:hover:not(:disabled) { background: #ccfbf1; border-color: #5eead4; }
.ob-mail-btn:disabled { opacity: .55; cursor: not-allowed; }
.ob-mail-ok {
  flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
  padding: 0 14px; border-radius: 11px; background: #ecfdf5; border: 1.5px solid #a7f3d0;
  color: #047857; font-size: 13.5px; font-weight: 800;
}

/* The code box: a panel, so it reads as one task rather than another loose field. */
.ob-otp {
  margin-top: 10px; padding: 14px; border-radius: 13px;
  border: 1.5px solid #99f6e4; background: linear-gradient(180deg, #f0fdfa, #ffffff);
}
.ob-otp-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 9px; }
.ob-otp-t { font-size: 13px; font-weight: 800; color: #0f766e; }
.ob-otp-edit { border: none; background: none; padding: 0; font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); cursor: pointer; text-decoration: underline; }
.ob-otp-edit:hover { color: #0f172a; }
.ob-otp-row { display: flex; gap: 8px; }
.ob-otp-in {
  flex: 1; min-width: 0; text-align: center;
  font-size: 22px; font-weight: 800; letter-spacing: .42em; text-indent: .42em;
  padding: 11px 14px; background: #fff;
}
.ob-otp-btn {
  flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
  padding: 0 18px; border: none; border-radius: 11px;
  background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff;
  font-size: 14px; font-weight: 700; cursor: pointer; transition: opacity .15s;
}
.ob-otp-btn:disabled { opacity: .5; cursor: not-allowed; }
.ob-otp-foot { margin-top: 9px; font-size: 12.5px; line-height: 1.45; }
.ob-mail + .ob-sub-status { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
@media (max-width: 560px) {
  .ob-mail { flex-wrap: wrap; }
  .ob-mail-btn, .ob-mail-ok { width: 100%; justify-content: center; padding: 11px 15px; }
  .ob-otp-row { flex-direction: column; }
  .ob-otp-btn { padding: 12px 18px; justify-content: center; }
}
.auth-error { font-size: 13px; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 12px; margin-top: 14px; }

.auth-btn {
  padding: 13px 18px; border: none; border-radius: 11px;
  background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff;
  font-size: 15px; font-weight: 700; cursor: pointer; display: flex;
  align-items: center; justify-content: center; gap: 8px;
  box-shadow: 0 6px 18px rgba(13,148,136,.28);
  transition: opacity .15s, transform .12s, box-shadow .15s;
}
.auth-btn:active:not(:disabled) { transform: translateY(1px); }
.auth-btn:disabled { box-shadow: none; }
.auth-form .auth-btn { width: 100%; margin-top: 4px; }
.auth-btn:hover:not(:disabled) { opacity: .92; }
.auth-btn:disabled { opacity: .6; cursor: not-allowed; }
.auth-btn-ghost {
  padding: 12px 18px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff);
  color: #475569; font-size: 14.5px; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
}
.auth-btn-ghost:hover { border-color: #cbd5e1; color: #0f172a; }
.auth-btn-ghost.sm { padding: 8px 12px; font-size: 13px; }
.auth-spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.auth-footer { margin-top: 20px; text-align: center; font-size: 13.5px; color: var(--text-muted, #64748b); }
.auth-terms { margin: 12px 0 0; text-align: center; font-size: 12px; color: var(--text-light, #94a3b8); line-height: 1.5; }
.auth-link { color: #0f766e; font-weight: 600; text-decoration: none; }
.auth-link:hover { text-decoration: underline; }

/* ── Step 2 layout ── */
.ob-grid { display: grid; grid-template-columns: 1fr 380px; gap: 28px; align-items: start; }
@media (max-width: 720px) {
  .ob-grid { grid-template-columns: 1fr; }
  .ob-preview { position: static; }
}
.ob-form { display: flex; flex-direction: column; gap: 14px; }

.ob-sub { display: flex; align-items: stretch; }
/* Locked web-address display (the address = the business username, fixed at signup) */
.ob-sub-locked { flex: 1; display: flex; align-items: center; gap: 7px; padding: 10px 14px; background: #f8fafc; border: 1.5px solid var(--border, #e2e8f0); border-right: none; border-radius: 10px 0 0 10px; font-size: 14px; font-weight: 700; color: #0f172a; }
.ob-sub-locked svg { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.ob-lock-ok { color: #16a34a; vertical-align: -2px; }
.ob-sub-in { border-top-right-radius: 0; border-bottom-right-radius: 0; }
.ob-sub-suffix {
  display: flex; align-items: center; padding: 0 12px; font-size: 13px; font-weight: 600;
  color: var(--text-muted, #64748b); background: #f1f5f9; border: 1.5px solid var(--border, #e2e8f0); border-left: none;
  border-top-right-radius: 10px; border-bottom-right-radius: 10px; white-space: nowrap;
}
.ob-sub-status { font-size: 12.5px; min-height: 16px; }
.ob-muted { color: var(--text-light, #94a3b8); }
.ob-ok { color: #16a34a; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.ob-bad { color: #dc2626; font-weight: 700; }

/* Logo & name */
.ob-logo-row { display: flex; align-items: center; gap: 14px; }
.ob-logo-prev { width: 56px; height: 56px; border-radius: 13px; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px; flex-shrink: 0; background-size: cover; background-position: center; }
.ob-logo-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ob-file { display: none; }
.ob-logo-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  height: 38px; padding: 0 14px; border: 1.5px solid #99f6e4; border-radius: 10px; background: #f0fdfa;
  color: #0f766e; font-size: 13.5px; font-weight: 700; cursor: pointer;
}
.ob-logo-btn:hover:not(:disabled) { background: #ccfbf1; }
.ob-logo-btn:disabled { opacity: .6; cursor: default; }
.ob-logo-x { background: none; border: none; color: #dc2626; font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }

/* Brand colours */
/* Collapse-proof (same recipe as My Website's Brand colors card): minmax(0,1fr)
   tracks + min-width:0 so the hex inputs can never blow the grid wider than
   the signup column; swatches hard-locked square with UA chrome stripped. */
.ob-colors { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.ob-color { display: flex; gap: 8px; align-items: center; min-width: 0; }
.ob-swatch { width: 40px; min-width: 40px; max-width: 40px; height: 40px; flex: 0 0 40px; padding: 0; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: none; cursor: pointer; appearance: none; -webkit-appearance: none; }
.ob-swatch::-webkit-color-swatch-wrapper { padding: 3px; }
.ob-swatch::-webkit-color-swatch { border: none; border-radius: 6px; }
.ob-swatch::-moz-color-swatch { border: none; border-radius: 6px; }
.ob-color-meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1 1 0; }
.ob-color-meta > span { font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .03em; }
.ob-hex { text-transform: lowercase; padding: 6px 8px; font-size: 12.5px; width: 100%; min-width: 0; box-sizing: border-box; }
.ob-reset { margin-top: 8px; background: none; border: none; color: #0f766e; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 0; }
.ob-reset:hover { text-decoration: underline; }

/* Auto-modules note */
.ob-auto { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 10px; padding: 10px 12px; line-height: 1.5; }
.ob-auto svg { flex-shrink: 0; margin-top: 1px; }

/* Sections to show (legacy, unused) */
.ob-mods { display: flex; flex-direction: column; gap: 8px; }
.ob-mod { display: flex; align-items: center; gap: 11px; padding: 9px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; }
.ob-mod-ico { width: 30px; height: 30px; border-radius: 8px; background: #f0fdfa; color: #0f766e; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ob-mod-t { flex: 1; min-width: 0; }
.ob-mod-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.ob-mod-desc { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.ob-switch { position: relative; display: inline-block; width: 40px; height: 23px; flex-shrink: 0; }
.ob-switch input { opacity: 0; width: 0; height: 0; }
.ob-slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 999px; transition: .2s; }
.ob-slider::before { content: ''; position: absolute; height: 17px; width: 17px; left: 3px; top: 3px; background: var(--surface, #ffffff); border-radius: 50%; transition: .2s; }
.ob-switch input:checked + .ob-slider { background: #0f766e; }
.ob-switch input:checked + .ob-slider::before { transform: translateX(17px); }

/* Live preview — storefront mock */
.ob-preview { background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px; position: sticky; top: 14px; }
.ob-pv-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 10px; }
.ob-pv-card { border: 1px solid var(--border, #e2e8f0); border-radius: 12px; overflow: hidden; background: var(--surface, #ffffff); box-shadow: 0 8px 20px rgba(15,23,42,.06); }
.ob-pv-chrome { display: flex; align-items: center; gap: 5px; padding: 7px 10px; background: #f1f5f9; border-bottom: 1px solid #e8edf2; }
.ob-pv-chrome > span { width: 7px; height: 7px; border-radius: 50%; background: #cbd5e1; }
.ob-pv-addr { flex: 1; display: flex; align-items: center; gap: 4px; margin-left: 6px; background: var(--surface, #ffffff); border-radius: 5px; padding: 3px 8px; font-size: 9.5px; color: var(--text-light, #94a3b8); overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ob-pv-bar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
.ob-pv-logo { width: 26px; height: 26px; border-radius: 7px; background: linear-gradient(135deg, var(--pa), var(--pv)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; overflow: hidden; flex-shrink: 0; }
.ob-pv-logo img { width: 100%; height: 100%; object-fit: cover; }
.ob-pv-name { font-size: 13.5px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.ob-pv-signin { font-size: 10px; font-weight: 800; color: #fff; background: var(--pv); border-radius: 999px; padding: 3px 9px; flex-shrink: 0; }
.ob-pv-nav { display: flex; gap: 3px; padding: 0 8px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; }
.ob-pv-navlink { display: inline-flex; align-items: center; gap: 3px; padding: 7px 7px; font-size: 10px; font-weight: 700; color: var(--text-light, #94a3b8); border-bottom: 2px solid transparent; }
.ob-pv-navlink.active { color: var(--pv); border-bottom-color: var(--pa); }
.ob-pv-hero { padding: 20px 14px; background: linear-gradient(135deg, var(--pv), var(--pa)); text-align: center; }
.ob-pv-h1 { font-size: 14px; font-weight: 800; color: #fff; margin-bottom: 10px; line-height: 1.35; }
.ob-pv-cta { display: inline-block; background: var(--surface, #ffffff); color: var(--pv); font-size: 12px; font-weight: 800; padding: 7px 16px; border-radius: 8px; }
.ob-pv-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 12px; }
.ob-pv-tile { border: 1px solid #eef2f6; border-radius: 9px; padding: 8px; }
.ob-pv-tile-img { height: 34px; border-radius: 6px; background: color-mix(in srgb, var(--pv) 13%, white); margin-bottom: 7px; }
.ob-pv-tile-l { height: 7px; width: 80%; border-radius: 4px; background: var(--border, #e2e8f0); margin-bottom: 5px; }
.ob-pv-tile-s { height: 6px; width: 50%; border-radius: 4px; background: #eef2f6; }

.ob-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; }
/* ── Step 3 done ── */
.success-state { text-align: center; padding: 8px 0 6px; }
.success-icon {
  width: 64px; height: 64px; border-radius: 50%;
  background: linear-gradient(135deg, #14b8a6, #0f766e);
  color: #fff; display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.success-title { font-size: 21px; font-weight: 800; color: #0f172a; margin: 0 0 10px; }
.success-body  { font-size: 14px; color: #475569; line-height: 1.7; margin: 0 0 18px; }
.ob-done-site {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px; padding: 12px 14px; margin-bottom: 18px;
}
.ob-done-site-l { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; color: #0f766e; word-break: break-all; min-width: 0; }
.ob-done-site-actions { display: flex; gap: 8px; }
.success-state .auth-btn { width: 100%; }

/* ── Phone (≤560px) — 360px-wide friendly ── */
@media (max-width: 560px) {
  /* iOS focus-zoom guard: all controls share .auth-input (select/textarea/hex too) */
  .auth-input { font-size: 16px; }

  /* Reclaim content width: 24+36px chrome left only ~240px at 360px wide */
  .auth-shell { padding: 12px; }
  .auth-card { padding: 22px 18px 20px; border-radius: 16px; }

  /* Username row: let the input shrink, slim the .bookboll.com suffix */
  .ob-sub-in { min-width: 0; flex: 1; }
  .ob-sub-suffix { padding: 0 8px; font-size: 12px; }
  .ob-sub-locked { padding: 10px 10px; font-size: 13px; min-width: 0; overflow: hidden; text-overflow: ellipsis; }

  /* Primary/Accent colour pickers stack — keeps the hex inputs typable */
  .ob-colors { grid-template-columns: 1fr; }

  /* Storefront-mock text up to the legibility floor */
  .ob-pv-addr { font-size: 10.5px; }
  .ob-pv-navlink, .ob-pv-signin { font-size: 11px; }

  /* Done-step site row: URL on top, Copy/Open as a 2-up grid of tappable buttons */
  .ob-done-site { flex-direction: column; align-items: stretch; }
  .ob-done-site-actions { display: grid; grid-template-columns: 1fr 1fr; }
  .auth-btn-ghost.sm { padding: 11px 12px; }
}

/* Guided business onboarding */
.auth-shell{background:#f4f7f7;align-items:start;display:grid;grid-template-columns:300px minmax(0,760px);justify-content:center;gap:56px;padding:40px 32px;min-height:100vh;color:#12332f;--surface:#fff;--text-muted:#526762;--text-light:#61796f;--border:#cedcd6}
.ob-sidebar{position:sticky;top:32px;padding:4px 0;display:flex;flex-direction:column;gap:38px}.ob-back{display:flex;align-items:center;gap:8px;color:#075e54;text-decoration:none;font-size:14px;font-weight:700}.ob-sidebar h2{font-size:32px;line-height:1.15;letter-spacing:-.8px;color:#12332f;margin:0 0 16px}.ob-sidebar p{font-size:14px;line-height:1.6;color:#526762;margin:0}.ob-sidebar ol{padding:0;list-style:none;margin:0;display:grid;gap:28px}.ob-sidebar li{display:flex;align-items:flex-start;gap:13px;color:#657c73}.ob-sidebar li>span{width:30px;height:30px;border-radius:50%;border:1px solid #b7ccc3;display:grid;place-items:center;font-size:13px;font-weight:700;flex-shrink:0}.ob-sidebar li.current>span{background:#075e54;color:white;border-color:#075e54}.ob-sidebar li.complete>span{background:#dcf5ec;color:#075e54;border-color:#b4d9c8}.ob-sidebar strong{display:block;font-size:14px;line-height:1.7}.ob-sidebar small{display:block;font-size:12px;line-height:1.5;margin-top:3px}.ob-sidebar li.current{color:#12332f}.ob-sidebar-note{display:flex;gap:10px;padding-top:24px;border-top:1px solid #d4e2dc}.ob-sidebar-note svg{color:#075e54;flex-shrink:0}.ob-sidebar-note p{font-size:12px}.auth-card{max-width:none;padding:36px 40px;border:1px solid #d9e4de;box-shadow:0 5px 20px #12332f05;border-radius:16px;transition:none}.auth-card.wide{max-width:none}.auth-brand{margin-bottom:28px}.auth-brand-name{color:#12332f}.auth-logo{background:#0fa59b}.auth-heading{font-size:29px;line-height:1.2;color:#12332f;letter-spacing:-.6px}.auth-sub{font-size:14px;line-height:1.6;margin-top:10px;margin-bottom:26px;max-width:57ch}.auth-form{gap:22px}.auth-label{font-size:14px;color:#213f35;font-weight:650}.auth-input{background:#fff;border-color:#b9ccc3;border-radius:8px;min-height:47px;font-size:15px}.auth-input:focus{border-color:#075e54;box-shadow:0 0 0 3px #075e5415}.auth-input[aria-invalid=true],.ob-sub.invalid{border-color:#bb3636;background:#fff7f7}.ob-field-error{color:#a72828;font-size:12px;line-height:1.5}.auth-btn{background:#075e54;border-radius:8px;box-shadow:none;min-height:48px}.auth-btn:hover:not(:disabled){background:#044b43;transform:none}.ob-mobile-progress{font-size:12px;color:#526762;margin-bottom:14px}.ob-type-field{border:0;padding:0;margin:0;min-width:0}.ob-type-field>p{font-size:12px;color:#526762;margin:5px 0 10px}.ob-type-options{display:grid;grid-template-columns:1fr 1fr;gap:8px}.ob-type-options label{display:flex;align-items:center;gap:9px;border:1px solid #cbdcd3;border-radius:8px;padding:12px;line-height:1.3;font-size:13px;color:#294a3d;cursor:pointer}.ob-type-options label.selected{border-color:#087967;background:#eef8f3}.ob-type-options input{accent-color:#075e54;flex-shrink:0;width:16px;height:16px;margin:0}.ob-business-summary{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f1f7f4;border-radius:8px;gap:16px}.ob-business-summary strong,.ob-business-summary small{display:block}.ob-business-summary strong{font-size:14px;color:#12332f}.ob-business-summary small{font-size:12px;color:#526762;margin-top:4px;overflow-wrap:anywhere}.ob-business-summary button{background:none;border:0;color:#075e54;font:inherit;font-size:13px;font-weight:700;cursor:pointer;text-decoration:underline}.ob-next-steps{padding:0;list-style:none;text-align:left;margin:26px 0;display:grid;gap:16px}.ob-next-steps li{display:flex;gap:12px;color:#075e54}.ob-next-steps strong,.ob-next-steps small{display:block}.ob-next-steps strong{font-size:14px;color:#12332f}.ob-next-steps small{font-size:13px;color:#526762;line-height:1.5;margin-top:3px}.ob-grid{grid-template-columns:1fr}.ob-preview{margin-top:0;position:static;max-width:500px;width:100%;justify-self:center}.ob-form{gap:18px}.ob-actions{border-top:1px solid #d9e4de;padding-top:22px}.success-state{text-align:left}.success-icon{margin-left:0}.auth-shell :is(a,button,summary):focus-visible{outline:3px solid #1a6afd;outline-offset:3px}.ob-mail-btn{min-width:108px}.ob-mail-in{min-width:0}.ob-pw-eye{min-width:36px;min-height:36px}.auth-pw-eye{min-width:32px;min-height:36px;align-items:center;justify-content:center}.ob-sub-status{line-height:1.5}
@media(max-width:1000px){.auth-shell{grid-template-columns:230px minmax(0,640px);gap:28px;padding:28px 24px}.auth-card{padding:28px}.ob-sidebar h2{font-size:27px}}
@media(max-width:760px){.auth-shell{display:block;padding:18px 16px 32px}.ob-sidebar{position:static;margin:0 0 20px;padding:0}.ob-sidebar>div,.ob-sidebar ol{display:none}.auth-card{padding:24px 20px;border-radius:12px}.auth-heading{font-size:26px}.auth-brand{margin-bottom:20px}.ob-type-options{grid-template-columns:1fr 1fr}.ob-type-options label{padding:12px 9px;font-size:12px}.ob-grid{display:block}.ob-preview{margin-top:26px}.ob-actions{gap:12px;flex-wrap:wrap}.ob-actions .auth-btn{flex:1}.ob-mail{flex-wrap:wrap}.ob-mail-in{flex:1 1 170px}.ob-mail-btn{padding-inline:12px;font-size:12px}.ob-sub-suffix{font-size:12px;padding-inline:10px}.ob-otp-row{gap:8px}.auth-pw-wrap .auth-input{padding-right:48px}}


/* Form actions stay with their step, including when the mobile keyboard opens. */
.auth-card, .auth-field, .ob-form, .ob-business-summary > span { min-width: 0; }
.ob-business-summary strong, .ob-sub-locked { overflow-wrap: anywhere; }
.ob-next-steps svg, .ob-done-site-l svg { flex-shrink: 0; }
@media (max-width: 1000px) {
  .auth-input, .ob-mail-in, .ob-otp-in, .ob-sub-in { font-size: 16px; }
}
@media (max-width: 760px) {
  .auth-shell { padding: 16px 12px calc(28px + env(safe-area-inset-bottom, 0px)); }
  .auth-card { padding: 24px 20px; }
  .auth-input, .ob-mail-in, .ob-otp-in, .ob-sub-in { font-size: 16px; }
  .auth-form > .auth-btn, .ob-actions { position: static; width: 100%; box-shadow: none; }
  .auth-form > .auth-btn { border-radius: 8px; padding: 14px; white-space: normal; line-height: 1.45; }
  .ob-actions { margin-top: 24px; padding: 18px 0 0; flex-direction: column-reverse; background: none; gap: 8px; }
  .ob-actions .auth-btn, .ob-actions .auth-btn-ghost { width: 100%; min-height: 48px; flex: auto; white-space: normal; }
  .ob-mail { gap: 10px; }.ob-mail-in { flex-basis: 100%; }
  .ob-mail-btn { width: 100%; min-height: 44px; justify-content: center; border-radius: 8px; }
  .ob-sub-status { flex-wrap: wrap; }.ob-sub-status > span { min-width: 0; }
  .auth-pw-eye { width: 44px; min-height: 44px; }
  .ob-business-summary button { min-width: 44px; min-height: 44px; }
  .ob-logo-row { flex-wrap: wrap; }.ob-logo-actions { min-width: 0; }
  .ob-otp-edit { min-height: 40px; }.ob-otp-in { width: 0; flex: 1; min-width: 0; }
  .ob-otp-head { flex-wrap: wrap; gap: 8px; }.ob-otp-btn { min-height: 46px; }
  .ob-sub-locked { white-space: normal; }.ob-sub-suffix { flex-shrink: 0; }
  .ob-done-site-l { width: 100%; align-items: flex-start; }.ob-done-site-l span { min-width: 0; overflow-wrap: anywhere; }
  .ob-preview { overflow: hidden; }.ob-pv-brandname { max-width: 135px; }
  .ob-mobile-progress { padding: 10px 12px; border-left: 3px solid #087967; background: #eef8f3; border-radius: 4px; }
}
@media (max-width: 360px) {
  .auth-card { padding: 22px 16px; }.auth-heading { font-size: 24px; }
  .ob-type-options { grid-template-columns: 1fr; }.ob-type-options label { font-size: 13px; }
  .ob-sub-suffix { font-size: 11px; padding-inline: 7px; }
  .ob-pv-navlinks { gap: 8px; }.ob-pv-nav { padding: 12px; }
}
/* Website setup: identity on the left, the customer-facing result on the right. */
.website-step { grid-template-columns:240px minmax(0,1060px); gap:40px; }
.website-step .auth-card { padding:32px; }
.website-step .ob-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:32px; }
.ob-brand-group { padding:0; min-width:0; }
.ob-brand-group + .ob-brand-group { border-top:1px solid #cedcd6; padding-top:24px; }
.ob-brand-group h2 { margin:0; font-size:18px; color:#12332f; }
.ob-brand-group > p { margin:5px 0 18px; font-size:13px; line-height:1.5; color:#526762; }
.ob-brand-group .auth-field { margin-top:18px; }
.ob-palettes { display:flex; flex-wrap:wrap; gap:8px; }
.ob-palettes button { display:flex; align-items:center; gap:4px; padding:9px; min-height:42px; font:inherit; font-size:12px; border:1px solid #cedcd6; border-radius:7px; background:white; color:#12332f; cursor:pointer; }
.ob-palettes button[aria-pressed=true] { border-color:#075e54; box-shadow:0 0 0 1px #075e54; }
.ob-palettes button span { width:12px; height:20px; border-radius:3px; }.ob-palettes button span + span { margin-right:5px; }
.website-step .ob-preview { min-width:0; width:100%; padding:20px; background:#eef5f2; border:0; border-radius:12px; position:sticky; top:24px; }
.website-step .ob-pv-label { display:flex; align-items:center; gap:7px; font-size:13px; letter-spacing:0; text-transform:none; color:#294a3d; margin-bottom:16px; }.ob-pv-label span { margin-left:auto; font-size:11px; font-weight:500; }
.website-step .ob-pv-hero { min-height:160px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:28px 20px; }
.website-step .ob-pv-h1 { font-size:23px; line-height:1.25; overflow-wrap:anywhere; }
.ob-site-address { display:flex; gap:10px; margin-top:24px; color:#075e54; }.ob-site-address svg { flex-shrink:0; margin-top:2px; }.ob-site-address div { min-width:0; display:grid; gap:5px; }.ob-site-address strong { font-size:13px; }.ob-site-address span { font-size:14px; overflow-wrap:anywhere; }.ob-site-address small { font-size:12px; line-height:1.5; color:#526762; }
.ob-preview-note { display:flex; align-items:flex-start; gap:8px; border-top:1px solid #cedcd6; padding-top:18px; margin:20px 0 0; font-size:12px; line-height:1.6; color:#526762; }.ob-preview-note svg { flex-shrink:0; margin-top:2px; }
@media(max-width:1190px) { .website-step { grid-template-columns:200px minmax(0,1fr); gap:24px; }.website-step .ob-grid { grid-template-columns:1fr; }.website-step .ob-preview { position:static; max-width:none; } }
@media(max-width:760px) { .website-step { display:block; }.website-step .auth-card { padding:24px 20px; }.website-step .ob-grid { gap:24px; }.website-step .ob-preview { margin:0; padding:16px; }.website-step .ob-pv-h1 { font-size:21px; } }
@media(max-width:360px) { .website-step .auth-card { padding:22px 16px; }.ob-colors { grid-template-columns:1fr; }.website-step .ob-preview { padding:12px; } }
</style>

