<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { Cookie, X, ShieldCheck } from 'lucide-vue-next'
import { CONSENT_REQUIRED, cookieConsent, consentReady, initCookieConsent, saveCookieConsent } from '../lib/cookieConsent.js'

const props = defineProps({ scope: { type: String, default: 'site' } })
watch(() => props.scope, initCookieConsent, { immediate: true })
const preferences = ref(null), editing = ref(false), analytics = ref(false), marketing = ref(false)
const notice = ref(''), banner = ref(null), bannerHeight = ref(0), appCardSpace = ref(0)
// With consent not required there is nothing to ask: no banner, no settings
// link. initCookieConsent still runs above so consentReady flips and tags load.
const showBanner = computed(() => CONSENT_REQUIRED && consentReady.value && !cookieConsent.value && !editing.value)
let resize, previousFocus, appResize, appMutations, observedCard
function measureAppCard() {
  const card = document.querySelector('.app-download')
  if (card !== observedCard) {
    appResize?.disconnect()
    observedCard = card
    if (card) appResize?.observe(card)
  }
  // Leave enough scroll room below the settings link to reach it while the
  // fixed app suggestion is showing, including its bottom/navigation offset.
  appCardSpace.value = card ? Math.max(0, window.innerHeight - card.getBoundingClientRect().top) : 0
}
async function chooseCookies() {
  previousFocus = document.activeElement
  analytics.value = cookieConsent.value?.analytics === true
  marketing.value = cookieConsent.value?.marketing === true
  editing.value = true
  await nextTick()
  preferences.value?.showModal()
}
async function closePreferences() {
  preferences.value?.close()
  editing.value = false
  await nextTick()
  if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true })
  else banner.value?.querySelector('button')?.focus({ preventScroll: true })
}
function save(choice) {
  const persisted = saveCookieConsent(choice)
  notice.value = persisted ? 'Cookie choices saved. You can change them anytime.' : 'Choices applied for this visit. Your browser could not save them for next time.'
  closePreferences()
}
const acceptAll = () => save({ analytics: true, marketing: true })
const necessaryOnly = () => save({ analytics: false, marketing: false })
watch(banner, element => { resize?.disconnect(); if (element) { bannerHeight.value = element.getBoundingClientRect().height; resize?.observe(element) } })
onMounted(() => {
  resize = new ResizeObserver(entries => { bannerHeight.value = entries[0]?.target.getBoundingClientRect().height || 0 })
  if (banner.value) { bannerHeight.value = banner.value.getBoundingClientRect().height; resize.observe(banner.value) }
  appResize = new ResizeObserver(measureAppCard)
  appMutations = new MutationObserver(measureAppCard)
  appMutations.observe(document.body, { childList: true, subtree: true })
  window.addEventListener('resize', measureAppCard)
  measureAppCard()
})
onBeforeUnmount(() => { resize?.disconnect(); appResize?.disconnect(); appMutations?.disconnect(); window.removeEventListener('resize', measureAppCard) })
</script>

