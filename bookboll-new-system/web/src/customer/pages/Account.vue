<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { customer, isCustomer, customerSignOut, loadCustomerAccount, saveCustomerAccount, uploadCustomerAvatar } from '../../lib/customerSession.js'
import { getCustomerToken } from '../../lib/api.js'
import { isWhiteLabel, activeBrand, brandShows, platformBrand } from '../../lib/brand.js'
import { uploadImage } from '../../lib/imageUpload.js'
import { profileStrength } from '../../lib/social.js'
import { useSeo } from '../../lib/seo.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import PersonalPage from '../components/PersonalPage.vue'
useSeo({ title: 'My account', noindex: true, path: '/account' })
import BrandMark from '../../components/BrandMark.vue'
import { User, Mail, LogOut, Trophy, Ticket, Sparkles, CalendarCheck, Dumbbell, Camera, Plus, X, ArrowRight, Loader, Check, Lock, Globe, Search, EyeOff } from 'lucide-vue-next'

const router = useRouter()
const edit = ref({ full_name: '', bio: '', is_private: false, is_discoverable: true, photos: [], marketing_email: false, marketing_sms: false, marketing_whatsapp: false })
// Live profile-strength meter (mirrors what hosts see when accepting join requests).
const strength = computed(() => profileStrength({
  full_name: edit.value.full_name, bio: edit.value.bio, avatar_url: avatarUrl.value, photos: edit.value.photos,
}))
const avatarUrl = ref('')
const avatarBusy = ref(false)
const galBusy = ref(false)
const imgErr = ref('')
const saveErr = ref('')
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const saved = ref(false)
const avatarInput = ref(null)
const galInput = ref(null)
const signedIn = computed(() => isCustomer.value && !!getCustomerToken())

const initial = computed(() => (customer.value?.full_name || customer.value?.email || '?').charAt(0).toUpperCase())

function fillFromCustomer() {
  const c = customer.value
  if (!c) return
  edit.value.full_name = c.full_name || ''
  edit.value.bio = c.bio || ''
  edit.value.is_private = c.is_private === true
  edit.value.is_discoverable = c.is_discoverable !== false
  edit.value.photos = Array.isArray(c.photos) ? [...c.photos] : []
  edit.value.marketing_email = c.marketing_email === true
  edit.value.marketing_sms = c.marketing_sms === true
  edit.value.marketing_whatsapp = c.marketing_whatsapp === true
  avatarUrl.value = c.avatar_url || ''
}

let loadInFlight = false
async function load() {
  if (!signedIn.value) { loading.value = false; loadError.value = ''; return }
  if (loadInFlight) return          // never let two loads overlap
  loadInFlight = true
  loading.value = true
  loadError.value = ''
  const res = await loadCustomerAccount()
  if (res.error) loadError.value = res.error
  fillFromCustomer()
  loading.value = false
  loadInFlight = false
}
onMounted(load)
// Watch the two values SEPARATELY. A getter returning a fresh array is a new
// object every evaluation, so Vue always sees it as changed — and because load()
// replaces customer.value, that re-triggered itself in an endless loop of
// /customer/profile requests. Element-wise sources compare by value, so a reload
// that yields the same id fires nothing.
watch([() => customer.value?.id, signedIn], load)

function pickAvatar() { avatarInput.value?.click() }
async function onAvatarFile(e) {
  const file = e.target.files?.[0]; if (!file) return
  imgErr.value = ''; avatarBusy.value = true
  const res = await uploadCustomerAvatar(file)
  avatarBusy.value = false
  if (avatarInput.value) avatarInput.value.value = ''
  if (res.error) { imgErr.value = res.error; return }
  avatarUrl.value = res.url
}
function pickGallery() { galInput.value?.click() }
async function onGalleryFiles(e) {
  const files = Array.from(e.target.files || []); if (!files.length) return
  imgErr.value = ''; galBusy.value = true
  for (const file of files) {
    const res = await uploadImage(file, 'avatars')
    if (res.error) { imgErr.value = res.error; break }
    edit.value.photos.push(res.url)
  }
  galBusy.value = false
  if (galInput.value) galInput.value.value = ''
}
function removePhoto(i) { edit.value.photos.splice(i, 1) }
async function saveProfile() {
  const name = edit.value.full_name.trim()
  if (!name) { saveErr.value = 'Please enter your name.'; return }
  saving.value = true; saved.value = false; saveErr.value = ''
  const res = await saveCustomerAccount({
    full_name: name,
    bio: edit.value.bio.trim(),
    is_private: edit.value.is_private,
    is_discoverable: edit.value.is_discoverable,
    avatar_url: avatarUrl.value,
    photos: edit.value.photos,
    marketing_email: edit.value.marketing_email,
    marketing_sms: edit.value.marketing_sms,
    marketing_whatsapp: edit.value.marketing_whatsapp,
    marketing_updated_at: new Date().toISOString(),
  })
  saving.value = false
  if (res.error) { saveErr.value = res.error; return }
  saved.value = true
  fillFromCustomer()
  setTimeout(() => { saved.value = false }, 2000)
}

