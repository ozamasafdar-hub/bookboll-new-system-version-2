<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import QRCode from 'qrcode'
import { Download, X, ScanLine } from 'lucide-vue-next'
import customerIcon from '../assets/mobile-apps/bookboll-customer.png'
import adminIcon from '../assets/mobile-apps/bookboll-admin.png'
import appStoreIcon from '../assets/mobile-apps/app-store.webp'
import playStoreIcon from '../assets/mobile-apps/google-play.png'
import { MOBILE_APPS, mobilePlatform, insideInstalledApp, canShowAppPrompt, rememberAppPrompt, promptKey } from '../lib/mobileApps.js'
import { clearMobileAppStatus, fetchMobileAppStatus, setMobileAppSelfReport, shouldOfferApp } from '../lib/mobileAppStatus.js'
import { apiFetch } from '../lib/api.js'

const props = defineProps({
  audience: { type: String, required: true },
  accountId: { type: [String, Number], default: '' },
  visitKey: { type: String, default: '' },
  context: { type: Object, default: null },
  blocked: Boolean,
})
const emit = defineEmits(['visibility-change'])
const app = computed(() => MOBILE_APPS[props.audience])
const platform = ref('desktop')
const appIcon = computed(() => props.audience === 'admin' ? adminIcon : customerIcon)
const qr = ref('')
const card = ref(null)
const height = ref(0)
const active = ref(false)
const dismissedVisit = ref(false)
const obstructed = ref(true)
const visible = computed(() => !!(active.value && props.context && app.value && !props.blocked && !obstructed.value))
const downloadUrl = computed(() => app.value?.download)
let storage, timer, observer, resize, frame, mounted = false, installed = false, generation = 0

// The server knows what the browser cannot: whether this ACCOUNT has signed in
// from the native app. It can only ever take the prompt away — a server that
// cannot answer leaves the existing per-visit behaviour exactly as it was.
const serverStatus = ref({ known: false, status: null })
const allowedByServer = () => shouldOfferApp(serverStatus.value, canShowAppPrompt(props.audience, storage, props.accountId))
async function refreshServerStatus() {
  if (!props.accountId) { serverStatus.value = { known: false, status: null }; return }
  const asked = props.accountId
  const result = await fetchMobileAppStatus(apiFetch, props.audience, asked)
  // Ignore an answer for an identity that is no longer the one signed in.
  if (asked !== props.accountId) return
  serverStatus.value = result
  if (result.known && result.status.suppress_prompt) active.value = false
}

