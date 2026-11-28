<script setup>
/**
 * Tracking.vue — "My Website → Tracking": connect analytics & marketing pixels.
 *
 * Dual-mode (mirrors Brand.vue):
 *   • PROVIDER      → saves tracking_* columns on the owner's admin_users row. Fires ONLY on
 *                     that provider's white-label storefront (<slug>.bookboll.com).
 *   • SUPER ADMIN   → saves tracking_* keys in platform_settings. Fires ONLY on the main
 *                     Bookboll marketplace (apex bookboll.com).
 *
 * The scoping is enforced by lib/tracking.js, which injects the ACTIVE brand's tags only
 * (provider's on their site, platform's on the apex) — so a provider only ever receives data
 * from their own site, never the marketplace or another business's site.
 *
 * Tracking is stored per scope: the marketplace's tags for a platform admin, the
 * provider's own for a provider — the API decides which from the session.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { loadSiteSettings, saveSiteSettings } from '../lib/siteSettings.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useToast } from '../lib/toast.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { providerSlug, brandPreviewUrl, marketplaceUrl, BRAND_APEX } from '../lib/brand.js'
import { isGa4, isGtm, isMetaPix } from '../lib/tracking.js'
import { apiFetch, apiBaseUrl } from '../lib/api.js'
import { isTrackingTestHost } from '../lib/trackingEnvironment.js'
import {
  LineChart, Check, Loader, ExternalLink, Info, ShieldCheck, Code2, AlertTriangle, Globe2, Store,
} from 'lucide-vue-next'

const { currentOwnerUserId, isSuperAdmin, role: actorRole } = useCurrentUser()
const toast = useToast()

const KEYS = ['tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head']
const ownerId = computed(() => currentOwnerUserId.value)
// Editing the MAIN Bookboll marketplace (apex, platform_settings) is HQ-only (Super Admin +
// Platform Staff with no provider context) — same rule as Brand.vue's marketplace editor.
const isPlatformMode = computed(() => (isSuperAdmin.value || actorRole.value === 'platform_staff') && !ownerId.value)

const loading = ref(true)
const saving = ref(false)
const ownerSlug = ref('')
const catalog = ref(null), catalogError = ref(''), catalogLoading = ref(false), catalogCurrency = ref('')
let catalogRequest = 0, settingsRequest = 0
const testWorkspace = isTrackingTestHost(window.location.hostname, import.meta.env.VITE_TRACKING_ENV, import.meta.env.VITE_PREVIEW_HOSTS)
const testPixel = import.meta.env.VITE_TEST_META_PIXEL || ''
const feedUrl = computed(() => {
  if (!isPlatformMode.value && !ownerSlug.value) return ''
  const url = new URL(`${apiBaseUrl()}/marketplace/meta-catalog.csv`, window.location.origin)
  if (!isPlatformMode.value) url.searchParams.set('brand_slug', ownerSlug.value)
  if (catalogCurrency.value) url.searchParams.set('currency', catalogCurrency.value)
  return url.href
})
async function checkCatalog() {
  const request = ++catalogRequest
  if (!isPlatformMode.value && !ownerSlug.value) return
  catalogLoading.value = true; catalogError.value = ''
  try {
    const result = await apiFetch('/marketplace/meta-catalog', { query: isPlatformMode.value ? {} : { brand_slug: ownerSlug.value } })
    if (request !== catalogRequest) return
    if (!result.success) throw Error(result.message || 'Could not check the catalog. Try again.')
    catalog.value = result.data
  } catch (error) { if (request === catalogRequest) catalogError.value = error.message }
  finally { if (request === catalogRequest) catalogLoading.value = false }
}
async function copyFeed() {
  try { await navigator.clipboard.writeText(feedUrl.value); toast.success('Catalog feed link copied.') }
  catch { toast.error('Select the feed link below and copy it.') }
}
const eventGuide = [
  ['PageView', 'A customer opens a page or navigates to another page.'],
  ['ViewContent', 'A listing or membership detail loads successfully. Only bookable listings carry catalog item IDs.'],
  ['AddToCart', 'A customer selects an available booking slot and the hold succeeds.'],
  ['InitiateCheckout', 'Stripe checkout is created. Value is the amount due online, in its payment currency.'],
  ['Purchase', 'Stripe confirms payment and Bookboll verifies the receipt. Deposits report only the amount paid online.'],
  ['Schedule', 'Bookboll confirms the booking, including cash-on-arrival and covered deposits. No payment value is reported.'],
  ['Contact / FindLocation', 'The customer chooses a contact method or opens directions. This does not confirm an enrollment.'],
  ['Search / CompleteRegistration', 'The customer submits a search or successfully creates an account.'],
]

const f = reactive({ meta_pixel: '', ga4: '', gtm: '', head: '' })

// ── Per-field validity (empty = fine; filled must be well-formed) ──────────────
const metaState = computed(() => !f.meta_pixel.trim() ? '' : (isMetaPix(f.meta_pixel) ? 'ok' : 'bad'))
const ga4State  = computed(() => !f.ga4.trim() ? '' : (isGa4(f.ga4) ? 'ok' : 'bad'))
const gtmState  = computed(() => !f.gtm.trim() ? '' : (isGtm(f.gtm) ? 'ok' : 'bad'))
const hasInvalid = computed(() => [metaState.value, ga4State.value, gtmState.value].includes('bad'))
const canSave = computed(() => !saving.value && !loading.value && !hasInvalid.value)

// What will actually fire, for the summary strip.
const active = computed(() => {
  const out = []
  if (metaState.value === 'ok') out.push('Meta Pixel')
  if (ga4State.value === 'ok') out.push('Google Analytics 4')
  if (gtmState.value === 'ok') out.push('Google Tag Manager')
  if (f.head.trim()) out.push('Custom code')
  return out
})

// Where these tags fire (a clickable test link).
const fireUrl = computed(() => isPlatformMode.value
  ? marketplaceUrl('/')
  : (ownerSlug.value ? brandPreviewUrl(ownerSlug.value) : ''))
const fireLabel = computed(() => isPlatformMode.value
  ? BRAND_APEX
  : (ownerSlug.value ? `${ownerSlug.value}.${BRAND_APEX}` : 'your storefront'))

async function load() {
  const request = ++settingsRequest
  ++catalogRequest
  catalog.value = null; catalogError.value = ''; catalogCurrency.value = ''; catalogLoading.value = false
  ownerSlug.value = ''
  loading.value = true
  // The API returns the marketplace's tracking for a platform admin, or the
  // provider's own — the caller doesn't choose.
  const { mode, settings, username } = await loadSiteSettings()
  if (request !== settingsRequest) return
  f.meta_pixel = settings.tracking_meta_pixel || ''
  f.ga4  = settings.tracking_ga4 || ''
  f.gtm  = settings.tracking_gtm || ''
  f.head = settings.tracking_head || ''
  if (mode === 'provider') ownerSlug.value = settings.brand_subdomain || username || ''
  loading.value = false
  clearDirty()
  checkCatalog()
}
onMounted(load)
// Reload when the acting context changes (e.g. an owner is resolved, or the demo role switch
// flips between provider and platform mode) so the form always shows the right saved values.
watch([isPlatformMode, ownerId], load)

async function save() {
  if (!canSave.value) return
  saving.value = true
  try {
    const res = await saveSiteSettings({
      tracking_meta_pixel: f.meta_pixel.trim(),
      tracking_ga4: f.ga4.trim(),
      tracking_gtm: f.gtm.trim(),
      tracking_head: f.head,
    })
    if (!res.success) throw new Error(res.message || 'Tracking was not saved.')
    if (isPlatformMode.value) {
      logAudit({ action: AUDIT.UPDATED, entity_type: 'platform_tracking', entity_id: 'marketplace', details: `Updated marketplace tracking — ${active.value.join(', ') || 'cleared'}` })
      toast.success('Marketplace tracking saved.')
    } else {
      logAudit({ action: AUDIT.UPDATED, entity_type: 'tracking', entity_id: ownerId.value, details: `Updated storefront tracking — ${active.value.join(', ') || 'cleared'}` })
      toast.success('Tracking saved. It will fire on your storefront.')
    }
    clearDirty()
  } catch (e) {
    toast.error(e?.message || 'Could not save.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page" @input="markDirty">
    <div class="page-header">
      <div>
        <h1 class="page-title">Tracking</h1>
        <div class="page-subtitle">Connect Meta, Google Tag Manager &amp; Google Analytics to your website</div>
      </div>
    </div>

    <p v-if="testWorkspace" class="trk-scope plat" role="status">Test workspace: saved live pixels, Google tags and custom code are inactive here. {{ testPixel ? `Test events use pixel ${testPixel}.` : 'Connect a separate test pixel in the deployment settings to verify delivery.' }}</p>
    <!-- Scope callout — makes the data-isolation guarantee explicit -->
    <div class="trk-scope" :class="isPlatformMode ? 'plat' : 'prov'">
      <ShieldCheck :size="20" />
      <div v-if="isPlatformMode">
        <b>Marketplace tracking.</b> These tags fire on the main Bookboll marketplace
        (<code>{{ BRAND_APEX }}</code>) only — never on any provider's own website.
      </div>
      <div v-else>
        <b>Your storefront only.</b> These tags fire on <b>your</b> website
        (<code>{{ fireLabel }}</code>) only. They never run on the Bookboll marketplace or any
        other business's site — so you only ever receive <b>your</b> traffic &amp; conversions.
      </div>
    </div>

    <div class="card trk-card">
      <!-- Meta Pixel -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label" for="tracking-meta">Meta Pixel ID</label>
          <span v-if="metaState==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="metaState==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Numbers only</span>
        </div>
        <input id="tracking-meta" v-model="f.meta_pixel" class="trk-input" :class="{ bad: metaState==='bad' }" placeholder="e.g. 1234567890123456" inputmode="numeric" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Meta Events Manager → Data sources → your pixel → <b>Settings</b>. A 15–16 digit number.</p>
        <p class="trk-hint">Enter the ID once here. Do not also paste its base code below or install the same pixel through Tag Manager.</p>
      </div>

      <!-- GA4 -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label" for="tracking-ga">Google Analytics 4 — Measurement ID</label>
          <span v-if="ga4State==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="ga4State==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Format G-XXXXXXX</span>
        </div>
        <input id="tracking-ga" v-model="f.ga4" class="trk-input" :class="{ bad: ga4State==='bad' }" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Google Analytics → Admin → Data streams → your web stream → <b>Measurement ID</b>.</p>
      </div>

      <!-- GTM -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label" for="tracking-gtm">Google Tag Manager — Container ID</label>
          <span v-if="gtmState==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="gtmState==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Format GTM-XXXXXX</span>
        </div>
        <input id="tracking-gtm" v-model="f.gtm" class="trk-input" :class="{ bad: gtmState==='bad' }" placeholder="GTM-XXXXXXX" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Tag Manager → your container → the <b>GTM-XXXXXXX</b> ID at the top.</p>
      </div>

      <!-- Advanced: raw head code -->
      <details class="trk-adv">
        <summary><Code2 :size="15" /> Advanced — custom &lt;head&gt; code</summary>
        <div class="trk-adv-body">
          <p class="trk-hint">Paste any extra tags/scripts (TikTok pixel, LinkedIn Insight, verification meta, etc.). Added to the <code>&lt;head&gt;</code> of {{ isPlatformMode ? 'the marketplace' : 'your storefront' }} only.</p>
          <textarea aria-label="Custom head code" v-model="f.head" class="trk-textarea" rows="6" spellcheck="false" placeholder="&lt;!-- e.g. --&gt;&#10;&lt;script&gt; /* your snippet */ &lt;/script&gt;"></textarea>
          <div class="trk-warn"><AlertTriangle :size="14" /> Only paste code from sources you trust — it runs on your live site.</div>
        </div>
      </details>

      <!-- Live summary of what fires -->
      <div class="trk-summary">
        <div class="trk-summary-h"><LineChart :size="15" /> Configured services</div>
        <div v-if="active.length" class="trk-tags">
          <span v-for="a in active" :key="a" class="trk-tag"><Check :size="12" /> {{ a }}</span>
        </div>
        <div v-else class="trk-none">Nothing connected yet — add an ID above to start measuring traffic.</div>
        <p class="trk-summary-note"><Info :size="13" /> Page views are tracked automatically on every screen, including in-app navigation.</p>
      </div>

      <div class="trk-actions">
        <a v-if="fireUrl" :href="fireUrl" target="_blank" rel="noopener" class="trk-open">
          <component :is="isPlatformMode ? Store : Globe2" :size="15" />
          {{ isPlatformMode ? 'Open marketplace' : 'Open my storefront' }} <ExternalLink :size="13" />
        </a>
        <button class="btn btn-primary trk-save" :disabled="!canSave" @click="save">
          <Loader v-if="saving" :size="16" class="trk-spin" /><Check v-else :size="16" />
          {{ saving ? 'Saving…' : 'Save tracking' }}
        </button>
      </div>
    </div>

    <section class="card trk-catalog" aria-labelledby="catalog-title">
      <div class="trk-section-head"><div><h2 id="catalog-title">Meta catalog</h2><p>Advertise your bookable listings with the same item IDs used by your pixel.</p></div><button class="btn btn-secondary" :disabled="catalogLoading" @click="checkCatalog">{{ catalogLoading ? 'Checking…' : 'Check listings' }}</button></div>
      <p v-if="catalogError" role="alert">{{ catalogError }}</p>
      <template v-if="catalog">
        <p><b>{{ catalog.items.length }} ready listings</b><span v-if="catalog.omitted.length"> · {{ catalog.omitted.length }} need a price or photo</span></p>
        <p class="trk-hint">{{ isPlatformMode ? 'Only listings visible on the marketplace are included.' : 'Only listings visible on your published business website are included.' }} Private listings, staff-only listings and suspended businesses are excluded. Membership information and community events are not catalog products.</p>
        <label class="trk-label" for="catalog-currency">Feed currency</label>
        <select id="catalog-currency" v-model="catalogCurrency" class="trk-input"><option value="">All listed currencies</option><option v-for="currency in catalog.currencies" :key="currency">{{ currency }}</option></select>
        <div v-if="feedUrl" class="trk-feed"><input aria-label="Catalog feed URL" readonly :value="feedUrl" class="trk-input" @focus="$event.target.select()"/><button class="btn btn-secondary" @click="copyFeed">Copy link</button><a :href="feedUrl" class="btn btn-secondary" target="_blank" rel="noopener">Open feed</a></div>
        <ol class="trk-steps"><li>In Meta Commerce Manager, create or select your catalog and add this URL as a scheduled data feed.</li><li>Connect this website’s pixel as the catalog event source. Connecting a pixel here does not create a Meta catalog automatically.</li><li>Review Meta’s import results and event match rate. Catalog prices are advertised starting prices; Purchase values are actual online payments.</li></ol>
        <details v-if="catalog.omitted.length"><summary>Listings to fix ({{ catalog.omitted.length }})</summary><ul><li v-for="item in catalog.omitted" :key="item.id"><b>{{ item.name || `Listing ${item.id}` }}</b> — {{ item.reason }}</li></ul></details>
      </template>
    </section>

    <section class="card trk-catalog" aria-labelledby="events-title">
      <h2 id="events-title">Events Bookboll sends</h2>
      <dl class="trk-events"><div v-for="[event, description] in eventGuide" :key="event"><dt>{{ event }}</dt><dd>{{ description }}</dd></div></dl>
      <p class="trk-hint">Failed payments, cancelled checkout and opening a thank-you link do not create a Purchase. Refreshing a verified receipt does not send it again.</p>
      <p class="trk-hint">The app sends explicit events and disables automatic configuration for its pixel instance. Existing Meta Event Setup Tool rules and Tag Manager triggers can still duplicate events. Review those with the pixel owner before a production rollout. This page does not change settings in Meta Events Manager.</p>
      <p class="trk-hint">Payment card entry happens on Stripe, so Bookboll does not guess an AddPaymentInfo event. Offline membership enquiries are never reported as paid subscriptions or trials. Browser blockers can prevent event delivery.</p>
    </section>
  </div>