async function openGate() { await ensureSignedIn() }   // success flips isCustomer → profile renders

function signOut() { customerSignOut(); router.push('/') }
</script>

<template>
  <PersonalPage :title="signedIn ? 'Account settings' : 'My account'" description="Your profile, privacy and communication preferences.">
  <div class="acc" :class="{ 'acc-signed-in': signedIn }">
    <!-- Signed in (Sanctum token required — leftover demo sessions cannot load Laravel profile) -->
    <template v-if="signedIn && loading">
      <div class="acc-msg">Loading…</div>
    </template>
    <template v-else-if="signedIn && loadError">
      <div class="acc-msg acc-msg-err">{{ loadError }}</div>
    </template>
    <template v-else-if="signedIn">
      <aside class="acc-summary">
      <div class="acc-prof">
        <div class="acc-avatar-wrap">
          <div class="acc-avatar" :class="{ img: avatarUrl }" :style="avatarUrl ? { backgroundImage: `url(${avatarUrl})` } : {}" @click="pickAvatar" title="Change profile picture">
            <span v-if="!avatarUrl">{{ initial }}</span>
            <div class="acc-avatar-ov"><Camera :size="18" /></div>
          </div>
          <button class="acc-cam" :disabled="avatarBusy" @click.stop="pickAvatar" title="Change profile picture">
            <Loader v-if="avatarBusy" :size="13" class="acc-spin" /><Camera v-else :size="13" />
          </button>
          <input ref="avatarInput" type="file" accept="image/*" class="acc-file" @change="onAvatarFile" />
        </div>
        <div class="acc-prof-id">
          <div class="acc-name">{{ customer.full_name || 'Player' }}</div>
          <div class="acc-email">{{ customer.email }}</div>
          <router-link v-if="!isWhiteLabel" :to="`/u/${customer.id}`" class="acc-viewprofile">View my public profile <ArrowRight :size="14" /></router-link>
        </div>
      </div>

      <div class="acc-links">
        <router-link to="/my-bookings" class="acc-link"><CalendarCheck :size="20" /><span>My Bookings</span></router-link>

        <router-link v-if="!isWhiteLabel" to="/rewards" class="acc-link"><Trophy :size="20" /><span>My Rewards</span></router-link>
        <router-link v-if="brandShows('events')" to="/my-games" class="acc-link"><Ticket :size="20" /><span>My Events</span></router-link>
      </div>
      <button class="acc-out" @click="signOut"><LogOut :size="17" /> Sign out</button>
      </aside>
      <div class="acc-edit">
        <section class="acc-section" aria-labelledby="profile-section">
        <h2 id="profile-section" class="acc-section-title">Profile details</h2><p class="acc-section-intro">Choose the name and introduction people see on Bookboll.</p>
        <label class="acc-l" for="account-name">Display name</label>
        <div class="acc-field"><User :size="18" /><input id="account-name" autocomplete="name" v-model="edit.full_name" placeholder="Your name" /></div>

        <!-- Social profile (bio, strength, privacy, photos) — marketplace only. A provider's
             white-label site is a basic booking/membership site, so it's hidden there. -->
        <template v-if="!isWhiteLabel">
          <label class="acc-l" for="account-bio">Bio <span class="acc-opt acc-opt-dup">a short intro shown on your profile</span></label>
          <textarea id="account-bio" v-model="edit.bio" class="acc-bio" rows="3" maxlength="300" placeholder="Tell others about you — your sports, level, what you're looking for…"></textarea>
          <div class="acc-bio-count">{{ (edit.bio || '').length }}/300</div>

          <!-- Profile strength: nudges completion (this is what hosts see when accepting you) -->
          <div class="acc-strength" :class="strength.level">
            <div class="acc-strength-top">
              <span>Profile strength</span><b>{{ strength.score }}%</b>
            </div>
            <div class="acc-strength-bar"><div class="acc-strength-fill" :style="{ width: strength.score + '%' }"></div></div>
            <div v-if="strength.missing.length" class="acc-strength-tips">
              Help other players and hosts get to know you. Still to add: {{ strength.missing.join(' · ') }}.
            </div>
            <div v-else class="acc-strength-tips done"><Check :size="13" /> Looking great — your profile is complete!</div>
          </div>

          <h3 class="acc-subsection-title">Profile visibility</h3>
          <!-- Private account: gate who can see your posts/photos behind follow-accept -->
          <button type="button" role="switch" :aria-checked="edit.is_private" aria-label="Private profile" class="acc-priv" :class="{ on: edit.is_private }" @click="edit.is_private = !edit.is_private">
            <component :is="edit.is_private ? Lock : Globe" :size="18" />
            <span class="acc-priv-txt">
              <b>{{ edit.is_private ? 'Private account' : 'Public account' }}</b>
              <small>{{ edit.is_private ? 'Only approved followers see your posts, photos & stats. Follow requests need your approval.' : 'Anyone can see your profile, posts and photos.' }}</small>
            </span>
            <span class="acc-toggle" :class="{ on: edit.is_private }"><span class="acc-knob"></span></span>
          </button>

          <!-- Being FOUND is a separate choice from who can see your posts: a
               private account is still reachable by link, so turning privacy on
               does not take you out of search. This is the one that does. -->
          <button type="button" role="switch" :aria-checked="edit.is_discoverable" aria-label="Findable by name in search" class="acc-priv" :class="{ on: edit.is_discoverable }" @click="edit.is_discoverable = !edit.is_discoverable">
            <component :is="edit.is_discoverable ? Search : EyeOff" :size="18" />
            <span class="acc-priv-txt">
              <b>{{ edit.is_discoverable ? 'Findable by name' : 'Not findable by name' }}</b>
              <small>{{ edit.is_discoverable ? 'People can find you by searching your name. Your profile link keeps working either way.' : 'You won’t appear in people search. Anyone with your profile link can still open it.' }}</small>
            </span>
            <span class="acc-toggle" :class="{ on: edit.is_discoverable }"><span class="acc-knob"></span></span>
          </button>

          <h3 class="acc-subsection-title">Profile photos</h3>
          <p class="acc-hint">Change your profile picture beside your name, or add other photos below.</p>
          <div class="acc-gallery">
            <div v-for="(img, i) in edit.photos" :key="img" class="acc-gimg" :style="{ backgroundImage: `url(${img})` }">
              <button class="acc-gimg-x" @click="removePhoto(i)" :aria-label="`Remove photo ${i + 1}`" title="Remove"><X :size="12" /></button>
            </div>
            <button class="acc-gadd" :disabled="galBusy" @click="pickGallery">
              <Loader v-if="galBusy" :size="18" class="acc-spin" />
              <template v-else><Plus :size="18" /><span>Add</span></template>
            </button>
            <input ref="galInput" type="file" accept="image/*" multiple class="acc-file" @change="onGalleryFiles" />
          </div>
        </template>
        <div v-if="imgErr" class="acc-err">{{ imgErr }}</div>

        </section>
        <section class="acc-section" aria-labelledby="preferences-section">
        <!-- Marketing preferences — per-channel consent, editable any time. Shown on the
             marketplace AND white-label sites (providers may message their own customers). -->
        <h2 id="preferences-section" class="acc-section-title">Messages &amp; offers</h2><p class="acc-section-intro">Choose where you receive marketing messages. Save your changes below.</p>
        <button type="button" role="switch" :aria-checked="edit.marketing_email" aria-label="Marketing by email" class="acc-priv acc-priv-mkt" :class="{ on: edit.marketing_email }" @click="edit.marketing_email = !edit.marketing_email">
          <Mail :size="18" />
          <span class="acc-priv-txt"><b>Email</b><small>Offers, news &amp; updates by email. One-click unsubscribe in every message.</small></span>
          <span class="acc-toggle" :class="{ on: edit.marketing_email }"><span class="acc-knob"></span></span>
        </button>
        <button type="button" role="switch" :aria-checked="edit.marketing_sms" aria-label="Marketing by sms" class="acc-priv acc-priv-mkt" :class="{ on: edit.marketing_sms }" @click="edit.marketing_sms = !edit.marketing_sms">
          <Mail :size="18" />
          <span class="acc-priv-txt"><b>SMS</b><small>Time-sensitive offers &amp; reminders by text message.</small></span>
          <span class="acc-toggle" :class="{ on: edit.marketing_sms }"><span class="acc-knob"></span></span>
        </button>
        <button type="button" role="switch" :aria-checked="edit.marketing_whatsapp" aria-label="Marketing by whatsapp" class="acc-priv acc-priv-mkt" :class="{ on: edit.marketing_whatsapp }" @click="edit.marketing_whatsapp = !edit.marketing_whatsapp">
          <Mail :size="18" />
          <span class="acc-priv-txt"><b>WhatsApp</b><small>Offers &amp; updates via WhatsApp.</small></span>
          <span class="acc-toggle" :class="{ on: edit.marketing_whatsapp }"><span class="acc-knob"></span></span>
        </button>

        </section>
        <div class="acc-save">
        <p class="acc-save-hint">Profile and preference changes apply when you save. Your profile picture saves immediately.</p>
        <div v-if="saveErr" class="acc-err">{{ saveErr }}</div>
        <button class="acc-btn" :disabled="saving || avatarBusy || galBusy" @click="saveProfile">{{ saving ? 'Saving…' : (saved ? 'Changes saved' : 'Save changes') }}</button>
        </div>
      </div>

    </template>

    <!-- Signed out -->
    <template v-else>
      <div class="acc-hero"><Sparkles :size="30" /><h1>Welcome to {{ isWhiteLabel ? activeBrand.name : 'Bookboll' }}</h1><p>{{ isWhiteLabel ? 'Sign in with your Bookboll account to book and manage your reservations.' : 'Sign in with Google or email to earn rewards, host games and track your join requests.' }}</p></div>
      <div class="acc-card">
        <button class="acc-btn" @click="openGate">Sign in / Create account</button>
        <p class="acc-fine">One Bookboll account works everywhere.</p>
      </div>

      <!-- Powered by Bookboll: clarifies the account is a shared Bookboll identity -->
      <div v-if="isWhiteLabel" class="acc-powered"><span v-if="platformBrand.logo" class="acc-powered-bb acc-powered-img" :style="{ backgroundImage: `url(${platformBrand.logo})` }"></span><span v-else class="acc-powered-bb"><BrandMark /></span> Powered by <strong>{{ platformBrand.name || 'Bookboll' }}</strong> <span class="acc-powered-tag">· one account for every Bookboll venue</span></div>
    </template>
  </div>
  </PersonalPage>
