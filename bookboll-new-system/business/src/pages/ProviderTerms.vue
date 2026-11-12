<script setup>
/**
 * ProviderTerms.vue — Terms & Conditions for business providers (public, bare layout).
 *
 * Linked from the /for-business landing ("T&Cs apply"), the landing footer, and the
 * /signup wizard ("By creating an account you agree…"). Sections 5–7 describe the requested payment policy.
 * Backend rollout requirements are in readmeAlamgir.md. Existing integrations (see booking.js paymentPlan/createCustomerBookingsMulti +
 * walletSetup gating): marketplace bookings are always at least partly online and
 * Bookboll's fee is collected as the deposit or as commission on full-online payments
 * (15% default, per-country/agreement overrides); own-website bookings also carry commission, halved during paid Premium.
 * Bookboll retains deposits as its fee regardless of payout connection; membership programs require a Membership-wallet payout method
 * before going live; Bookboll may PAUSE approved payout methods (payout_paused →
 * isWalletReady gates). Also covered: Premium subscription + custom-domain add-on
 * (lib/premium.js), hosted community events on provider courts (host reservations are
 * normal bookings; seat fees + prize pots are host↔player, never Bookboll's or the
 * venue's obligation), white-label merchant-of-record, provider-connected channels
 * (own SMTP / WhatsApp Business / tracking pixels), and one-trial-per-customer
 * enforcement (offerGuard).
 *
 * HOUSE RULE: never promise refunds anywhere — provider cancellation remedies are the
 * provider's own responsibility under their published policy, not Bookboll's.
 *
 * Fixed teal theme (matches the landing — NOT the super-admin marketplace brand).
 */
import { useSeo } from '../lib/seo.js'
import BusinessHeader from '../components/BusinessHeader.vue'
import { ScrollText } from 'lucide-vue-next'

useSeo({
  exactTitle: 'Provider Terms & Conditions | Bookboll for Business',
  description: 'The terms that apply to businesses listing on Bookboll: fees and deposits, marketplace and own-website bookings, payout wallets, membership programs, cancellations and provider responsibilities.',
  path: '/terms',
})

const UPDATED = '5 July 2026'

const SECTIONS = [
  { id: 'who', n: 1, title: 'Who we are & what these terms cover' },
  { id: 'account', n: 2, title: 'Your provider account & team' },
  { id: 'services', n: 3, title: 'The services: your website + the marketplace' },
  { id: 'listings', n: 4, title: 'Listings, review & placement' },
  { id: 'fees', n: 5, title: 'Fees, payments & deposits' },
  { id: 'wallets', n: 6, title: 'Wallets, payouts & payout reviews' },
  { id: 'memberships', n: 7, title: 'Membership programs & subscriptions' },
  { id: 'premium', n: 8, title: 'Premium subscription & paid add-ons' },
  { id: 'events', n: 9, title: 'Community events hosted at your venues' },
  { id: 'website', n: 10, title: 'Your website, white-label & connected channels' },
  { id: 'marketing', n: 11, title: 'Marketing to customers' },
  { id: 'cancellations', n: 12, title: 'Cancellations, no-shows & refunds' },
  { id: 'responsibilities', n: 13, title: 'Your responsibilities' },
  { id: 'data', n: 14, title: 'Customer data' },
  { id: 'termination', n: 15, title: 'Suspension & termination' },
  { id: 'liability', n: 16, title: 'Liability & disclaimers' },
  { id: 'changes', n: 17, title: 'Changes to these terms' },
]
</script>

