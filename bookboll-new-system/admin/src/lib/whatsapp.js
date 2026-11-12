/**
 * whatsapp.js — dashboard side of the WhatsApp channel.
 *
 * PER-PROVIDER, mirroring emailSender: each provider connects THEIR OWN WhatsApp Business
 * number + token (stored on businesses.whatsapp_config) and markets their own customers
 * from it. Bookboll (super admin / no owner) uses its OWN number — stored in the platform
 * settings under 'whatsapp_config' — for its marketing and the booking funnel.
 *
 * The browser NEVER talks to Meta directly (server-to-server + token secrecy). Everything
 * goes through our Laravel backend, which resolves the right number+token from WHO IS
 * ASKING — a provider gets their own sender, a platform admin gets Bookboll's. The
 * `ownerId` arguments below are kept so call sites read the same, but the server ignores
 * them: naming your own sender is exactly the hole that would let a provider send as
 * Bookboll.
 *
 * WhatsApp rules that shape the UI:
 *  - Marketing/bulk messages MUST use Meta-approved templates (no free-form blasts).
 *  - Free-form text only inside the 24-hour window after the customer last messaged.
 */
import { ref, computed } from 'vue'
import { apiFetch } from './api.js'
import { useCurrentUser } from './currentUser.js'

export const WHATSAPP_CONFIG_KEY = 'whatsapp_config'   // platform settings key (Bookboll's number)

export function blankWhatsappConfig() {
  return {
    enabled: false,
    // ── Meta app (from developers.facebook.com → your app) ──
    app_id: '',             // App ID (Dashboard → App settings → Basic)
    app_secret: '',         // App secret (same page) — platform only; verifies webhook signatures
    business_id: '',        // Meta Business Manager ID (business.facebook.com → Business settings) — optional reference
    // ── WhatsApp product (App → WhatsApp → API Setup) ──
    phone_number_id: '',    // "Phone number ID" shown under the sender number
    waba_id: '',            // "WhatsApp Business Account ID" on the same screen
    display_number: '',     // the human-readable sender number (reference only)
    // ── Auth ──
    access_token: '',       // permanent System User token. Stored here for BOTH sides so the
                            // dashboard is self-serve at launch; the functions fall back to the
                            // WHATSAPP_ACCESS_TOKEN secret when this is empty.
    // ── Webhook (platform only — providers don't receive messages) ──
    verify_token: '',       // any string you invent; must match what you paste into Meta's webhook config
    verified_at: null,
  }
}

/** The webhook callback URL to paste into Meta (App → WhatsApp → Configuration → Webhook). */
export function webhookCallbackUrl() {
  const base = String(import.meta.env.VITE_API_URL || '').replace(/\/+$/, '').replace(/\/api$/, '')
  return base ? `${base}/api/whatsapp/webhook` : ''
}

/** A fresh random verify token (any string works — this just makes a strong one). */
export function generateVerifyToken() {
  const a = new Uint8Array(18)
  crypto.getRandomValues(a)
  return 'bb_' + [...a].map(b => b.toString(16).padStart(2, '0')).join('')
}

// ── Load / save, keyed by owner ───────────────────────────────────────────────
// A provider (ownerId set) stores config on their admin_users row. Bookboll (no ownerId)
// stores it in platform_settings and keeps the token as the function's WHATSAPP_ACCESS_TOKEN
// secret (so platform config never carries a token in the browser).
export async function loadWhatsappConfig() {
  const res = await apiFetch('/admin/whatsapp/config')
  if (!res.success) return blankWhatsappConfig()
  return { ...blankWhatsappConfig(), ...(res.data?.config || {}) }
}

/**
 * Save the config the caller owns. Where it lands — the provider's business row or the
 * platform settings — is decided on the server, so a provider cannot overwrite
 * Bookboll's sender by passing a flag.
 *
 * The platform's FULL config (token included) is persisted so the owner can finish the
 * Meta setup from the dashboard; the server falls back to the WHATSAPP_ACCESS_TOKEN env
 * value when the stored token is empty.
 */
export async function saveWhatsappConfig(_ownerId, cfg) {
  const res = await apiFetch('/admin/whatsapp/config', { method: 'PUT', body: cfg })
  if (!res.success) return { error: { message: res.message || 'Could not save.' } }
  return { config: res.data?.config || null }
}

/**
 * Connected =
 *  - provider (ownerId): enabled + a phone number id + an access token (in the config), or
 *  - platform (no ownerId): a phone number id is set (the token is the function secret,
 *    confirmed live via the `status` action rather than a stored value).
 */
export function isWhatsappConfigured(cfg, ownerId = null) {
  if (!cfg) return false
  const hasNumber = !!String(cfg.phone_number_id || '').trim()
  if (ownerId) return !!(cfg.enabled && hasNumber && String(cfg.access_token || '').trim())
  return hasNumber
}

/**
 * Run one WhatsApp action on the backend. `ownerId` in the payload is ignored server-side
 * (the sender comes from the session) and is stripped here so it never even goes over the
 * wire. Returns { ok, ... } — the same envelope the edge function used, so every call
 * site keeps its shape.
 */