</template>

<style scoped>
.trk-catalog{max-width:980px;padding:24px;margin-top:24px;display:grid;gap:16px}.trk-catalog h2{font-size:20px;margin:0}.trk-catalog p{margin:0;line-height:1.6}.trk-section-head{display:flex;justify-content:space-between;align-items:start;gap:20px}.trk-section-head p{color:var(--text-muted);font-size:13px;margin-top:6px}.trk-feed{display:flex;gap:8px;align-items:center}.trk-feed input{min-width:0;flex:1}.trk-feed .btn{white-space:nowrap}.trk-steps{padding-left:22px;margin:0;display:grid;gap:12px;font-size:13px;line-height:1.6}.trk-events{margin:0}.trk-events>div{display:grid;grid-template-columns:190px 1fr;gap:20px;padding:14px 0;border-bottom:1px solid var(--border)}.trk-events dt{font-weight:700;font-size:13px}.trk-events dd{margin:0;color:var(--text-muted);font-size:13px;line-height:1.55}.trk-catalog select{max-width:240px}.trk-catalog summary{cursor:pointer;font-weight:600}.trk-catalog li{font-size:13px;line-height:1.8}@media(max-width:650px){.trk-feed{flex-wrap:wrap}.trk-feed input{flex-basis:100%}.trk-events>div{grid-template-columns:1fr;gap:5px}.trk-section-head{flex-direction:column}}
.trk-scope { display: flex; align-items: flex-start; gap: 11px; border-radius: 14px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; margin-bottom: 16px; }
.trk-scope svg { flex-shrink: 0; margin-top: 1px; }
.trk-scope.prov { background: var(--positive-bg, #ecfdf5); border: 1px solid var(--positive-border, #a7f3d0); color: var(--positive-ink, #065f46); }
.trk-scope.prov svg { color: var(--positive-ink, #059669); }
.trk-scope.plat { background: var(--info-bg, #eff6ff); border: 1px solid var(--info-border, #bfdbfe); color: var(--info-ink, #1e40af); }
.trk-scope.plat svg { color: var(--info-ink, #2563eb); }
.trk-scope code { background: rgba(15,23,42,.07); padding: 1px 6px; border-radius: 5px; font-size: 12.5px; font-weight: 700; }

.trk-card { display: flex; flex-direction: column; gap: 22px; max-width: 680px; padding: 22px 24px; }
@media (max-width: 560px) { .trk-card { padding: 16px; } }
.trk-field { display: flex; flex-direction: column; gap: 6px; }
.trk-field-head { display: flex; align-items: center; gap: 10px; }
.trk-label { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.trk-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
.trk-chip.ok { color: var(--positive-ink, #15803d); background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); }
.trk-chip.bad { color: var(--warning-ink, #b45309); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); }
.trk-input { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 15px; font-family: inherit; outline: none; background: var(--card-bg, #fff); color: var(--text, #0f172a); }
.trk-input:focus { border-color: var(--primary, #0f766e); }
.trk-input.bad { border-color: #f59e0b; }
.trk-hint { font-size: 12px; color: var(--text-muted, #94a3b8); line-height: 1.5; margin: 0; }
.trk-hint code { background: rgba(15,23,42,.06); padding: 0 4px; border-radius: 4px; }

.trk-adv { border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 4px 14px; }
.trk-adv summary { cursor: pointer; font-size: 13.5px; font-weight: 700; color: var(--text, #334155); display: flex; align-items: center; gap: 8px; padding: 10px 0; list-style: none; }
.trk-adv summary::-webkit-details-marker { display: none; }
.trk-adv-body { padding: 4px 0 12px; display: flex; flex-direction: column; gap: 8px; }
.trk-textarea { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; line-height: 1.5; resize: vertical; outline: none; background: var(--card-bg, #fff); color: var(--text, #0f172a); }
.trk-textarea:focus { border-color: var(--primary, #0f766e); }
.trk-warn { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; color: var(--warning-ink, #b45309); }

.trk-summary { background: var(--bg, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; }
.trk-summary-h { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted, #64748b); margin-bottom: 10px; }
.trk-tags { display: flex; flex-wrap: wrap; gap: 8px; }
.trk-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 999px; padding: 4px 11px; }
.trk-none { font-size: 13px; color: var(--text-muted, #94a3b8); }
.trk-summary-note { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-muted, #94a3b8); margin: 10px 0 0; }

.trk-migrate { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; line-height: 1.5; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 10px; padding: 11px 13px; }
.trk-migrate code { background: rgba(146,64,14,.1); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; }

.trk-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.trk-open { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--primary-ink, #0f766e); text-decoration: none; }
.trk-open:hover { text-decoration: underline; }
.trk-save { display: inline-flex; align-items: center; gap: 8px; }
.trk-spin { animation: trkspin 1s linear infinite; }
@keyframes trkspin { to { transform: rotate(360deg); } }

@media (max-width: 560px) {
  .trk-actions { flex-direction: column-reverse; align-items: stretch; }
  .trk-save { justify-content: center; min-height: 44px; }
  .trk-open { justify-content: center; min-height: 44px; align-items: center; }
  /* 360px: long unbroken strings (migration path, {slug}.bookboll.com) must wrap, not overflow */
  .trk-migrate code, .trk-scope code, .trk-hint code { word-break: break-all; overflow-wrap: anywhere; white-space: normal; }
  .trk-scope, .trk-migrate { min-width: 0; }
  .trk-scope > div { min-width: 0; }
  /* iOS auto-zoom: keep form fields at 16px on phones */
  .trk-input, .trk-textarea { font-size: 16px; }
  /* Let the validity chip wrap under long labels on very narrow screens */
  .trk-field-head { flex-wrap: wrap; row-gap: 4px; }
}
</style>