<template>
  <div class="pt">
    <!-- ── Top bar (matches the For Business landing) ─────────────────────── -->
    <BusinessHeader />

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <section class="pt-hero">
      <div class="pt-hero-in">
        <div class="pt-badge"><ScrollText :size="15" /> Provider agreement</div>
        <h1>Terms &amp; Conditions for Providers</h1>
        <p>These terms apply to businesses that create a provider account, list venues, courts, classes or membership programs on Bookboll, or run a booking website powered by Bookboll.</p>
        <div class="pt-updated">Last updated: {{ UPDATED }}</div>
      </div>
    </section>

    <div class="pt-body">
      <!-- Contents -->
      <nav class="pt-toc" aria-label="Contents">
        <div class="pt-toc-h">Contents</div>
        <a v-for="s in SECTIONS" :key="s.id" :href="'#' + s.id">{{ s.n }}. {{ s.title }}</a>
      </nav>

      <article class="pt-doc">
        <section id="who">
          <h2>1. Who we are &amp; what these terms cover</h2>
          <p>Bookboll ("Bookboll", "we", "us") operates (a) the <b>Bookboll marketplace</b> — the public website where customers discover and book sports venues, classes and membership programs — and (b) <b>provider booking websites</b> — branded storefronts we host for each provider (e.g. <i>yourclub.bookboll.com</i>), including embed widgets for a provider's own external website.</p>
          <p>These terms are an agreement between Bookboll and you, the business ("provider", "you") using those services. By creating a provider account or listing with Bookboll you accept these terms. Separate terms apply to customers who book.</p>
        </section>

        <section id="account">
          <h2>2. Your provider account &amp; team</h2>
          <ul>
            <li>You must provide accurate business information and keep it up to date, and you confirm you are authorised to act for the business you register.</li>
            <li>You are responsible for activity under your account and for the team members you invite. Team roles, permissions and scopes you assign in your dashboard determine what your staff can do; <b>the account owner remains responsible for staff actions</b>.</li>
            <li>Keep your credentials confidential. Tell us promptly if you suspect unauthorised use.</li>
          </ul>
        </section>

        <section id="services">
          <h2>3. The services: your website + the marketplace</h2>
          <ul>
            <li><b>Starting is free.</b> Your booking website and the core booking tools carry no setup or monthly fee. Certain advanced features require the optional Premium subscription (section 8), and a custom domain is a paid add-on.</li>
            <li><b>Your booking website</b> runs on a Bookboll subdomain (your permanently locked business username); you may optionally connect a custom domain for a one-time setup fee shown in your dashboard.</li>
            <li><b>Marketplace placement</b> is optional per listing: you choose whether each venue listing or membership program appears on the Bookboll marketplace, on your own website, or both.</li>
            <li>Some features are marketplace-only for customers (for example Bookboll loyalty rewards, community events and player profiles) and are funded and operated by Bookboll — they do not run on provider websites. Loyalty redemptions are funded from Bookboll's fee; your payout is never reduced by them.</li>
          </ul>
        </section>

        <section id="listings">
          <h2>4. Listings, review &amp; placement</h2>
          <ul>
            <li>Listings and programs you place on the <b>marketplace</b> are subject to Bookboll's review and approval before going live, and may be removed if they breach these terms or our content standards.</li>
            <li>Listings shown only on <b>your own website</b> do not require marketplace approval.</li>
            <li>You must own or be authorised to offer every venue, court, class or program you list, and your listing details (photos, prices, schedules, availability) must be accurate and kept current.</li>
          </ul>
        </section>

        <section id="fees">
          <h2>5. Fees, payments &amp; deposits</h2>
          <p class="pt-callout">Bookboll charges commission on <b>marketplace and own-website bookings</b>. Own-website venue bookings use the configured <b>Free or paid Premium rate</b> for the venue’s market. Your listing’s payment settings show the applicable rate; marketplace rates stay the same across plans.</p>
          <ul>
            <li><b>Marketplace bookings are always paid at least partly online.</b> There is no pay-everything-on-arrival option on the marketplace. The customer either pays:
              <ul>
                <li>an <b>online deposit</b> — which Bookboll retains as its platform fee, with the balance paid to you on arrival; or</li>
                <li>the <b>full amount online</b> — from which Bookboll deducts its commission and credits your share to your wallet.</li>
              </ul>
            </li>
            <li><b>The standard marketplace commission is 15%.</b> Rates can vary by country, listing type or individual agreement — the rate that applies to you is always shown in your dashboard before you list.</li>
            <li><b>Deposits are part of the customer's price.</b> An online deposit always counts toward the customer's total — it is never an extra charge on top of the price you set.</li>
            <li><b>Your own website offers two payment methods:</b> deposit + on arrival, or full payment online. Full payment online requires a connected payout method. Full payment on arrival is not offered on your own website.</li>
            <li><b>Payout connections do not waive commission.</b> On both channels, Bookboll retains the online deposit fee and you collect the remaining balance on arrival. With full online payment, Bookboll deducts its commission and credits your share to your <b>Bookings wallet</b>. The own-website discount applies during your active paid subscription; standard commissions apply when you return to Free.</li>
            <li><b>Deposits are non-refundable to the customer.</b> Customer-facing terms and checkout state this clearly: a customer who cancels or does not show forfeits the online deposit. You must not promise deposit refunds that contradict this.</li>
            <li>Promotional codes you create are funded from your share; promotional codes Bookboll creates are funded from Bookboll's fee.</li>
            <li><b>Custom domain</b> connection carries a one-time setup fee, shown before you confirm it.</li>
            <li>You are responsible for any taxes applicable to your sales.</li>
          </ul>
        </section>

        <section id="wallets">
          <h2>6. Wallets, payouts &amp; payout reviews</h2>
          <ul>
            <li>Bookboll provides per-country wallets, split by purpose: a <b>Bookings wallet</b> (venue/court bookings) and a <b>Membership wallet</b> (membership programs). Each holds a balance in that country's currency.</li>
            <li>Your share of online payments is credited to the relevant wallet. Withdrawals are paid to the payout method on file and may be subject to verification review by Bookboll before settlement.</li>
            <li><b>Payout methods are subject to verification, and Bookboll may pause an approved payout method</b> pending review (for example for fraud, chargeback or compliance checks). While a method is paused, features that require a ready wallet — such as full-online payments or publishing membership programs — may be unavailable until the review completes.</li>
            <li>Keep your payout details accurate. Bookboll may hold or reverse credits in cases of suspected fraud, chargebacks, or breach of these terms.</li>
          </ul>
        </section>

        <section id="memberships">
          <h2>7. Membership programs &amp; subscriptions</h2>
          <ul>
            <li>Creating a membership program <b>requires a payout method on your Membership wallet first</b> — unlike venue bookings, programs cannot be published without one.</li>
            <li>You define the plan, price, billing interval and any free trial or introductory offer; those terms are shown to the customer before they subscribe and you must honour them.</li>
            <li><b>Intro offers are one per customer, per business.</b> Bookboll automatically limits free trials and intro discounts to once per customer for your business (checked by account email and payment card). A customer rejoining at full price is never blocked.</li>
            <li>Recurring billing runs against the subscriber until the subscription is cancelled by you or the customer in line with your published terms. Cancelling stops future renewals; you decide — under your own policy — how to treat the current period, but must not promise refunds on Bookboll's behalf.</li>
          </ul>
        </section>

        <section id="premium">
          <h2>8. Premium subscription &amp; paid add-ons</h2>
          <ul>
            <li>Certain advanced dashboard features (for example data exports, the marketing email composer, your blog, promo codes, hero banners and embed widgets) require the optional <b>Premium subscription</b> — US$588 per year, billed annually with no free trial. The price and any applicable promotion are shown before you subscribe. Cancelling stops the next annual renewal; access continues until the end of the paid period.</li>
            <li>Premium renews automatically until cancelled. Cancelling stops future renewals; access to gated features ends with the paid period. Amounts already billed are not returned.</li>
            <li>Bookboll may adjust the Premium price or the set of gated features with notice in your dashboard.</li>
            <li>The <b>custom domain</b> connection is a separate one-time paid add-on (section 5).</li>
          </ul>
        </section>

        <section id="events">
          <h2>9. Community events hosted at your venues</h2>
          <ul>
            <li>Customers ("hosts") can book your courts to run community games, hangouts and tournaments. <b>A host's court reservation reaches you as a normal booking</b> — same pricing, deposit and payout rules as any other booking — and you must honour it like any other booking.</li>
            <li>The event itself (participants, entry fees, brackets, results, prizes) is organised by the host, not by you and not by Bookboll — unless you separately agree something with the host directly.</li>
            <li><b>Seat fees and prize pots are never Bookboll's obligation — and never yours as the venue.</b> Players pay the host (in person, or online via the host's own connected Stripe account); prize pots are funded and paid entirely by the host. Bookboll does not hold, guarantee, process or arbitrate that money for anyone.</li>
            <li>Your normal venue rules apply to event attendees, and you may refuse entry on the same grounds as for any customer (section 13).</li>
          </ul>
        </section>

        <section id="website">
          <h2>10. Your website, white-label &amp; connected channels</h2>
          <ul>
            <li><b>You are the merchant of record on your own website.</b> Sales made through your white-label storefront (or embeds on your external site) are contracts between you and your customer: you are responsible for your own customer terms, receipts, taxes and consumer-law compliance in your market. Bookboll provides the software.</li>
            <li>You are responsible for the content you publish on your storefront (name, logo, images, banners, blog posts) and must hold the rights to use it. Bookboll may remove content that is unlawful or breaches these terms.</li>
            <li>Analytics and marketing tags you connect (e.g. Meta Pixel, Google Analytics, Tag Manager, custom code) run on <b>your storefront only</b>. You are solely responsible for that code and for complying with privacy and cookie laws that apply to your visitors. Bookboll's own analytics run on the Bookboll marketplace only.</li>
            <li><b>Connected sending channels are yours.</b> If you connect your own email (SMTP) sender, your own WhatsApp Business number or other channels, those accounts, their provider fees and their terms are your responsibility, and messages sent through them are sent by you.</li>
          </ul>
        </section>

        <section id="marketing">
          <h2>11. Marketing to customers</h2>
          <ul>
            <li>You may only send marketing messages to customers who have opted in to that channel — consent is <b>per channel</b> (email, SMS or WhatsApp). Every marketing email must carry the built-in unsubscribe link, and opt-outs must be honoured immediately.</li>
            <li>Service messages about a customer's own booking or membership (confirmations, reminders, changes) are not marketing and may always be sent.</li>
            <li>You are responsible for the content of your campaigns and for complying with the marketing and messaging laws of your customers' countries — including the rules of any channel you connect yourself (section 10).</li>
          </ul>
        </section>

        <section id="cancellations">
          <h2>12. Cancellations, no-shows &amp; refunds</h2>
          <ul>
            <li>Platform-wide booking policies (such as cancellation windows, hold times and no-show handling) are published in the dashboard and apply to marketplace bookings.</li>
            <li><b>You set your own cancellation and refund policy toward customers</b> — publish it clearly and honour it consistently. It may not contradict the platform rule that online deposits are non-refundable.</li>
            <li><b>Customer cancellations and no-shows:</b> the customer's online deposit is not refunded — it is retained per the fee rules in section 5. Any balance that was payable on arrival is simply not collected.</li>
            <li>You must honour every confirmed booking and subscription. <b>If you cancel on a customer</b>, making it right — rebooking, credit or returning amounts you hold — is your responsibility under your published policy and applicable law. Bookboll does not fund, guarantee or process refunds on your behalf, and is not obliged to return its platform fee.</li>
            <li>Repeated provider-side cancellations can lead to removal from the marketplace (section 13).</li>
          </ul>
        </section>

        <section id="responsibilities">
          <h2>13. Your responsibilities</h2>
          <ul>
            <li>Operate your venue and services safely, lawfully and with the licences your activity requires.</li>
            <li>Keep schedules, prices and availability accurate so customers are never sold a slot you cannot honour.</li>
            <li>Treat customers fairly — misleading listings, discriminatory refusals or repeated no-shows on your side may lead to removal from the marketplace.</li>
            <li><b>Verifying members &amp; refusing entry.</b> Every active membership carries a membership pass (QR + short code) the member shows on each visit; your dashboard's Member check-in page verifies it — catching shared, expired or cancelled subscriptions — and records attendance. You may refuse entry to a member who cannot present a valid pass, and you may ban a specific customer from your venues for documented abuse, repeated no-shows, unpaid balances, pass-sharing or safety risks — but not on discriminatory grounds. A ban you apply covers your business only; platform-wide action is Bookboll's decision.</li>
            <li>Do not use the services for anything unlawful, or attempt to take marketplace-originated customers off-platform specifically to avoid the platform fee on their marketplace bookings.</li>
          </ul>
        </section>

        <section id="data">
          <h2>14. Customer data</h2>
          <ul>
            <li>Customer data you access through Bookboll (contact details, bookings, passes, form answers) may be used only to serve those customers and — with the customer's per-channel consent — for marketing. It must not be sold or shared with third parties.</li>
            <li>Protect the customer data you handle, limit your team's access to what their role needs, and comply with the data-protection laws that apply to you as the business your customers deal with.</li>
            <li>If your account closes, stop using data on customers who reached you through the marketplace, except as needed for legal or accounting duties.</li>
          </ul>
        </section>

        <section id="termination">
          <h2>15. Suspension &amp; termination</h2>
          <ul>
            <li>You can stop using the services at any time; wallet balances remain withdrawable subject to verification.</li>
            <li>Bookboll may suspend or remove listings, pause payout methods, or suspend or close a provider account, for breach of these terms, suspected fraud, safety concerns, or legal requirement. Where practical we will tell you why and give you a chance to fix the issue.</li>
            <li>Obligations that by nature survive (outstanding payments, liability, data duties) survive termination.</li>
          </ul>
        </section>

        <section id="liability">
          <h2>16. Liability &amp; disclaimers</h2>
          <ul>
            <li>The services are provided "as is". We work to keep them available and accurate but do not guarantee uninterrupted or error-free operation, or any particular volume of bookings or customers.</li>
            <li>You contract with your customers directly for the services you provide at your venue; Bookboll is not a party to that relationship and is not liable for what happens at your premises.</li>
            <li>Bookboll is likewise not a party to arrangements between event hosts and their players (seat fees, prize pots, results) that take place at your venue, and has no payment obligation arising from them.</li>
            <li>To the fullest extent permitted by law, Bookboll's total liability to you under these terms is limited to the platform fees Bookboll earned from your bookings in the 12 months before the claim. Nothing in these terms excludes liability that cannot lawfully be excluded.</li>
          </ul>
        </section>

        <section id="changes">
          <h2>17. Changes to these terms</h2>
          <p>We may update these terms as the platform evolves. Material changes will be announced in your dashboard with reasonable notice; continuing to use the services after a change takes effect means you accept the updated terms. If you do not accept a change, stop using the services and withdraw your balance.</p>
          <p class="pt-contact">Questions about these terms? Contact Bookboll support from your dashboard.</p>
        </section>
      </article>
    </div>

    <footer class="pt-foot">
      <span>© {{ new Date().getFullYear() }} Bookboll</span>
      <div class="pt-foot-links">
        <router-link to="/">Bookboll for Business</router-link>
        <router-link to="/">Marketplace</router-link>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Fixed teal theme — matches the /for-business landing (NOT the marketplace brand). */