function dismiss(action = 'dismissed') {
  // The browser record is kept either way, so the preference holds even when
  // the account-wide write fails or the server does not support it.
  rememberAppPrompt(props.audience, action, storage, props.accountId)
  if (action === 'installed' && props.accountId) {
    // Account-wide, so it survives a new device. Never awaited: dismissing a
    // card must not wait on the network.
    setMobileAppSelfReport(apiFetch, props.audience, props.accountId, true).catch(() => {})
  }
  dismissedVisit.value = true
  active.value = false
}
function open() {
  if (!mounted || installed || !props.context) return
  clearTimeout(timer)
  dismissedVisit.value = false
  // An explicit request can reopen the card without erasing saved preferences.
  active.value = true
}
defineExpose({ open })
function detectObstruction() {
  frame = 0
  // Overlays and active typing own the screen. Never cover checkout, a drawer or a keyboard.
  const editing = document.activeElement?.matches('input:not([type="radio"]):not([type="checkbox"]):not([type="button"]):not([type="submit"]), textarea, select, [contenteditable="true"]')
  const overlays = document.querySelectorAll('[aria-modal="true"], [role="dialog"], dialog[open], .bb-drawer-backdrop, .modal-overlay, .crc')
  obstructed.value = !!editing || [...overlays].some(el => !el.closest('[inert], [aria-hidden="true"]') && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden')
}
function inspect() { if (!frame) frame = requestAnimationFrame(detectObstruction) }
function schedule() {
  clearTimeout(timer)
  if (!props.context || installed) { active.value = false; return }
  if (!mounted || dismissedVisit.value || active.value || props.blocked || obstructed.value || !allowedByServer()) return
  // Let the route and its content settle before offering a secondary action.
  timer = setTimeout(() => {
    if (dismissedVisit.value || !props.context || props.blocked || obstructed.value || !allowedByServer()) return
    active.value = true
  }, 1800)
}
watch([() => props.visitKey, () => props.context?.title, () => props.context?.body, () => props.accountId], ([, , , accountId], [, , , previousAccountId]) => {
  active.value = false
  dismissedVisit.value = false
  if (accountId !== previousAccountId) {
    // Sign-out or an account switch: never carry one identity's answer over.
    clearMobileAppStatus(previousAccountId)
    serverStatus.value = { known: false, status: null }
    // Asked alongside, not before: waiting on the network here would delay a
    // prompt the page would otherwise show, and the answer can only ever take
    // the prompt away — which the scheduled check re-reads before it opens.
    refreshServerStatus()
  }
  schedule()
})
watch([() => props.blocked, obstructed], schedule)
watch(visible, async value => {
  emit('visibility-change', value)
  await nextTick()
  resize?.disconnect()
  if (value && card.value) {
    height.value = card.value.getBoundingClientRect().height
    resize?.observe(card.value)
  }
})
watch([downloadUrl, visible], async ([url, shown]) => {
  const request = ++generation
  qr.value = ''
  if (!shown || platform.value !== 'desktop' || !url) return
  try {
    const result = await QRCode.toDataURL(url, { width: 240, margin: 4, errorCorrectionLevel: 'M', color: { dark: '#101d2a', light: '#ffffff' } })
    if (request === generation) qr.value = result
  } catch { /* The download link remains usable if canvas/QR generation fails. */ }
})
function onStorage(event) {
  if (event.key === promptKey(props.audience, props.accountId) && !allowedByServer()) active.value = false
}
onMounted(() => {
  mounted = true
  try { storage = window.localStorage } catch { /* browser blocks persistence */ }
  platform.value = mobilePlatform(navigator)
  installed = insideInstalledApp(window, navigator)
  detectObstruction()
  observer = new MutationObserver(inspect)
  observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'open', 'hidden', 'inert', 'aria-hidden', 'aria-modal'] })
  resize = new ResizeObserver(entries => { height.value = entries[0]?.borderBoxSize?.[0]?.blockSize || card.value?.getBoundingClientRect().height || 0 })
  document.addEventListener('focusin', inspect)
  document.addEventListener('focusout', inspect)
  window.addEventListener('storage', onStorage)
  schedule()
  // Asked after the first schedule, so a slow or absent answer never delays
  // the prompt the page would already have shown.
  refreshServerStatus()
})
onUnmounted(() => {
  mounted = false
  clearTimeout(timer)
  cancelAnimationFrame(frame)
  observer?.disconnect()
  resize?.disconnect()
  document.removeEventListener('focusin', inspect)
  document.removeEventListener('focusout', inspect)
  window.removeEventListener('storage', onStorage)
  emit('visibility-change', false)
})
</script>
<template>
  <!-- Leave room to scroll the last page action above this bottom-pinned suggestion. -->
  <div v-if="visible" class="app-download-space" :style="{ height: `${height + 24}px` }" aria-hidden="true"></div>
  <aside v-if="visible" ref="card" class="app-download" :class="{ 'app-download-desktop': platform === 'desktop', 'app-download-admin': audience === 'admin' }" :aria-label="`Get the ${app.name} app`" @keydown.esc.stop="dismiss()">
    <button class="app-download-close" type="button" aria-label="Dismiss app suggestion for this visit" title="Hide for this visit" @click="dismiss()"><X :size="18" /></button>
    <img class="app-download-icon" :src="appIcon" :alt="`${app.name} app icon`" width="56" height="56" />
    <div class="app-download-main">
    <div class="app-download-copy">
      <div class="app-download-name" data-no-translate>{{ app.name }}</div>
      <h2>{{ context.title }}</h2>
      <p>{{ context.body }}</p>
    </div>
    <div class="app-download-stores" aria-label="Available on the App Store and Google Play">
      <span><img :src="appStoreIcon" alt="" width="22" height="22" /><span data-no-translate>App Store</span></span>
      <span><img :src="playStoreIcon" alt="" width="22" height="22" /><span data-no-translate>Google Play</span></span>
    </div>
    <div class="app-download-actions">
      <a class="app-download-cta" :href="downloadUrl" target="_blank" rel="noopener" @click="dismiss('download')"><Download :size="17" /> Get the app</a>
      <button class="app-download-have" type="button" @click="dismiss('installed')">I already have the app</button>
    </div>
    </div>
    <div v-if="platform === 'desktop'" class="app-download-qr">
      <img v-if="qr" :src="qr" :alt="`Scan to download ${app.name} on iPhone or Android`" width="112" height="112" />
      <div v-else class="app-download-qr-placeholder"><ScanLine :size="32" /><span>Use Get the app</span></div>
      <span>Scan to download</span>
      <small>iPhone &amp; Android</small>
    </div>
  </aside>
</template>

