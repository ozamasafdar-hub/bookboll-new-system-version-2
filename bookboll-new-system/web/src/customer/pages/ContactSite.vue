<script setup>
/** ContactSite.vue — provider Contact page: hero banner (optional, from
 *  My Website), tap-to-act method cards, and location/hours cards. */
import { computed } from 'vue'
import { activeBrand, brandSectionEnabled } from '../../lib/brand.js'
import { useSeo } from '../../lib/seo.js'
import { Phone, Mail, MapPin, Clock, MessageCircle, Navigation } from 'lucide-vue-next'
import VisitUs from '../components/VisitUs.vue'
import { trackStorefrontEvent } from '../../lib/storefrontEvents.js'

useSeo(() => ({ title: `Contact ${activeBrand.value?.name || ''}`, noindex: true, path: '/contact' }))
const brand = computed(() => activeBrand.value || {})
const c = computed(() => brand.value.pages?.contact || {})
const heroTitle = computed(() => c.value.hero_title || 'Get in Touch')
const heroSub = computed(() => c.value.hero_sub || `We're happy to help — reach ${brand.value.name} any way you like.`)
const waLink = computed(() => `https://wa.me/${String(c.value.whatsapp || '').replace(/[^\d]/g, '')}`)
</script>

<template>
  <div class="ct">
    <section class="ct-hero" :class="{ img: c.hero_image }"
      :style="c.hero_image ? { backgroundImage: `linear-gradient(rgba(10,20,30,.45), rgba(10,20,30,.55)), url(${c.hero_image})` } : {}">
      <h1 class="ct-hero-h1">{{ heroTitle }}</h1>
      <p class="ct-hero-sub">{{ heroSub }}</p>
    </section>

    <!-- Reach us: big tap-to-act method cards -->
    <div class="ct-methods">
      <a v-if="c.phone" :href="`tel:${c.phone}`" class="ct-m" @click="trackStorefrontEvent('Contact', { method: 'phone' })">
        <span class="ct-m-ico"><Phone :size="20" /></span>
        <b>Call Us</b><small data-no-translate>{{ c.phone }}</small>
      </a>
      <a v-if="c.whatsapp" :href="waLink" target="_blank" rel="noopener" class="ct-m wa" @click="trackStorefrontEvent('Contact', { method: 'whatsapp' })">
        <span class="ct-m-ico"><MessageCircle :size="20" /></span>
        <b>WhatsApp</b><small>Fastest reply</small>
      </a>
      <a v-if="c.email" :href="`mailto:${c.email}`" class="ct-m" @click="trackStorefrontEvent('Contact', { method: 'email' })">
        <span class="ct-m-ico"><Mail :size="20" /></span>
        <b>Email Us</b><small data-no-translate>{{ c.email }}</small>
      </a>
    </div>

    <!-- Find us / hours -->
    <div v-if="c.hours || (brandSectionEnabled('locations') && (c.address || c.maps_url))" class="ct-info">
      <div v-if="brandSectionEnabled('locations') && (c.address || c.maps_url)" class="ct-card">
        <span class="ct-card-ico"><MapPin :size="18" /></span>
        <div class="ct-card-body">
          <b>Find Us</b>
          <p v-if="c.address">{{ c.address }}</p>
          <a v-if="c.maps_url" :href="c.maps_url" target="_blank" rel="noopener" class="ct-dir" @click="trackStorefrontEvent('FindLocation', { kind: 'venue' })"><Navigation :size="14" /> Get Directions</a>
        </div>
      </div>
      <div v-if="c.hours" class="ct-card">
        <span class="ct-card-ico"><Clock :size="18" /></span>
        <div class="ct-card-body">
          <b>Opening Hours</b>
          <p>{{ c.hours }}</p>
        </div>
      </div>
    </div>

    <!-- All venues + opening hours (shared with the home page) -->
    <VisitUs />
  </div>
</template>

<style scoped>
.ct { max-width: 860px; margin: 0 auto; padding-bottom: 24px; display: flex; flex-direction: column; gap: 24px; }
/* Full-bleed hero — see AboutSite: edge to edge, flush under the nav. */
.ct-hero { margin-inline: calc(50% - 50vw); margin-top: -18px; background: linear-gradient(130deg, var(--brand), var(--brand-accent)); padding: 76px 24px; text-align: center; color: #fff; background-size: cover; background-position: center; margin-bottom: 6px; }
.ct-hero-h1 { font-size: 36px; font-weight: 900; margin: 0 0 10px; color: #fff; line-height: 1.12; }
.ct-hero-sub { font-size: 15.5px; opacity: .93; margin: 0 auto; max-width: 540px; line-height: 1.6; }
.ct-methods { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.ct-m { display: flex; flex-direction: column; align-items: center; gap: 4px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e8edf3); border-radius: 16px; padding: 22px 14px 18px; text-decoration: none; text-align: center; transition: transform .12s, border-color .12s; }
.ct-m:hover { transform: translateY(-2px); border-color: var(--brand-accent); }
.ct-m-ico { width: 46px; height: 46px; border-radius: 14px; background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); color: var(--brand-ink, var(--brand)); display: flex; align-items: center; justify-content: center; margin-bottom: 6px; }
.ct-m.wa .ct-m-ico { background: var(--success-bg, #dcfce7); color: var(--success-ink, #16a34a); }
.ct-m b { font-size: 15px; color: var(--text, #0f172a); }
.ct-m small { font-size: 12.5px; color: var(--text-muted, #64748b); overflow-wrap: anywhere; }
.ct-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
.ct-card { display: flex; gap: 13px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e8edf3); border-radius: 16px; padding: 18px; }
.ct-card-ico { width: 40px; height: 40px; border-radius: 12px; background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); color: var(--brand-ink, var(--brand)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ct-card-body { min-width: 0; }
.ct-card-body b { font-size: 14.5px; color: var(--text, #0f172a); }
.ct-card-body p { font-size: 13.5px; color: var(--text-secondary, #475569); line-height: 1.55; margin: 4px 0 0; }
.ct-dir { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 13px; font-weight: 800; color: var(--brand-ink, var(--brand)); text-decoration: none; }
@media (max-width: 760px) {
  .ct-hero { padding: 50px 18px; }
  .ct-hero-h1 { font-size: 25px; }
  .ct-hero-sub { font-size: 14px; }
}
</style>