<template>
  <footer v-if="CONSENT_REQUIRED" class="bb-cookie-footer" :style="{ '--bb-cookie-app-space': `${appCardSpace}px` }">
    <button type="button" @click="chooseCookies"><Cookie :size="16" />Cookie settings</button>
    <span role="status">{{ notice }}</span>
  </footer>
  <div v-if="showBanner" :style="{ height: `${bannerHeight + 16}px` }" aria-hidden="true"></div>
  <Teleport to="body">
    <section v-if="showBanner" ref="banner" class="bb-cookie-banner bb-cookie-surface" aria-label="Cookie choices">
      <div class="bb-cookie-copy"><h2><Cookie :size="20" />Your cookie choices</h2><p>Necessary cookies and similar storage keep sign-in, security and bookings working. With your permission, we also use analytics to improve the site and marketing tools to measure ads.</p></div>
      <div class="bb-cookie-actions"><button type="button" class="bb-cookie-button" @click="necessaryOnly">Only necessary</button><button type="button" class="bb-cookie-button bb-cookie-accept" @click="acceptAll">Accept all</button><button type="button" class="bb-cookie-custom" @click="chooseCookies">Choose cookies</button></div>
    </section>
    <dialog v-if="CONSENT_REQUIRED" ref="preferences" class="bb-cookie-dialog bb-cookie-surface" aria-labelledby="bb-cookie-title" @cancel.prevent="closePreferences">
      <header><div><h2 id="bb-cookie-title">Cookie preferences</h2><p>Choose what this website can use in this browser.</p></div><button type="button" class="bb-cookie-close" aria-label="Close cookie preferences without saving" @click="closePreferences"><X :size="20" /></button></header>
      <div class="bb-cookie-options">
        <label><input type="checkbox" checked disabled /><span><strong>Necessary <small>Always on</small></strong><span>Sign-in, security, booking checkout and the settings you ask us to remember. These keep the website working.</span></span></label>
        <label><input v-model="analytics" type="checkbox" /><span><strong>Analytics</strong><span>Understand page visits and category clicks so we can improve the website. Includes Google Analytics when configured.</span></span></label>
        <label><input v-model="marketing" type="checkbox" /><span><strong>Marketing</strong><span>Measure advertising and help show relevant ads, including Meta Pixel when configured.</span></span></label>
        <p class="bb-cookie-note"><ShieldCheck :size="16" /><span>Tools that combine analytics and advertising need both choices. Your email, SMS and WhatsApp preferences stay separate.</span></p>
      </div>
      <footer><p>Skip optional keeps necessary storage only. Changing an allowed category may reload this page to stop its tracking.</p><div class="bb-cookie-actions"><button type="button" class="bb-cookie-button" @click="necessaryOnly">Skip optional</button><button type="button" class="bb-cookie-button bb-cookie-accept" @click="acceptAll">Accept all</button><button type="button" class="bb-cookie-button bb-cookie-save" @click="save({ analytics, marketing })">Save choices</button></div></footer>
    </dialog>
  </Teleport>
</template>