<style scoped>
.app-download{--surface:#142437;--text:#f7faff;--text-muted:#c1cfe0;--text-secondary:#e6edf7;--border:#395067;--bg:#213850;--brand-ink:#9ebfff;color-scheme:dark;position:fixed;left:50%;transform:translateX(-50%);bottom:calc(18px + env(safe-area-inset-bottom,0px));z-index:120;width:820px;max-width:calc(100vw - 40px);padding:20px 46px 20px 20px;box-sizing:border-box;display:grid;grid-template-columns:56px minmax(0,1fr);align-items:start;gap:18px;border:1px solid var(--border);border-radius:16px;background:var(--surface);color:var(--text);box-shadow:0 12px 40px #08152633;text-align:start;font-family:inherit}
html.cust-shell.cust-dark .app-download,html.adm-shell.adm-dark .app-download{--surface:#fff;--text:#101d2a;--text-muted:#546579;--text-secondary:#25364a;--border:#c8d5e4;--bg:#edf3fa;--brand-ink:#1a54c6;color-scheme:light}
.app-download-desktop{grid-template-columns:56px minmax(0,1fr) 130px}
.app-download-close{position:absolute;inset-inline-end:2px;top:2px;width:40px;height:40px;border:0;border-radius:10px;background:transparent;color:var(--text-muted);display:grid;place-items:center;cursor:pointer}
.app-download-close:hover,.app-download-have:hover{background:var(--bg);color:var(--text)}
.app-download-icon{display:block;width:56px;height:56px;border-radius:13px;object-fit:contain;margin-top:3px}
.app-download-main{min-width:0}.app-download-name{font-weight:600;font-size:11px;color:var(--text-muted);margin:0 0 4px}.app-download h2{font-size:18px;font-weight:750;line-height:1.3;margin:0 0 5px;letter-spacing:-.02em;color:var(--text)}.app-download p{font-size:13px;line-height:1.45;margin:0;color:var(--text-muted);max-width:52ch}
.app-download-stores{display:flex;align-items:center;flex-wrap:wrap;gap:14px;margin-top:9px}.app-download-stores>span{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-secondary);white-space:nowrap}.app-download-stores img{display:block;width:18px;height:18px;object-fit:contain}
.app-download-actions{display:flex;align-items:center;flex-wrap:wrap;gap:6px 14px;margin-top:10px}.app-download-cta{display:inline-flex;justify-content:center;align-items:center;gap:7px;min-height:40px;padding:8px 15px;box-sizing:border-box;background:#2563eb;color:white;font-size:13px;font-weight:700;text-decoration:none;border-radius:8px}.app-download-admin .app-download-cta{background:#087f77}.app-download-cta:hover{filter:brightness(1.08)}.app-download-have{border:0;border-radius:7px;min-height:40px;padding:6px 0;background:transparent;color:var(--text-muted);font-size:11px;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
.app-download-qr{align-self:center;display:flex;flex-direction:column;align-items:center;border-inline-start:1px solid var(--border);padding-inline-start:18px;gap:4px;text-align:center}.app-download-qr img{display:block;width:112px;height:112px;border-radius:7px;background:white}.app-download-qr>span{font-size:11px;font-weight:650;margin-top:2px}.app-download-qr small{font-size:10px;color:var(--text-muted)}.app-download-qr-placeholder{width:112px;height:112px;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:10px;font-size:11px;color:var(--text-muted)}.app-download :is(button,a):focus-visible{outline:3px solid var(--brand-ink);outline-offset:3px}
@media(min-width:1000px){.app-download-admin{left:calc(50% + 115px);max-width:calc(100vw - 278px)}}
@media(max-width:767px){.app-download{width:480px;max-width:calc(100vw - 24px);bottom:calc(12px + env(safe-area-inset-bottom,0px));padding:18px;gap:12px;grid-template-columns:44px minmax(0,1fr)}.cust:not(.cust-inflow) .app-download{bottom:calc(82px + env(safe-area-inset-bottom,0px))}.app-download-icon{width:44px;height:44px;border-radius:10px}.app-download-copy{padding-inline-end:18px}.app-download h2{font-size:16px}.app-download p{font-size:12px}.app-download-stores{gap:10px}.app-download-actions{gap:2px 10px}.app-download-have{font-size:11px}.app-download-cta{font-size:12px;padding-inline:11px}.app-download-desktop{grid-template-columns:44px minmax(0,1fr)}.app-download-desktop .app-download-qr{grid-column:2;flex-direction:row;justify-content:flex-start;gap:10px;border:0;padding:10px 0 0;margin:0;border-top:1px solid var(--border)}.app-download-desktop .app-download-qr img,.app-download-desktop .app-download-qr-placeholder{width:96px;height:96px;flex-shrink:0}.app-download-desktop .app-download-qr small{display:none}.app-download-desktop .app-download-qr>span{max-width:72px;text-align:start;line-height:1.5}}
@media(max-width:380px){.app-download-actions{align-items:stretch;flex-direction:column}.app-download-have{min-height:32px}.app-download-stores{gap:7px}.app-download-stores>span{font-size:10px}}
@media(max-height:520px){.app-download{max-height:calc(100dvh - 105px);overflow-y:auto}}@media print{.app-download,.app-download-space{display:none}}
</style>