export async function invokeWhatsapp(payload = {}) {
  const { ownerId: _ignored, ...body } = payload
  try {
    const res = await apiFetch('/admin/whatsapp/action', { method: 'POST', body })
    if (!res.success) {
      const first = Object.values(res.errors || {}).flat().find(v => typeof v === 'string')
      return { ok: false, error: first || res.message || 'WhatsApp request failed.' }
    }
    return res.data ?? { ok: false, error: 'Empty response from the WhatsApp service.' }
  } catch {
    return { ok: false, error: 'The WhatsApp service is not reachable.' }
  }
}

/** Digits-only WhatsApp number (country code, no +). Returns '' when clearly unusable. */
export function normalizeWaNumber(phone) {
  let d = String(phone || '').replace(/\D/g, '')
  if (d.startsWith('00')) d = d.slice(2)   // international dialing prefix → bare country code
  return d.length >= 8 ? d : ''
}

/** Replace {{1}}…{{n}} in a template body with values (missing → keep the placeholder). */
export function renderTemplateBody(bodyText, variables = []) {
  return String(bodyText || '').replace(/\{\{(\d+)\}\}/g, (_, n) => {
    const v = variables[Number(n) - 1]
    return v == null || v === '' ? `{{${n}}}` : String(v)
  })
}

/** Personalize one variable value: the literal token {name} → the customer's first name. */
export function personalizeVariable(value, customer) {
  const first = String(customer?.full_name || '').trim().split(/\s+/)[0] || 'there'
  return String(value ?? '').replaceAll('{name}', first)
}

// ── Templates: author + submit for Meta review from the dashboard ─────────────
// One platform instead of two: build the template here, we submit it to Meta via
// the edge function, and its review status (PENDING → APPROVED / REJECTED+reason)
// shows up in the same UI via list_templates.

/** Meta template names: lowercase snake_case only. */
export function slugifyTemplateName(s) {
  return String(s || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60)
}

export function createWaTemplate(ownerId, { name, language, category, headerText, bodyText, footerText, examples, headerImageUrl, headerVideoUrl }) {
  return invokeWhatsapp({ action: 'create_template', ownerId, name, language, category, headerText, bodyText, footerText, examples, headerImageUrl: headerImageUrl || undefined, headerVideoUrl: headerVideoUrl || undefined })
}

export function deleteWaTemplate(ownerId, name) {
  return invokeWhatsapp({ action: 'delete_template', ownerId, name })
}

export function listWaTemplates(ownerId) {
  return invokeWhatsapp({ action: 'list_templates', ownerId })
}

/** The recent-messages log for the caller's own sender (WhatsApp page). */
export async function loadWaOutbound(limit = 15) {
  const res = await apiFetch('/admin/whatsapp/outbound', { query: { limit } })
  if (!res.success) return { ok: false, outbound: [] }
  return { ok: true, outbound: res.data?.outbound || [] }
}

// ── Queued campaigns: compose now, auto-send when Meta approves ────────────────
// A campaign composed against a PENDING template is snapshotted into
// whatsapp_queued_campaigns. processWaQueue() — run whenever the WhatsApp page or
// the marketing composer opens — asks the backend to check template statuses and
// either fire the stored campaign (APPROVED) or record the rejection (REJECTED).

export async function queueWaCampaign({ template, payload }) {
  const res = await apiFetch('/admin/whatsapp/queue', {
    method: 'POST',
    body: {
      template_name: template.name,
      template_language: template.language || 'en_US',
      payload,
    },
  })
  if (!res.success) return { error: res.message || 'Could not queue the campaign.' }
  return { row: res.data?.row || null }
}

export async function loadWaQueue() {
  const res = await apiFetch('/admin/whatsapp/queue')
  return res.success ? (res.data?.queue || []) : []
}

export async function dismissWaQueueItem(id) {
  await apiFetch(`/admin/whatsapp/queue/${encodeURIComponent(id)}/dismiss`, { method: 'POST' })
}

export async function cancelWaQueueItem(id) {
  await apiFetch(`/admin/whatsapp/queue/${encodeURIComponent(id)}/cancel`, { method: 'POST' })
}

/**
 * The pump: check each waiting campaign's template with Meta and either fire it
 * (APPROVED) or record why it never will (REJECTED). Returns
 * { checked, sent: [row…], rejected: [row…] } so callers can toast.
 *
 * This used to run here in the browser, claiming rows with a conditional update. It now
 * runs in one backend call — same trigger (opening the WhatsApp page or the composer),
 * same result, but claiming and dispatching are a single guarded step, so two open tabs
 * can no longer both send the same campaign.
 */
export async function processWaQueue() {
  const res = await apiFetch('/admin/whatsapp/queue/process', { method: 'POST' })
  if (!res.success) return { checked: 0, sent: [], rejected: [], error: res.message }
  const d = res.data || {}
  return { checked: d.checked || 0, sent: d.sent || [], rejected: d.rejected || [], error: d.error }
}

/**
 * Reactive helper for the CURRENT business's WhatsApp sender — used by the marketing composer
 * (gate + send) and the WhatsApp settings page. Provider → their own config; Bookboll → the
 * platform config. `ownerId` is null for Bookboll (super admin).
 */
export function useProviderWhatsapp() {
  const { currentOwnerUserId } = useCurrentUser()
  const wa = ref(blankWhatsappConfig())
  const loaded = ref(false)
  async function load() {
    wa.value = await loadWhatsappConfig()
    loaded.value = true
  }
  const ownerId = computed(() => currentOwnerUserId.value || null)
  const configured = computed(() => isWhatsappConfigured(wa.value, ownerId.value))
  return { wa, loaded, load, configured, ownerId }
}