<style scoped>
.bb-cookie-surface { --cc-bg:#fff; --cc-text:#101d2a; --cc-muted:#536478; --cc-border:#dbe4ef; --cc-tint:#f3f7fc; --cc-accent:#1a54c6; color-scheme:light; background:var(--cc-bg); color:var(--cc-text); font-family:inherit; box-sizing:border-box; }
html.cust-dark.cust-shell .bb-cookie-surface, html.adm-dark.adm-shell .bb-cookie-surface { --cc-bg:#172536; --cc-text:#f2f6fc; --cc-muted:#b9c9dc; --cc-border:#3a4e65; --cc-tint:#20334a; --cc-accent:#a8c8ff; color-scheme:dark; }
.bb-cookie-banner { position:fixed; z-index:4000; inset:auto 16px 16px; display:flex; align-items:center; gap:28px; padding:22px 26px; border:1px solid var(--cc-border); border-radius:16px; box-shadow:0 8px 36px #07162629; }
.bb-cookie-copy { flex:1; min-width:0; }.bb-cookie-surface h2 { color:var(--cc-text); margin:0; font-size:20px; font-weight:700; line-height:1.3; display:flex; align-items:center; gap:10px; }.bb-cookie-surface p { font-size:13px; line-height:1.6; color:var(--cc-muted); margin:8px 0 0; max-width:76ch; }
.bb-cookie-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }.bb-cookie-banner .bb-cookie-actions { max-width:340px; }
.bb-cookie-button, .bb-cookie-custom { min-height:44px; padding:10px 15px; line-height:1.3; font-family:inherit; font-size:13px; font-weight:600; border:1px solid var(--cc-border); border-radius:8px; cursor:pointer; background:var(--cc-tint); color:var(--cc-text); white-space:nowrap; }
.bb-cookie-custom { border-color:transparent; background:transparent; text-decoration:underline; text-underline-offset:3px; color:var(--cc-accent); }.bb-cookie-save, .bb-cookie-accept { background:#1a6afd; border-color:#1a6afd; color:#fff; }
.bb-cookie-surface button:hover { filter:brightness(.96); }.bb-cookie-surface :is(button,input):focus-visible { outline:3px solid var(--cc-accent); outline-offset:3px; }
.bb-cookie-dialog { padding:0; border:1px solid var(--cc-border); border-radius:18px; width:min(600px,calc(100vw - 32px)); max-height:calc(100dvh - 32px); margin:auto; box-shadow:0 24px 80px #06152655; overflow:auto; }.bb-cookie-dialog[open] { display:flex; flex-direction:column; }.bb-cookie-dialog header,.bb-cookie-dialog footer { flex-shrink:0; }.bb-cookie-options { overflow-y:auto; min-height:0; }.bb-cookie-dialog::backdrop { background:#06122280; }
.bb-cookie-dialog header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:24px; border-bottom:1px solid var(--cc-border); }.bb-cookie-close { flex-shrink:0; width:44px; height:44px; display:grid; place-items:center; background:var(--cc-tint); color:var(--cc-text); border:0; border-radius:8px; cursor:pointer; }
.bb-cookie-options { padding:0 24px 20px; }.bb-cookie-options label { display:flex; align-items:flex-start; gap:14px; padding:20px 0; cursor:pointer; border-bottom:1px solid var(--cc-border); }.bb-cookie-options input { width:20px; height:20px; min-width:20px; padding:0; margin:2px 0 0; appearance:auto; accent-color:#1a6afd; }.bb-cookie-options strong { display:block; font-size:15px; margin-bottom:6px; }.bb-cookie-options strong small { font-size:11px; margin-inline-start:8px; font-weight:500; color:var(--cc-muted); }.bb-cookie-options label span span { display:block; font-size:13px; line-height:1.6; color:var(--cc-muted); }.bb-cookie-note { display:flex; gap:8px; padding-top:10px; }.bb-cookie-note svg { flex-shrink:0; margin-top:3px; }.bb-cookie-dialog footer { padding:20px 24px; background:var(--cc-tint); border-top:1px solid var(--cc-border); }.bb-cookie-dialog footer p { margin:0 0 16px; font-size:12px; }
.bb-cookie-footer { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; padding:12px 20px calc(12px + var(--bb-cookie-app-space,0px)); background:var(--surface,#fff); color:var(--text-muted,#536478); font-size:12px; }.bb-cookie-footer button { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:44px; padding:8px 12px; background:transparent; border:0; color:inherit; text-decoration:underline; text-underline-offset:3px; cursor:pointer; font-family:inherit; }
@media(max-width:800px) { .bb-cookie-banner { flex-direction:column; align-items:stretch; gap:16px; padding:20px; }.bb-cookie-banner .bb-cookie-actions { max-width:none; justify-content:flex-start; }.bb-cookie-actions .bb-cookie-button { flex:1; }.bb-cookie-banner { max-height:calc(100dvh - 32px); overflow:auto; }.bb-cookie-footer { padding-bottom:calc(90px + var(--bb-cookie-app-space,0px)); } }
@media(max-width:440px) { .bb-cookie-banner { inset-inline:10px; bottom:10px; padding:18px; }.bb-cookie-banner .bb-cookie-custom { flex-basis:100%; }.bb-cookie-dialog header,.bb-cookie-dialog footer { padding:18px; }.bb-cookie-options { padding:0 18px 16px; }.bb-cookie-dialog .bb-cookie-actions { display:grid; grid-template-columns:1fr 1fr; }.bb-cookie-save { grid-column:1/-1; } }
@media print { .bb-cookie-banner,.bb-cookie-dialog,.bb-cookie-footer { display:none; } }
</style>
