<script setup>
/**
 * ForBusinessPricing.vue — the dedicated /for-business/pricing page.
 *
 * Free vs annual Premium comparison for provider businesses
 * Annual plan headers and account-aware calls to action,
 * a commission comparison and shared core tools, plus the Premium growth
 * toolkit with ✗/✓ marks. Subscription prices read from lib/premium.js.
 * Commission enforcement is pending in readmeAlamgir.md. Fixed teal landing theme (NOT the
 * marketplace brand), bare 'auth' layout, indexable with its own SEO + FAQ LD.
 */
import { ref, computed } from 'vue'
import { useSeo, faqLd } from '../lib/seo.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import { PREMIUM_YEARLY_TOTAL, PREMIUM_FEATURES } from '../lib/premium.js'
import BusinessHeader from '../components/BusinessHeader.vue'
import { Check, X as XIcon, ArrowRight, ArrowLeft, Info } from 'lucide-vue-next'

// Booking commission differs by plan; core tools are included on both.
const CORE_ROWS = [
  { label: 'Own-website booking commission', free: 'Your market’s Free rate', premium: 'Your market’s Premium rate' },
  { label: 'Marketplace booking commission', free: 'Standard rate', premium: 'Standard rate' },
  { label: 'Free branded booking website (yourclub.bookboll.com)' },
  { label: 'Bookboll marketplace listing' },
  { label: 'Court, venue & activity bookings' },
  { label: 'Membership listings on your own website', free: 'Premium required', premium: 'Included' },
  { label: 'Marketplace membership listing', free: 'USD 20/month per listing*', premium: 'USD 20/month per listing*' },
  { label: 'Online payments & payout wallet' },
  { label: 'Calendar & staff accounts' },
]

const PRICING_FAQS = [
  { q: 'Is the Free plan really free?',
    a: 'The Free plan has no setup or monthly subscription fee. Booking commissions apply on both your own website and the Bookboll marketplace. Own-website bookings use the Free or paid Premium rate configured for your venue’s market. Your listing form shows both rates. Marketplace rates stay the same across plans.' },
  { q: 'How is Premium billed?',
    a: 'Premium is an annual subscription with no free trial. The annual charge is due when you subscribe. You can cancel renewal from the dashboard and keep access until the end of your paid year.' },
  { q: 'Does connecting payouts remove the commission?',
    a: 'No. Your website offers full payment online or deposit + on arrival. Full online payment needs connected payouts: Bookboll deducts its commission and credits your share to your Bookings wallet. Deposit + on arrival works without connected payouts: Bookboll keeps the online deposit fee, and you collect the remaining balance. Commission applies either way.' },
  { q: 'What does Premium cost?',
    a: `US$${PREMIUM_YEARLY_TOTAL} per year, billed annually. There is no monthly plan or free trial.` },
  { q: 'Can I switch or cancel later?',
    a: 'Yes. You can upgrade to annual Premium or cancel its next renewal at any time. You keep Premium until the end of the paid year, then return to Free from your dashboard — your booking website and stored data remain. Own-website membership listings become hidden when Premium ends.' },
]

// SoftwareApplication LD with BOTH plan tiers — lets search engines show the
// free plan + Premium price directly in results for "bookboll pricing" queries.
const PRICING_LD = {
  '@context': 'https://schema.org',
  '@type': 'SoftwareApplication',
  name: 'Bookboll for Business',
  applicationCategory: 'BusinessApplication',
  operatingSystem: 'Web',
  url: 'https://business.bookboll.com/pricing',
  description: 'Booking software and a free branded website for sports venues, gyms and academies — free forever plan, annual Premium growth toolkit.',
  offers: [
    { '@type': 'Offer', name: 'Free', price: '0', priceCurrency: 'USD',
      description: 'No monthly subscription fee. Booking website, venue marketplace listings and booking payments. Membership marketplace exposure is a separate monthly fee. Standard booking commissions apply on both website and marketplace.' },
    { '@type': 'Offer', name: 'Premium (annual)', price: String(PREMIUM_YEARLY_TOTAL), priceCurrency: 'USD',
      description: 'US$588 billed annually, no free trial. Paid Premium uses the configured own-website booking rate; marketplace rates stay the same.' },
  ],
  publisher: { '@type': 'Organization', name: 'Bookboll', url: 'https://bookboll.com/' },
}

useSeo({
  exactTitle: 'Pricing — Free & Premium Plans | Bookboll for Business',
  description: `Bookboll for Business: no monthly subscription on Free. Annual Premium at US$${PREMIUM_YEARLY_TOTAL}/year adds own-website membership listings and marketing tools. Booking commissions apply on both plans.`,
  path: '/pricing',
  jsonLd: [PRICING_LD, faqLd(PRICING_FAQS)],
})

