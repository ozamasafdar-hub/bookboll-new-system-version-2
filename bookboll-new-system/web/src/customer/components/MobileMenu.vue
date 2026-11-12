<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { customer, isCustomer, customerTheme, toggleCustomerTheme } from '../../lib/customerSession.js'
import { brandShows, activeBrand, isWhiteLabel, brandPageEnabled, brandSectionEnabled } from '../../lib/brand.js'
import { unreadMessages } from '../../lib/social.js'
import { businessHomeUrl } from '../../lib/crossApp.js'
import LanguageSwitcher from '../../components/LanguageSwitcher.vue'
import NotificationBell from './NotificationBell.vue'
import { X, Home, Search, Building2, CalendarDays, UsersRound, Dumbbell, Newspaper, User, CalendarCheck, Trophy, Settings, MessageCircle, LogOut, Moon, Plus, ArrowUpRight, Info, Phone, MapPin } from 'lucide-vue-next'

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close', 'signin', 'signout'])
const route = useRoute()
const dialog = ref(null)
let previousOverflow = null
let desktop

function unlockScroll() {
  if (previousOverflow !== null) document.documentElement.style.overflow = previousOverflow
  previousOverflow = null
}
function close() {
  dialog.value?.close()
  unlockScroll()
  emit('close')
}
function accountAction(action) { close(); emit(action) }
function syncDialog() {
  if (props.open && !dialog.value?.open) {
    previousOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
    dialog.value?.showModal()
  } else if (!props.open) {
    dialog.value?.close()
    unlockScroll()
  }
}
function onResize() { if (desktop.matches && props.open) close() }
watch(() => props.open, syncDialog, { flush: 'post' })
watch(() => route.fullPath, () => { if (props.open) close() })
onMounted(() => {
  desktop = window.matchMedia('(min-width: 768px)')
  desktop.addEventListener('change', onResize)
  syncDialog()
})
onBeforeUnmount(() => { desktop?.removeEventListener('change', onResize); unlockScroll() })
</script>