.pt { --ink:#0f172a; --teal:#14b8a6; --teal-lite:#5eead4; background:var(--surface, #ffffff); color:var(--ink); min-height:100vh;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; -webkit-font-smoothing:antialiased; }

/* Nav */
/* Language switcher, restyled for the dark sticky nav */

/* Hero */
.pt-hero { background:linear-gradient(135deg,#0f172a,#134e4a); color:#fff; padding:46px 22px 40px; }
.pt-hero-in { max-width:1020px; margin:0 auto; }
.pt-badge { display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:800; color:var(--teal-lite); background:rgba(20,184,166,.16); padding:6px 13px; border-radius:999px; }
.pt-hero h1 { color:#fff; font-size:32px; font-weight:900; letter-spacing:-.02em; margin:14px 0 10px; }
.pt-hero p { color:#cbd5e1; font-size:15px; line-height:1.6; max-width:680px; margin:0; }
.pt-updated { margin-top:14px; font-size:12.5px; font-weight:700; color:var(--text-light, #94a3b8); }

/* Body: TOC + doc */
.pt-body { max-width:1020px; margin:0 auto; padding:36px 22px 60px; display:grid; grid-template-columns:250px minmax(0,1fr); gap:36px; align-items:start; }
.pt-toc { position:sticky; top:76px; border:1px solid var(--border, #e2e8f0); border-radius:14px; padding:14px 16px; display:flex; flex-direction:column; gap:7px; }
.pt-toc-h { font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.07em; color:var(--text-light, #94a3b8); margin-bottom:3px; }
.pt-toc a { font-size:12.5px; font-weight:600; color:#475569; text-decoration:none; line-height:1.4; }
.pt-toc a:hover { color:#0f766e; }

.pt-doc section { scroll-margin-top:80px; margin-bottom:30px; }
.pt-doc h2 { font-size:19px; font-weight:900; color:var(--ink); margin:0 0 10px; padding-bottom:8px; border-bottom:1px solid #eef2f6; }
.pt-doc p { font-size:14.5px; color:#334155; line-height:1.7; margin:0 0 10px; }
.pt-doc ul { margin:0 0 10px; padding-inline-start:20px; display:flex; flex-direction:column; gap:8px; }
.pt-doc li { font-size:14.5px; color:#334155; line-height:1.65; }
.pt-doc li ul { margin-top:8px; }
.pt-doc b { color:var(--ink); }
.pt-callout { background:#f0fdfa; border:1px solid #99f6e4; border-radius:12px; padding:12px 15px; color:#134e4a; }
.pt-contact { color:var(--text-muted, #64748b); font-size:13.5px; }

/* Footer */
.pt-foot { background:#0b1220; color:var(--text-light, #94a3b8); padding:20px 22px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; font-size:13px; }
.pt-foot-links { display:flex; gap:18px; }
.pt-foot-links a { color:#cbd5e1; text-decoration:none; font-weight:700; }
.pt-foot-links a:hover { color:#fff; }

@media (max-width:800px) {
  .pt-body { grid-template-columns:1fr; gap:22px; }
  .pt-toc { position:static; }
  .pt-hero h1 { font-size:26px; }
}

/* Narrow phones (360px-class) */
@media (max-width:600px) {
  /* Hero: cap h1 at phone size, tighten gutters */
  .pt-hero { padding:32px 16px 28px; }
  .pt-hero h1 { font-size:22px; }

  /* Gutters + nested list indents */
  .pt-body { padding:24px 16px 48px; }
  .pt-foot { padding:18px 16px; }
  .pt-doc ul { padding-inline-start:18px; }
  .pt-doc li ul { padding-inline-start:16px; }

  /* TOC: comfortable tap spacing */
  .pt-toc { gap:10px; }
  .pt-toc a { padding:2px 0; }
}
</style>