const open = ref(-1)
</script>

<template>
  <div class="fbp">
    <BusinessHeader />

    <!-- ── Hero ───────────────────────────────────────────────────────────── -->
    <section class="fbp-hero">
      <router-link to="/" class="fbp-back"><ArrowLeft :size="15" /> Bookboll for Business</router-link>
      <h1>Simple pricing.<br /><span class="fbp-hl">Free forever</span>, or grow with Premium.</h1>
      <p class="fbp-hero-sub">No monthly subscription on Free. Booking commissions apply on both channels. Paid Premium halves your own-website booking commission and adds tools to grow your business.</p>
    </section>

    <!-- ── Plans ──────────────────────────────────────────────────────────── -->
    <section class="fbp-sec">

      <div class="fbp-pt">
        <!-- column headers -->
        <div class="fbp-pt-corner"></div>
        <div class="fbp-pt-head">
          <div class="fbp-pt-name">Free</div>
          <div class="fbp-pt-price"><b>$0</b><span>forever</span></div>
          <div class="fbp-pt-bill">Free forever · no card needed</div>
          <a :href="authUser ? adminDashboardUrl() : '/signup'" class="fbp-pt-cta ghost">{{ authUser ? 'Open my dashboard' : 'Get started free' }}</a>
        </div>
        <div class="fbp-pt-head hot">
          <div class="fbp-pt-ribbon">Recommended</div>
          <div class="fbp-pt-name">Premium</div>
          <div class="fbp-pt-price"><b>${{ PREMIUM_YEARLY_TOTAL }}</b><span>/year</span></div>
          <div class="fbp-pt-bill">Billed annually in USD</div>
          <div class="fbp-pt-trial">One year of Premium</div>
          <a :href="authUser ? adminDashboardUrl() : '/signup'" class="fbp-pt-cta">Choose annual Premium</a>
        </div>

        <!-- core platform: identical on both plans -->
        <div class="fbp-pt-group">Booking fees &amp; included tools</div>
        <template v-for="r in CORE_ROWS" :key="r.label">
          <div class="fbp-pt-l">{{ r.label }}</div>
          <div class="fbp-pt-c" data-plan="Free"><template v-if="r.free">{{ r.free }}</template><Check v-else :size="17" class="fbp-pt-yes" aria-label="Included" /></div>
          <div class="fbp-pt-c hot" data-plan="Premium"><template v-if="r.premium">{{ r.premium }}</template><Check v-else :size="17" class="fbp-pt-yes" aria-label="Included" /></div>
        </template>

        <!-- premium growth toolkit -->
        <div class="fbp-pt-group">Growth toolkit — Premium</div>
        <template v-for="ft in PREMIUM_FEATURES.filter(f => f.key !== 'website_commission')" :key="ft.key">
          <div class="fbp-pt-l">{{ ft.label }}<small>{{ ft.desc }}</small></div>
          <div class="fbp-pt-c" data-plan="Free"><XIcon :size="16" class="fbp-pt-no" aria-label="Not included" /></div>
          <div class="fbp-pt-c hot" data-plan="Premium"><Check :size="17" class="fbp-pt-yes" aria-label="Included" /></div>
        </template>
      </div>

      <div class="fbp-note">
        <Info :size="14" />
        <span><b>How the commission works:</b> if your standard rate is 15%, Free website bookings use 15% and paid Premium website bookings use 7.5%. Rates vary by country and listing; your dashboard shows yours. Connecting payouts does not remove the fee. The discount applies during your paid subscription. Marketplace commission stays at the standard rate.</span>
      </div>
      <div class="fbp-note">
        <Info :size="14" />
        <span>Subscription prices are in USD. Premium is ${{ PREMIUM_YEARLY_TOTAL }} once a year with no free trial. Booking commissions apply separately. *Marketplace membership listings default to USD 20 per listing each month on both plans; country/city rates may differ. Customers enrol and pay the academy directly. Optional extras: a custom domain (one-time fee). <router-link to="/terms" class="fbp-link">Provider terms apply</router-link>.</span>
      </div>
    </section>

    <!-- ── Pricing FAQ ────────────────────────────────────────────────────── -->
    <section class="fbp-sec fbp-faq">
      <h2>Pricing questions</h2>
      <div v-for="(fq, i) in PRICING_FAQS" :key="fq.q" class="fbp-qa" :class="{ open: open === i }">
        <button type="button" class="fbp-q" @click="open = open === i ? -1 : i">{{ fq.q }}<span class="fbp-q-ico">{{ open === i ? '–' : '+' }}</span></button>
        <p v-if="open === i" class="fbp-a">{{ fq.a }}</p>
      </div>
    </section>

    <!-- ── Final CTA ──────────────────────────────────────────────────────── -->
    <section class="fbp-cta">
      <h2>Start free. Upgrade when you're growing.</h2>
      <a v-if="authUser" :href="adminDashboardUrl()" class="fbp-cta-btn">Open my dashboard <ArrowRight :size="17" /></a>
      <router-link v-else to="/signup" class="fbp-cta-btn">Create your free account <ArrowRight :size="17" /></router-link>
    </section>

    <footer class="fbp-foot">
      <span>© {{ new Date().getFullYear() }} Bookboll — booking software for sports facilities</span>
      <div class="fbp-foot-links">
        <router-link to="/">For Business</router-link>
        <router-link to="/blog">Blog</router-link>
        <router-link to="/terms">Provider terms</router-link>
        <a v-if="authUser" :href="adminDashboardUrl()">Dashboard</a>
        <a v-else :href="adminLoginUrl()">Sign in</a>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.fbp { --ink:#0f172a; --teal:#14b8a6; --teal-2:#2dd4bf; --teal-lite:#5eead4; background:#ffffff; color:var(--ink); min-height:100vh; }


/* hero */
.fbp-hero { background:radial-gradient(1100px 460px at 80% -10%, rgba(20,184,166,.22), transparent 60%), linear-gradient(135deg,#0f172a,#134e4a); color:#fff; padding:46px 22px 54px; text-align:center; }
.fbp-back { display:inline-flex; align-items:center; gap:6px; color:var(--teal-lite); font-weight:800; font-size:13px; text-decoration:none; margin-bottom:16px; }
.fbp-hero h1 { color:#fff; font-size:38px; font-weight:900; letter-spacing:-.02em; line-height:1.14; margin:0; }
.fbp-hl { color:var(--teal-2); }
.fbp-hero-sub { max-width:640px; margin:14px auto 0; color:#cbd5e1; font-size:15.5px; line-height:1.6; }

.fbp-sec { max-width:1120px; margin:0 auto; padding:42px 22px; }

/* billing toggle */
.fbp-billing { display:flex; justify-content:center; gap:4px; background:#f1f5f9; border-radius:999px; padding:4px; width:fit-content; margin:0 auto; }
.fbp-billing button { display:inline-flex; align-items:center; gap:7px; border:none; background:none; color:#64748b; font:inherit; font-weight:800; font-size:14px; padding:9px 18px; border-radius:999px; cursor:pointer; }
.fbp-billing button.on { background:#ffffff; color:var(--ink); box-shadow:0 1px 4px rgba(15,23,42,.14); }
.fbp-save { font-size:10.5px; font-weight:800; text-transform:uppercase; color:#047857; background:#d1fae5; padding:2px 8px; border-radius:999px; }

/* plan comparison table */
.fbp-pt { display:grid; grid-template-columns:1.5fr 1fr 1fr; margin-top:26px; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden; background:#fff; box-shadow:0 10px 34px rgba(15,23,42,.06); }
.fbp-pt-corner { border-bottom:1px solid #e2e8f0; }
.fbp-pt-head { position:relative; text-align:center; padding:22px 14px 18px; border-bottom:1px solid #e2e8f0; border-left:1px solid #f1f5f9; display:flex; flex-direction:column; align-items:center; gap:4px; }
.fbp-pt-head.hot { background:linear-gradient(180deg, rgba(20,184,166,.08), rgba(20,184,166,.02)); border-left:2px solid var(--teal); }
.fbp-pt-ribbon { position:absolute; top:10px; right:10px; font-size:10px; font-weight:900; letter-spacing:.05em; text-transform:uppercase; color:#04201c; background:var(--teal-2); padding:3px 9px; border-radius:999px; }
.fbp-pt-name { font-size:16px; font-weight:900; color:var(--ink); }
.fbp-pt-price { display:flex; align-items:baseline; gap:3px; }
.fbp-pt-price b { font-size:34px; font-weight:900; color:var(--ink); line-height:1.05; }
.fbp-pt-price span { font-size:14px; font-weight:700; color:#64748b; }
.fbp-pt-bill { font-size:12px; color:#64748b; }
.fbp-pt-trial { font-size:11.5px; font-weight:800; color:#047857; background:#d1fae5; border-radius:999px; padding:4px 11px; margin-top:2px; }
.fbp-pt-cta { margin-top:10px; display:inline-flex; align-items:center; justify-content:center; background:var(--teal); color:#04201c; font-weight:800; font-size:13.5px; padding:10px 18px; border-radius:999px; text-decoration:none; }
.fbp-pt-cta:hover { background:var(--teal-2); }
.fbp-pt-cta.ghost { background:#fff; border:1.5px solid #cbd5e1; color:#334155; }
.fbp-pt-cta.ghost:hover { border-color:var(--teal); color:var(--teal); background:#fff; }
.fbp-pt-group { grid-column:1 / -1; font-size:11.5px; font-weight:900; letter-spacing:.06em; text-transform:uppercase; color:#64748b; background:#f8fafc; padding:10px 16px; border-bottom:1px solid #e2e8f0; }
.fbp-pt-l { padding:12px 16px; font-size:13.5px; font-weight:600; color:#334155; border-bottom:1px solid #f1f5f9; display:flex; flex-direction:column; gap:2px; }
.fbp-pt-l small { font-weight:500; font-size:11.5px; color:#94a3b8; line-height:1.35; }
.fbp-pt-c { display:flex; align-items:center; justify-content:center; padding:12px 10px; font-size:12.5px; font-weight:700; color:#334155; border-bottom:1px solid #f1f5f9; border-left:1px solid #f1f5f9; text-align:center; }
.fbp-pt-c.hot { background:rgba(20,184,166,.045); border-left:2px solid var(--teal); }
.fbp-pt-yes { color:#059669; }
.fbp-pt-no { color:#cbd5e1; }

.fbp-note { display:flex; align-items:flex-start; gap:8px; max-width:760px; margin:16px auto 0; font-size:12.5px; color:#64748b; line-height:1.55; }
.fbp-note svg { flex-shrink:0; margin-top:2px; color:var(--teal); }
.fbp-link { color:var(--teal); font-weight:700; }

/* pricing FAQ */
.fbp-faq { max-width:760px; }
.fbp-faq h2 { text-align:center; font-size:26px; font-weight:900; letter-spacing:-.02em; margin:0 0 18px; }
/* Quiet divider-list FAQ (matches the marketplace's redesigned FAQ) */
.fbp-qa { border-bottom:1px solid #e8edf3; }
.fbp-qa:last-of-type { border-bottom:none; }
.fbp-q { display:flex; align-items:center; justify-content:space-between; gap:14px; width:100%; text-align:start; border:none; background:none; font:inherit; font-weight:600; font-size:14px; color:#334155; padding:13px 2px; cursor:pointer; }
.fbp-q:hover { color:var(--teal); }
.fbp-qa.open .fbp-q { color:var(--ink); }
.fbp-q-ico { color:var(--text-light, #94a3b8); font-weight:400; font-size:17px; flex-shrink:0; }
.fbp-qa.open .fbp-q-ico { color:var(--teal); }
.fbp-a { margin:0; padding:0 24px 14px 2px; font-size:13.5px; color:#64748b; line-height:1.65; }

/* CTA + footer */
.fbp-cta { background:linear-gradient(135deg,#0f172a,#134e4a); color:#fff; text-align:center; padding:48px 22px; }
.fbp-cta h2 { color:#fff; font-size:26px; font-weight:900; letter-spacing:-.02em; margin:0 0 18px; }
.fbp-cta-btn { display:inline-flex; align-items:center; gap:8px; background:var(--teal); color:#04201c; font-weight:800; font-size:15.5px; padding:13px 24px; border-radius:999px; text-decoration:none; }
.fbp-cta-btn:hover { background:var(--teal-2); }
.fbp-foot { max-width:1120px; margin:0 auto; padding:22px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
.fbp-foot-links { display:flex; gap:16px; flex-wrap:wrap; }
.fbp-foot-links a { color:#475569; font-weight:700; text-decoration:none; }
.fbp-foot-links a:hover { color:var(--teal); }

@media (max-width:700px) {
  .fbp-hero h1 { font-size:28px; }
  .fbp-sec { padding:30px 14px; }
  .fbp-pt { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .fbp-pt > * { min-width: 0; }
  .fbp-pt-corner { display: none; }
  .fbp-pt-head { padding: 16px 10px; justify-content: flex-end; }
  .fbp-pt-ribbon { position: static; font-size: 9px; margin-bottom: 6px; }
  .fbp-pt-price b { font-size:26px; }
  .fbp-pt-l { grid-column: 1 / -1; font-size:13px; padding:14px 14px 8px; border-top:1px solid #e2e8f0; border-bottom:0; }
  .fbp-pt-l small { font-size:12px; color:#64748b; line-height:1.5; }
  .fbp-pt-c { flex-direction:column; gap:6px; font-size:12px; padding:10px 8px 14px; }
  .fbp-pt-c::before { content:attr(data-plan); font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; color:#64748b; }
  .fbp-pt-cta { font-size:12px; padding:9px 12px; }
}
</style>