</template>

<style scoped>
.acc { max-width: 440px; margin: 0 auto; }
.acc-msg { text-align: center; padding: 50px 20px; color: var(--text-light, #94a3b8); }
.acc-msg-err { color: var(--danger-ink, #dc2626); }
.acc-hero { text-align: center; color: var(--brand-ink, var(--brand)); margin-bottom: 22px; }
.acc-hero h1 { font-size: 25px; font-weight: 900; color: var(--text, #0f172a); margin: 12px 0 6px; }
.acc-hero p { font-size: 14.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0; }
.acc-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; }
.acc-l { display: block; font-size: 13.5px; font-weight: 700; color: var(--text-secondary, #334155); margin: 14px 0 6px; }
.acc-opt { font-size: 11.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
.acc-field { display: flex; align-items: center; gap: 10px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 0 14px; color: var(--text-light, #94a3b8); }
.acc-field:focus-within { border-color: var(--brand-accent); }
.acc-field input { flex: 1; border: none; outline: none; padding: 13px 0; font-size: 15px; color: var(--text, #0f172a); background: transparent; }
.acc-err { font-size: 13.5px; color: var(--danger-ink, #dc2626); margin-top: 12px; }
.acc-mkt { margin-top: 14px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.acc-mkt-head { font-size: 12.5px; font-weight: 800; color: var(--text-secondary, #334155); margin-bottom: 8px; }
.acc-mcheck { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary, #475569); margin-inline-end: 16px; cursor: pointer; }
.acc-mcheck input { width: 16px; height: 16px; }
.acc-mkt-note { font-size: 11px; color: var(--text-light, #94a3b8); line-height: 1.5; margin: 9px 0 0; }
.acc-btn { width: 100%; background: var(--brand); color: #fff; font-weight: 800; font-size: 16px; padding: 14px; border: none; border-radius: 12px; cursor: pointer; margin-top: 18px; }
.acc-btn:disabled { opacity: .6; }
.acc-fine { text-align: center; font-size: 12.5px; color: var(--text-light, #94a3b8); margin: 12px 0 0; }
.acc-field-end { flex-shrink: 0; }
.acc-ok { color: var(--success-ink, #16a34a); }
.acc-known { display: flex; align-items: flex-start; gap: 8px; background: var(--success-bg, #f0fdf4); border: 1px solid var(--success-border, #bbf7d0); color: var(--success-ink, #15803d); border-radius: 10px; padding: 11px 12px; font-size: 13px; font-weight: 600; line-height: 1.45; margin-top: 12px; }
.acc-known svg { flex-shrink: 0; margin-top: 1px; }
/* Powered-by uses Bookboll's own teal (not the provider brand) — it represents Bookboll. */
.acc-powered { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 16px; font-size: 12.5px; color: var(--text-light, #94a3b8); font-weight: 600; }
.acc-powered strong { color: var(--text-secondary, #475569); }
.acc-powered-bb { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: linear-gradient(135deg,#14b8a6,#0f766e); color: #fff; font-size: 9.5px; font-weight: 800; }
.acc-powered-img { background-size: cover; background-position: center; }

/* profile header */
.acc-prof { display: flex; align-items: center; gap: 16px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; }
.acc-avatar-wrap { position: relative; flex-shrink: 0; }
.acc-avatar { width: 66px; height: 66px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; cursor: pointer; position: relative; overflow: hidden; }
.acc-avatar.img { background-size: cover; background-position: center; }
.acc-avatar-ov { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(15,23,42,.45); color: #fff; opacity: 0; transition: opacity .12s; }
.acc-avatar:hover .acc-avatar-ov { opacity: 1; }
.acc-cam { position: absolute; bottom: -2px; right: -2px; width: 26px; height: 26px; border-radius: 50%; border: 2px solid #fff; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.acc-cam:hover { background: #0e6a63; }
.acc-cam:disabled { opacity: .7; }
.acc-file { display: none; }
.acc-prof-id { min-width: 0; }
.acc-name { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); }
.acc-email { font-size: 14px; color: var(--text-muted, #64748b); }
.acc-viewprofile { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: var(--brand-ink, var(--brand)); text-decoration: none; margin-top: 5px; }
.acc-viewprofile:hover { text-decoration: underline; }

/* profile editor */
.acc-edit { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 20px; margin-top: 14px; }
.acc-edit-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 4px; }
.acc-bio { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 14.5px; color: var(--text, #0f172a); font-family: inherit; resize: vertical; outline: none; }
.acc-bio:focus { border-color: var(--brand-accent); }
.acc-bio-count { text-align: right; font-size: 11px; color: var(--text-light, #94a3b8); margin-top: 3px; }

/* Profile strength meter */
.acc-strength { margin-top: 14px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.acc-strength-top { display: flex; justify-content: space-between; font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #475569); }
.acc-strength-top b { color: var(--text, #0f172a); }
.acc-strength-bar { height: 7px; border-radius: 999px; background: var(--border, #e2e8f0); overflow: hidden; margin: 8px 0; }
.acc-strength-fill { height: 100%; border-radius: 999px; transition: width .25s; }
.acc-strength.weak .acc-strength-fill { background: #f59e0b; }
.acc-strength.ok .acc-strength-fill { background: #3b82f6; }
.acc-strength.strong .acc-strength-fill { background: #10b981; }
.acc-strength-tips { font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.45; }
.acc-strength-tips.done { display: flex; align-items: center; gap: 5px; color: var(--success-ink, #059669); font-weight: 700; }

/* Private toggle */
.acc-priv { width: 100%; display: flex; align-items: center; gap: 12px; margin-top: 14px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; padding: 13px 14px; cursor: pointer; text-align: left; color: var(--text-secondary, #334155); }
.acc-priv:hover { border-color: var(--brand-accent); }
.acc-priv.on { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 5%, var(--surface, white)); }
.acc-priv > svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.acc-priv-txt { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.acc-priv-txt b { font-size: 14px; color: var(--text, #0f172a); }
.acc-priv-txt small { font-size: 11.5px; color: var(--text-light, #94a3b8); line-height: 1.4; }
.acc-toggle { flex-shrink: 0; width: 40px; height: 23px; border-radius: 999px; background: #cbd5e1; position: relative; transition: background .15s; }
.acc-toggle.on { background: var(--brand); }
.acc-knob { position: absolute; top: 2px; left: 2px; width: 19px; height: 19px; border-radius: 50%; background: var(--surface, #ffffff); transition: transform .15s; }
.acc-toggle.on .acc-knob { transform: translateX(17px); }

.acc-gallery { display: flex; flex-wrap: wrap; gap: 9px; }
.acc-gimg { position: relative; width: 78px; height: 78px; border-radius: 12px; background-size: cover; background-position: center; border: 1px solid var(--border, #e2e8f0); }
.acc-gimg-x { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; border: none; background: rgba(15,23,42,.78); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.acc-gimg-x:hover { background: #ef4444; }
.acc-gadd { width: 78px; height: 78px; border-radius: 12px; border: 1.5px dashed var(--border-strong, #cbd5e1); background: var(--canvas, #f8fafc); color: var(--brand-ink, var(--brand)); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; font-size: 11px; font-weight: 700; cursor: pointer; }
.acc-hint { font-size: 12px; color: var(--text-light, #94a3b8); margin: 0 0 10px; line-height: 1.4; }
.acc-gadd:hover:not(:disabled) { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.acc-spin { animation: accspin 1s linear infinite; } @keyframes accspin { to { transform: rotate(360deg); } }

.acc-links { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin: 16px 0; }
.acc-link { display: flex; flex-direction: column; align-items: center; gap: 8px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 18px 10px; text-decoration: none; color: var(--brand-ink, var(--brand)); font-weight: 800; font-size: 13px; text-align: center; }
.acc-link:hover { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.acc-out { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--surface, #ffffff); border: 1.5px solid var(--danger-border, #fecaca); color: var(--danger-ink, #dc2626); font-weight: 800; font-size: 15px; padding: 13px; border-radius: 12px; cursor: pointer; }

/* Phone: tighten the signed-out sign-in flow so the email input + CTA sit higher,
   stop iOS zoom-on-focus (16px inputs), enlarge tap targets, and lay marketing rows
   out as full-width tappable rows. Spacing/size/font-size only — no colours/backgrounds,
   so existing dark-mode behaviour is untouched. */
@media (max-width: 760px) {
  /* iOS zoom-on-focus guard: all text fields at 16px */
  .acc-field input, .acc-bio { font-size: 16px; }

  /* Compress the signed-out hero so the email field & Continue button rise up */
  .acc-hero { margin-bottom: 14px; }
  .acc-hero h1 { font-size: 21px; margin: 8px 0 4px; }
  .acc-hero p { font-size: 13.5px; }
  .acc-hero svg { width: 26px; height: 26px; }
  .acc-card { padding: 18px 16px; }
  .acc-btn { margin-top: 14px; }

  /* Signed-out marketing consent: each channel its own full-width, easy-tap row */
  .acc-mcheck { display: flex; margin-inline-end: 0; padding: 11px 0; }
  .acc-mcheck input { width: 20px; height: 20px; }

  /* Wider quick-links tiles so labels breathe (still a grid) */
  .acc-links { grid-template-columns: 1fr 1fr; gap: 10px; }

  /* Signed-in editor: tighten spacing so Save reaches higher */
  .acc-prof { padding: 16px; gap: 12px; }
  .acc-avatar { width: 58px; height: 58px; font-size: 24px; }
  /* Long unbroken emails/names must wrap, not push past the card edge at 360px */
  .acc-email, .acc-name { overflow-wrap: anywhere; }
  .acc-edit { padding: 16px; }
  .acc-l { margin: 10px 0 5px; }
  .acc-strength, .acc-mkt { padding: 10px 12px; }
  .acc-priv { padding: 11px 12px; margin-top: 8px; }
  .acc-priv-txt small { font-size: 11px; }

  /* Tap targets >= comfortable on phone */
  .acc-cam { width: 32px; height: 32px; }
  .acc-gimg-x { width: 26px; height: 26px; }

  /* Pass 2 — calmer, first-glance-readable screens (presentation only) */
  /* Redundant instruction: avatar camera badge + "Add" tile already show the affordance */
  .acc-hint { display: none; }
  /* Fine print repeats the hero copy / .acc-known banner / button label in every state */
  .acc-fine { display: none; }
  /* One visual level per card: flatten inner grey boxes to background-only */
  .acc-mkt, .acc-strength { border: none; border-radius: 10px; }
  /* All three marketing rows share the same Mail icon — hide the misleading chrome;
     the bold channel name (Email/SMS/WhatsApp) identifies each row */
  .acc-priv-mkt > svg { display: none; }
  /* Helper suffixes that duplicate the per-row descriptions / textarea placeholder */
  .acc-opt-dup { display: none; }
  /* Type scale: no text below 11px; buttons 14-15px */
  .acc-powered-bb { font-size: 11px; }
  .acc-btn { font-size: 15px; }
  /* "Works everywhere" is already said by the hero + the .acc-known banner */
  .acc-powered-tag { display: none; }
}
.acc.acc-signed-in { max-width: none; display: grid; grid-template-columns: 290px minmax(0, 1fr); gap: 28px; align-items: start; }.acc-summary { min-width: 0; }.acc-prof { flex-direction: column; align-items: flex-start; padding: 22px; }.acc-email { overflow-wrap: anywhere; }.acc-links { grid-template-columns: 1fr; gap: 0; margin-top: 16px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--surface); }.acc-link { flex-direction: row; justify-content: flex-start; border: 0; border-bottom: 1px solid var(--border); border-radius: 0; min-height: 52px; padding: 14px 18px; }.acc-link:last-child { border-bottom: 0; }.acc-edit { margin: 0; padding: 0; background: transparent; border: 0; }.acc-section { padding: 26px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); margin-bottom: 20px; }.acc-section-title { font-size: 19px; margin: 0 0 8px; font-weight: 700; }.acc-section-intro, .acc-save-hint { color: var(--text-muted); font-size: 13px; line-height: 1.6; margin: 0 0 18px; }.acc-subsection-title { font-size: 15px; margin: 26px 0 10px; }.acc-priv { min-height: 70px; border-radius: 10px; }.acc-priv-mkt { margin-bottom: 0; border: 0; border-bottom: 1px solid var(--border); border-radius: 0; padding: 16px 0; }.acc-priv-mkt:last-child { border-bottom: 0; }.acc-save { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 12px 24px; }.acc-save-hint { flex: 1 1 240px; margin: 0; }.acc-save .acc-btn { width: auto; min-width: 160px; margin: 0; font-size: 14px; }.acc-cam { width: 36px; height: 36px; }.acc-gimg-x { min-width: 32px; min-height: 32px; }.acc-field input { min-width: 0; }.acc-out { min-height: 44px; }
@media(max-width:860px) { .acc.acc-signed-in { grid-template-columns: 1fr; gap: 22px; }.acc-prof { flex-direction: row; align-items: center; }.acc-links { grid-template-columns: 1fr 1fr; }.acc-link { font-size: 12px; padding: 12px; border-bottom: 0; }.acc-section { padding: 20px 16px; }.acc-bio, .acc-field input { font-size: 16px; }.acc-save .acc-btn { width: 100%; } }
@media(max-width:860px) { .acc-summary { display: contents; }.acc-prof { grid-row: 1; }.acc-edit { grid-row: 2; }.acc-links { grid-row: 3; }.acc-out { grid-row: 4; } }
</style>
