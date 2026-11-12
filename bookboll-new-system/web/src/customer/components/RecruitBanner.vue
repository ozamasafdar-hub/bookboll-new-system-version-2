<script setup>
/**
 * RecruitBanner — provider-recruitment CTA, tailored per main venue category
 * (sports / hangout / work) so it reads right on every kind of landing page. Shared across
 * the homepage, venue browse and membership/city pages. Hidden on white-label sites.
 */
import { computed } from 'vue'
import { selectedCity } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { adminLoginUrl, businessHomeUrl } from '../../lib/crossApp.js'
import { Store, Check, ArrowRight } from 'lucide-vue-next'

const props = defineProps({
  cityName: { type: String, default: '' },
  category: { type: String, default: '' },   // '' | 'sports' | 'hangout' | 'work' | 'membership' | 'business'
})
const where = computed(() => props.cityName || selectedCity.value?.name || 'your city')

const COPY = {
  sports:  { heading: 'Own a court, pitch or venue?', audience: 'players', cta: 'List your venue' },
  hangout: { heading: 'Run an entertainment or hangout venue?', audience: 'guests', cta: 'List your venue' },
  work:    { heading: 'Own a studio or workspace?', audience: 'people', cta: 'List your space' },
  membership: { heading: 'Run a gym, academy or class?', audience: 'customers', cta: 'Advertise your academy', body: 'Show your academy on Bookboll and let interested customers call you directly. You manage enrolment, attendance and academy payments.' },
  business: { heading: 'Do you own a business?', audience: 'customers', cta: 'Try Bookboll for businesses',
              body: "Create your own website and list your services on Bookboll's platform as well — take venue bookings and help customers discover your academy." },
  '':      { heading: 'Own a venue or space?', audience: 'customers', cta: 'List your space' },
}
const c = computed(() => COPY[props.category] || COPY[''])
</script>

<template>
  <section v-if="!isWhiteLabel" class="recruit" aria-label="Bookboll for businesses">
    <div class="recruit-text">
      <div class="recruit-badge"><Store :size="15" /> Bookboll for businesses</div>
      <h2>{{ c.heading }}</h2>
      <p>{{ c.body || `List on Bookboll and get discovered by ${c.audience} near you — manage venue bookings and help customers discover your services.` }}</p>
      <ul class="recruit-perks">
        <li><Check :size="15" /> Reach {{ c.audience }} searching in {{ where }}</li>
        <li><Check :size="15" /> {{ category === 'membership' ? 'Customers contact you directly' : 'Take venue bookings online' }}</li>
        <li><Check :size="15" /> {{ category === 'membership' ? 'USD 20/month per listing by default; local rates may apply' : 'Free website; academy marketplace listings billed separately' }}</li>
      </ul>
    </div>
    <div class="recruit-cta">
      <a :href="businessHomeUrl('/')" class="recruit-btn">{{ c.cta }} <ArrowRight :size="18" /></a>
      <a :href="adminLoginUrl()" class="recruit-signin">Already a partner? Sign in</a>
    </div>
  </section>
</template>

<style scoped>
/* NOTE: the recruit banner uses FIXED teal colors on purpose — it must NOT follow the
   site brand color (super-admin marketplace theming). Do not swap these for --brand vars. */
/* margin-bottom keeps whatever follows (FAQ / SEO blocks / blog) clearly separated on every page */
.recruit { display: flex; align-items: center; justify-content: space-between; gap: 28px; margin-top: 32px; margin-bottom: 36px; padding: 28px 30px; border: 1px solid #237966; border-radius: 20px; background: linear-gradient(120deg, #073f36, #0b6655); color: #fff; position: relative; overflow: hidden; flex-wrap: wrap; color-scheme: dark; }
.recruit::after { content: ''; position: absolute; inset-inline-end: -50px; top: -50px; width: 220px; height: 220px; background: #14b8a6; opacity: .14; border-radius: 50%; }
.recruit-text { flex: 1; min-width: 260px; z-index: 1; }
.recruit-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; color: #5eead4; background: rgba(20,184,166,.16); padding: 5px 12px; border-radius: 999px; }
.recruit-text h2 { font-size: 26px; font-weight: 700; letter-spacing: -.4px; margin: 14px 0 10px; color: #fff; }
.recruit-text p { font-size: 14px; color: #d1fae5; line-height: 1.65; margin: 0 0 18px; max-width: 580px; }
.recruit-perks { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px 18px; }
.recruit-perks li { display: flex; align-items: center; gap: 7px; font-size: 13px; color: #ecfdf5; font-weight: 500; }
.recruit-perks svg { color: #34d399; flex-shrink: 0; }
.recruit-cta { display: flex; flex-direction: column; align-items: stretch; gap: 10px; z-index: 1; }
.recruit-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #14b8a6; color: #04201c; font-weight: 800; font-size: 15.5px; padding: 14px 26px; border-radius: 999px; text-decoration: none; white-space: nowrap; }
.recruit-btn:hover { background: #2dd4bf; }
.recruit-signin { text-align: center; color: #d1fae5; font-size: 13px; font-weight: 500; text-decoration: none; min-height: 44px; display: flex; align-items: center; justify-content: center; }
.recruit-signin:hover { color: #fff; text-decoration: underline; }
.recruit a:focus-visible { outline: 2px solid #fff; outline-offset: 4px; }

/* Mobile: stack columns, kill fixed min-widths and nowrap so nothing exceeds a ~344px viewport */
@media (max-width: 760px) {
  .recruit { flex-direction: column; align-items: stretch; gap: 18px; padding: 22px 16px; }
  .recruit-text, .recruit-cta { flex: none; width: 100%; min-width: 0; }
  .recruit-text h2 { font-size: 20px; }
  .recruit-text p { max-width: none; }
  .recruit-perks { flex-direction: column; flex-wrap: nowrap; gap: 8px; }
  .recruit-perks li { align-items: flex-start; min-width: 0; }
  .recruit-perks svg { margin-top: 2px; }
  .recruit-btn { white-space: normal; width: 100%; padding: 13px 18px; }
}
</style>