<template>
  <dialog ref="dialog" id="mobile-menu" class="mobile-menu" aria-labelledby="mobile-menu-title" @cancel.prevent="close" @click="($event.target === dialog) && close()">
    <div v-if="open" class="mm-content">
      <header class="mm-header"><h2 id="mobile-menu-title">{{ isWhiteLabel ? activeBrand.name : 'Menu' }}</h2><button type="button" class="mm-close" aria-label="Close menu" autofocus @click="close"><X :size="23" /></button></header>
      <div class="mm-body">
        <router-link v-if="!isWhiteLabel || brandShows('venues')" :to="isWhiteLabel ? '/facilities' : '/search'" class="mm-search" @click="close"><Search :size="20" /><span>{{ isWhiteLabel ? 'Find a listing' : 'Search Bookboll' }}</span></router-link>
        <nav :aria-label="isWhiteLabel ? 'Explore our website' : 'Explore Bookboll'" class="mm-nav">
          <router-link to="/" exact-active-class="on" @click="close"><Home :size="20" />{{ $t('nav.home') }}</router-link>
          <router-link v-if="brandShows('venues')" to="/facilities" active-class="on" @click="close"><Building2 :size="20" />{{ isWhiteLabel ? 'Listings' : $t('nav.venues') }}</router-link>
          <router-link v-if="!isWhiteLabel && brandShows('events')" to="/games" active-class="on" @click="close"><CalendarDays :size="20" />{{ $t('nav.events') }}</router-link>
          <router-link v-if="!isWhiteLabel" to="/communities" active-class="on" @click="close"><UsersRound :size="20" />Community</router-link>
          <router-link v-if="brandShows('programs')" to="/programs" active-class="on" @click="close"><Dumbbell :size="20" />{{ $t('nav.memberships') }}</router-link>
          <router-link v-if="brandShows('blog')" to="/blog" active-class="on" @click="close"><Newspaper :size="20" />Blog</router-link>
          <router-link v-if="isWhiteLabel && brandShows('venues') && brandSectionEnabled('locations')" to="/locations" active-class="on" @click="close"><MapPin :size="20" />Our locations</router-link>
          <router-link v-if="isWhiteLabel && brandPageEnabled('about')" to="/about" active-class="on" @click="close"><Info :size="20" />About us</router-link>
          <router-link v-if="isWhiteLabel && brandPageEnabled('contact')" to="/contact" active-class="on" @click="close"><Phone :size="20" />Contact</router-link>
        </nav>
        <router-link v-if="!isWhiteLabel && brandShows('events')" to="/host-game" class="mm-host" @click="close"><Plus :size="19" /><span>Host an event &amp; earn</span><ArrowUpRight :size="17" /></router-link>

        <section class="mm-section" aria-labelledby="mm-account-title">
          <h3 id="mm-account-title">Your account</h3>
          <template v-if="isCustomer">
            <router-link :to="isWhiteLabel ? '/account' : `/u/${customer.id}`" class="mm-profile" @click="close"><User :size="20" /><span data-no-translate>{{ customer.full_name || 'My profile' }}</span></router-link>
            <nav aria-label="Your account" class="mm-nav">
              <router-link to="/my-bookings" @click="close"><CalendarCheck :size="19" />My bookings</router-link>

              <router-link v-if="!isWhiteLabel" to="/my-games" @click="close"><CalendarDays :size="19" />My events</router-link>
              <router-link v-if="!isWhiteLabel" to="/my-communities" @click="close"><UsersRound :size="19" />My communities</router-link>
              <router-link v-if="!isWhiteLabel" to="/rewards" @click="close"><Trophy :size="19" />Rewards</router-link>
              <router-link to="/account" @click="close"><Settings :size="19" />Settings</router-link>
            </nav>
            <router-link v-if="!isWhiteLabel" to="/messages" class="mm-row" @click="close"><MessageCircle :size="20" /><span>Messages</span><b v-if="unreadMessages" class="mm-count">{{ unreadMessages > 9 ? '9+' : unreadMessages }}</b></router-link>
            <NotificationBell v-if="!isWhiteLabel" labeled />
          </template>
          <template v-else>
            <p>{{ isWhiteLabel ? 'Sign in to manage your bookings.' : 'Keep your bookings, memberships and events in one place.' }}</p>
            <button type="button" class="mm-signin" @click="accountAction('signin')"><User :size="18" />{{ $t('account.signIn') }}</button>
          </template>
        </section>

        <section class="mm-section" aria-labelledby="mm-preferences-title">
          <h3 id="mm-preferences-title">Preferences</h3>
          <button type="button" class="mm-row mm-theme" role="switch" :aria-checked="customerTheme === 'dark'" aria-label="Dark mode" @click="toggleCustomerTheme"><Moon :size="20" /><span>Dark mode</span><span class="mm-switch" :class="{ on: customerTheme === 'dark' }"><span /></span></button>
          <div class="mm-language"><span>Language</span><LanguageSwitcher /></div>
        </section>
        <a v-if="!isWhiteLabel" :href="businessHomeUrl('/')" class="mm-business"><span><strong>Bookboll for businesses</strong><small>Add your business and reach more customers</small></span><ArrowUpRight :size="20" /></a>
        <button v-if="isCustomer" type="button" class="mm-row mm-signout" @click="accountAction('signout')"><LogOut :size="19" />{{ $t('account.signOut') }}</button>
      </div>
    </div>
  </dialog>
</template>

<style scoped>
.mobile-menu { position: fixed; inset-block: 0; inset-inline: 0 auto; margin: 0; padding: 0; width: min(100%, 420px); max-width: 100%; height: 100dvh; max-height: 100dvh; border: 0; background: var(--surface); color: var(--text); box-shadow: 12px 0 48px #0003; }
.mobile-menu::backdrop { background: #070d18a6; }
.mm-content { min-height: 100%; }
.mm-header { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; background: var(--surface); border-bottom: 1px solid var(--border); }
.mm-header h2 { font-size: 20px; font-weight: 700; margin: 0; overflow-wrap: anywhere; min-width: 0; }
.mm-close { display: grid; place-items: center; flex-shrink: 0; width: 44px; height: 44px; border: 0; border-radius: 50%; background: var(--bg); color: var(--text); cursor: pointer; }
.mm-body { padding: 20px 20px calc(24px + env(safe-area-inset-bottom, 0px)); }
.mm-search { display: flex; align-items: center; gap: 12px; min-height: 50px; padding: 12px 16px; border: 1px solid var(--border-strong, var(--border)); border-radius: 10px; color: var(--text-muted); background: var(--canvas); font-size: 14px; }
.mm-nav { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 6px; margin: 12px -8px; }
.mm-nav a { display: flex; align-items: center; gap: 9px; min-height: 48px; padding: 10px 8px; border-radius: 8px; color: var(--text-secondary); font-size: 13px; font-weight: 550; line-height: 1.4; }
.mm-nav a.on, .mm-nav a:hover { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 9%, var(--surface)); }
.mm-nav svg, .mm-row svg, .mm-business svg { flex-shrink: 0; }
.mm-host { display: flex; align-items: center; gap: 9px; min-height: 48px; color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 650; }
.mm-host span { flex: 1; }
.mm-section { border-top: 1px solid var(--border); margin-top: 16px; padding-top: 20px; }
.mm-section h3 { margin: 0 0 10px; font-size: 14px; font-weight: 700; }
.mm-section p { margin: 0 0 14px; font-size: 13px; line-height: 1.6; color: var(--text-muted); }
.mm-profile { display: flex; align-items: center; gap: 10px; min-height: 44px; color: var(--text); font-size: 14px; font-weight: 600; }
.mm-profile span { overflow-wrap: anywhere; }
.mm-row { width: 100%; display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 8px 0; border: 0; background: transparent; color: var(--text-secondary); font: inherit; font-size: 14px; text-align: start; cursor: pointer; }
.mm-row > span:first-of-type { flex: 1; }
.mm-count { border-radius: 12px; padding: 2px 7px; background: var(--brand); color: white; font-size: 12px; }
.mm-signin { width: 100%; display: flex; justify-content: center; align-items: center; gap: 9px; min-height: 46px; border: 0; border-radius: 10px; background: var(--brand); color: white; font: inherit; font-size: 14px; font-weight: 650; cursor: pointer; }
.mm-switch { flex: none; width: 38px; height: 24px; padding: 3px; border-radius: 16px; background: var(--border-strong, #94a3b8); }
.mm-switch > span { display: block; width: 18px; height: 18px; background: #fff; border-radius: 50%; }
.mm-switch.on { background: var(--brand); }
.mm-switch.on > span { margin-inline-start: 14px; }
.mm-language { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-block: 8px; font-size: 14px; }
.mm-language > span { padding-top: 12px; color: var(--text-secondary); }
.mm-language :deep(.lang-trigger) { min-height: 44px; border-radius: 8px; }
.mm-language :deep(.lang-menu) { position: static; margin-top: 6px; min-width: 0; width: 180px; max-width: 100%; box-shadow: none; }
.mm-business { display: flex; align-items: center; gap: 14px; margin-top: 24px; padding: 16px; border-radius: 12px; background: #0b6655; color: #fff; }
.mm-business span { flex: 1; }
.mm-business strong { display: block; font-size: 14px; font-weight: 650; }
.mm-business small { display: block; margin-top: 5px; font-size: 12px; line-height: 1.6; color: #d1fae5; }
.mm-signout { margin-top: 14px; }
.mobile-menu a { text-decoration: none; }
.mobile-menu a:focus-visible, .mobile-menu button:focus-visible { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: 3px; }
</style>
