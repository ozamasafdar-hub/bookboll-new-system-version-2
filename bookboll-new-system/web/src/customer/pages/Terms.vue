<script setup>
/**
 * Terms.vue — Customer Terms & Conditions (public storefront page, /terms).
 *
 * Linked from the sign-up consent checkbox (AuthGate), the storefront footer and
 * checkout copy. The money sections mirror the REAL platform rules in code:
 * slot holds (bb_hold_slots + platform_settings.booking_hold_minutes), the
 * NON-REFUNDABLE online deposit that is PART of the total price, full-online
 * splits, one intro trial per customer per provider (offerGuard, email+card),
 * loyalty (marketplace-only, funded from Bookboll's fee, changeable), hosted
 * events (host runs it; seat fees on arrival or via the host's OWN connected
 * Stripe — hostPayouts.js; prize pots 100% the host's obligation; instant-join
 * auto-accept; request deadlines; host may remove joiners; Moderation.vue
 * takedowns), the entry-pass (QR / text code) identity check at the venue,
 * and social profiles/posts/DMs (app-level privacy).
 *
 * HOUSE RULE: never promise refunds anywhere — cancellations and any money
 * back are between the customer and the provider/host per their policies.
 *
 * Provider terms live separately at /for-business/terms.
 */
import { useSeo } from '../../lib/seo.js'
import { businessHomeUrl } from '../../lib/crossApp.js'
import { ScrollText } from 'lucide-vue-next'

useSeo({
  exactTitle: 'Terms & Conditions | Bookboll',
  description: 'The terms for booking venues, joining membership programs and community events on Bookboll: payments and deposits, refunds and cancellations, entry passes, fair-use and account rules.',
  path: '/terms',
})

const UPDATED = '5 July 2026'

const SECTIONS = [
  { id: 'about', n: 1, title: 'About these terms' },
  { id: 'account', n: 2, title: 'Your account & identity' },
  { id: 'bookings', n: 3, title: 'Bookings & slot holds' },
  { id: 'payments', n: 4, title: 'Payments & deposits' },
  { id: 'refunds', n: 5, title: 'Cancellations, refunds & no-shows' },
  { id: 'memberships', n: 6, title: 'Academy and membership listings' },
  { id: 'offers', n: 7, title: 'Promo codes & loyalty points' },
  { id: 'events', n: 8, title: 'Community games, hangouts & tournaments' },
  { id: 'prizes', n: 9, title: 'Prize pots & event money' },
  { id: 'entry', n: 10, title: 'Entry passes & check-in at the venue' },
  { id: 'social', n: 11, title: 'Profiles, posts & messages' },
  { id: 'conduct', n: 12, title: 'Fair use, conduct & bans' },
  { id: 'marketing', n: 13, title: 'Messages & marketing' },
  { id: 'data', n: 14, title: 'Your data' },
  { id: 'liability', n: 15, title: 'Liability' },
  { id: 'changes', n: 16, title: 'Changes & contact' },
]
</script>

<template>
  <div class="tc">
    <header class="tc-hero">
      <div class="tc-badge"><ScrollText :size="14" /> Customer agreement</div>
      <h1>Terms &amp; Conditions</h1>
      <p class="tc-sub">The short, honest rules for booking venues, joining memberships and playing in community events.</p>
      <div class="tc-updated">Last updated: {{ UPDATED }}</div>
    </header>

    <nav class="tc-toc" aria-label="Contents">
      <a v-for="s in SECTIONS" :key="s.id" :href="'#' + s.id">{{ s.n }}. {{ s.title }}</a>
    </nav>

    <article class="tc-doc">
      <section id="about">
        <h2>1. About these terms</h2>
        <p>These terms are an agreement between you (the customer) and Bookboll for using the Bookboll marketplace and the provider booking websites Bookboll powers. When you book a venue, join a membership or event, you also enter a direct relationship with that <b>provider</b> (the business running the venue or program) — they deliver the service; Bookboll runs the booking platform.</p>
      </section>

      <section id="account">
        <h2>2. Your account &amp; identity</h2>
        <ul>
          <li>You need an account to complete a booking or event signup. Keep your name, email and phone number accurate — they are used for confirmations and for identifying you at the venue.</li>
          <li>Your phone number may be verified with a one-time code (WhatsApp or SMS). One person per account; don't share accounts or impersonate others.</li>
          <li>You must be legally able to enter this agreement, or have a parent/guardian book on your behalf.</li>
        </ul>
      </section>

      <section id="bookings">
        <h2>3. Bookings &amp; slot holds</h2>
        <ul>
          <li>When you select time slots, they are <b>held for you for a short window</b> (typically 10 minutes) while you complete checkout. If the timer runs out, the slots are released for other customers and you'll need to pick again.</li>
          <li>A booking exists only when checkout completes and you see the confirmation. Prices, availability and any venue rules shown at checkout are the ones that apply.</li>
          <li>Booked time is for the activity, capacity and duration shown on the listing.</li>
        </ul>
      </section>

      <section id="payments">
        <h2>4. Payments &amp; deposits</h2>
        <p class="tc-callout"><b>The deposit is part of the price — and it is non-refundable.</b> When a booking asks for a deposit online, that deposit counts toward your total (it is never an extra charge on top). It secures your slot and is not returned if you cancel or don't show up. The remainder is paid in person at the venue.</p>
        <ul>
          <li>Payment options vary per venue. Depending on the venue and where you book, you'll pay one of: an <b>online deposit</b> (rest on arrival, in person), the <b>full amount online</b> (some venues allow this), or — on some providers' own websites — <b>everything at the venue</b>.</li>
          <li>What you pay now vs. at the venue is always itemised at checkout before you confirm. No hidden charges are added afterwards.</li>
          <li>Amounts are charged in the venue's local currency shown at checkout. Any card or bank fees your issuer charges are yours.</li>
        </ul>
      </section>

      <section id="refunds">
        <h2>5. Cancellations, refunds &amp; no-shows</h2>
        <ul>
          <li><b>The online deposit is never refunded</b> — whoever cancels, and whatever the reason. It is the non-refundable part of the price.</li>
          <li><b>Everything beyond the deposit is between you and the provider (or host).</b> Each provider sets and applies their own cancellation policy. Bookboll is the booking platform — it does not decide, guarantee, fund or process refunds on a provider's behalf.</li>
          <li><b>If the provider cancels on you</b> (court closed, class cancelled), contact the provider: rebooking, credit or any money back for amounts they hold is handled by them under their own policy and the law that applies to them.</li>
          <li>Loyalty points redeemed on a booking the provider cancels may be re-credited by Bookboll at its discretion.</li>
          <li>Repeated no-shows can lead to booking restrictions or a ban (see section 12).</li>
        </ul>
      </section>

      <section id="memberships"><h2>6. Academy and membership listings</h2><p>Bookboll displays academy information and a contact phone so you can contact the provider directly. Availability, enrolment, academy payments, renewals, cancellations and attendance are arranged with the provider. Bookboll does not enrol you or collect academy membership payments through these listings. Confirm the programme details and price directly before enrolling.</p></section>

      <section id="offers">
        <h2>7. Promo codes &amp; loyalty points</h2>
        <ul>
          <li>Promo codes apply to the payment being made today, respect their published limits (audience, usage caps, expiry) and can't be exchanged for cash.</li>
          <li>Loyalty points are earned and redeemable <b>on the Bookboll marketplace only</b> (not on provider websites), on online payments. Redeemed points reduce what you pay; they have no cash value and expire with your account.</li>
          <li>Point redemptions are funded by Bookboll out of its own platform fee — never by the venue. Bookboll may change earn/redeem rates, pause redemption at a venue, or withdraw the loyalty program; points already earned keep no cash value either way.</li>
          <li>Abusing offers (fake accounts, card cycling, code sharing outside the rules) forfeits the benefit and can lead to a ban.</li>
        </ul>
      </section>

      <section id="events">
        <h2>8. Community games, hangouts &amp; tournaments</h2>
        <p class="tc-callout"><b>Bookboll only connects players.</b> Every community event is organised and run by its <b>host</b> — another customer, not Bookboll. Bookboll lists the event and passes join requests along; the event itself, and the money around it, is the host's.</p>
        <ul>
          <li><b>Hosting.</b> When a host books a venue for an event, that court reservation is a normal booking — the deposit the host pays is <b>non-refundable</b>, like any booking deposit, including if the event doesn't fill and the host cancels.</li>
          <li><b>Joining.</b> Depending on the event you either <b>request to join</b> (the host reviews and accepts or declines you) or <b>join instantly</b> (instant-join events auto-accept you while spots remain). Hosts may set a <b>request deadline</b> — after it passes, no more requests are taken.</li>
          <li><b>Paying the host.</b> Any price per player is paid <b>to the host</b>, not to Bookboll: in person at the event, or — where the host has enabled online tickets — online through the <b>host's own connected Stripe account</b> (Bookboll only deducts its service fee from each ticket; it does not hold the host's money). If your request is declined, nothing is charged.</li>
          <li><b>Hosts stay in charge.</b> A host may remove a participant, including after acceptance (for example if plans change or someone can't come). If you can't make it, tell the host — the seat fee arrangement is between the two of you.</li>
          <li><b>Tournaments.</b> Brackets, match scheduling, scoring and results are managed by the host. Bookboll displays what the host enters; it does not referee, verify results or adjudicate disputes between players and hosts.</li>
          <li><b>No attendance guarantee.</b> Bookboll doesn't guarantee that an event takes place, fills up, or that everyone shows — the host and the joiners are responsible to each other.</li>
          <li><b>Moderation.</b> Events are public group activities. Bookboll may flag or remove unsafe, misleading or rule-breaking events at any time, and hosts who break the rules can be banned.</li>
          <li><b>Own risk.</b> You join events at your own risk — see section 15. What happens at an event is between you, the host and the venue.</li>
        </ul>
      </section>

      <section id="prizes">
        <h2>9. Prize pots &amp; event money</h2>
        <p class="tc-callout"><b>Bookboll is not involved in prize money — at all.</b> A prize pot advertised on an event is offered, funded and paid <b>entirely by the host</b>.</p>
        <ul>
          <li>Bookboll never holds, collects, guarantees, processes or pays out prize money. An advertised pot is the host's promise to the players — not Bookboll's.</li>
          <li>How and when winners are paid is agreed between the players and the host. Bookboll does not arbitrate prize or result disputes.</li>
          <li>The same goes for any other money arranged around an event (side bets, gear, transport): it is between the people involved, at their own risk.</li>
          <li>If a host advertises prizes they don't honour, report it — Bookboll can remove their events and ban them (section 12), but cannot recover or pay the prize.</li>
        </ul>
      </section>

      <section id="entry">
        <h2>10. Entry passes &amp; check-in at the venue</h2>
        <ul>
          <li>Your bookings come with an <b>entry pass</b>: a QR code and a short text code in your account.</li>
          <li>Providers may ask to scan or read your pass on each visit to confirm the booking is really yours and to record attendance. Showing it is part of the service — someone who cannot present a valid pass can be refused entry, and the deposit stays non-refundable.</li>
          <li>Passes, bookings and memberships are personal and <b>non-transferable</b>. Sharing your account or pass so someone else can play or train on your plan counts as abuse and can lead to refusal of entry or a ban (section 12).</li>
        </ul>
      </section>

      <section id="social">
        <h2>11. Profiles, posts &amp; messages</h2>
        <ul>
          <li>Bookboll includes social features on the marketplace: player profiles, posts, follows and direct messages. Using them is optional.</li>
          <li><b>You are responsible for what you post and send.</b> Only share content you have the right to share, and keep it lawful and respectful — no harassment, spam, impersonation or adult/illegal content. Bookboll may remove content and restrict social features for breaches.</li>
          <li>You give Bookboll permission to display the content you post within the platform (that's what posting is). You can delete your posts at any time.</li>
          <li>Setting your profile to private limits who sees it inside the app — treat it as a preference, not a vault. Don't post anything that must stay secret, and never share payment details in messages.</li>
        </ul>
      </section>

      <section id="conduct">
        <h2>12. Fair use, conduct &amp; bans</h2>
        <ul>
          <li>Treat venues, staff, hosts and other players with respect, follow venue rules, and use bookings/memberships only for yourself (and guests where the listing allows).</li>
          <li><b>Providers may refuse entry or ban you from their venue</b> for abuse, repeated no-shows, unpaid balances, safety risks or pass-sharing. A provider ban applies to that provider's venues and programs.</li>
          <li><b>Bookboll may restrict, suspend or close accounts</b> platform-wide for fraud, offer abuse, harassment, fake accounts, prize/event abuse or repeated breaches. Where reasonable, we'll warn you first.</li>
          <li>Bans don't erase money owed, and deposits already paid stay non-refundable.</li>
        </ul>
      </section>

      <section id="marketing">
        <h2>13. Messages &amp; marketing</h2>
        <ul>
          <li>We and your providers send <b>service messages</b> about your bookings and events (confirmations, reminders, changes, one-time sign-in codes) by email, SMS or WhatsApp — these are part of the service.</li>
          <li><b>Marketing</b> (offers &amp; news) is sent only on channels you opted in to — consent is per channel (email, SMS, WhatsApp) — and every marketing email has an unsubscribe link. You can change consent anytime in Account settings or via the unsubscribe page.</li>
          <li>Marketing from a provider you've used is sent by that provider under their own responsibility; unsubscribing from them doesn't affect your Bookboll service messages.</li>
        </ul>
      </section>

      <section id="data">
        <h2>14. Your data</h2>
        <ul>
          <li>Bookboll stores your account details, bookings, memberships, event activity and communication preferences to run the service.</li>
          <li>When you book or join something, the details needed to serve you (name, contact, booking/pass info, form answers) are shared with that provider or host. They may only use them to serve you — and for marketing only with your consent.</li>
          <li>Your data is not sold. You can update your details in Account settings and ask support to close your account; closing it forfeits remaining loyalty points.</li>
        </ul>
      </section>

      <section id="liability">
        <h2>15. Liability</h2>
        <ul>
          <li>Sport carries inherent risk — play within your ability and follow safety instructions. Some activities may require you to accept the venue's or host's waiver before participating.</li>
          <li>The provider is responsible for the venue, equipment and services they deliver; the host is responsible for the event they run; Bookboll is responsible for the booking platform. Bookboll isn't liable for what happens on a provider's premises or at a host's event.</li>
          <li>Bookboll isn't a party to the deals you make with providers or hosts (entry fees, prizes, refunds beyond the platform rules above) and isn't liable for them.</li>
          <li>Nothing in these terms limits liability that can't lawfully be limited.</li>
        </ul>
      </section>

      <section id="changes">
        <h2>16. Changes &amp; contact</h2>
        <p>We may update these terms as the platform evolves; material changes are announced on the site. Continuing to book after a change means you accept it. Questions? Contact support from your account page.</p>
        <p class="tc-note">Businesses listing on Bookboll are covered by the separate <a :href="businessHomeUrl('/terms')">Provider Terms &amp; Conditions</a>.</p>
      </section>
    </article>
  </div>
</template>

<style scoped>
.tc { max-width: 760px; margin: 0 auto; }
.tc-hero { padding: 6px 0 4px; }
.tc-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 12%, var(--surface, white)); padding: 5px 12px; border-radius: 999px; }
.tc h1 { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); margin: 12px 0 8px; }
.tc-sub { font-size: 15px; color: var(--text-secondary, #475569); line-height: 1.6; margin: 0; }
.tc-updated { margin-top: 10px; font-size: 12.5px; font-weight: 700; color: var(--text-light, #94a3b8); }

.tc-toc { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0 26px; }
.tc-toc a { font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #475569); text-decoration: none; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 999px; padding: 7px 12px; }
.tc-toc a:hover { color: var(--brand-ink, var(--brand)); border-color: var(--brand-accent); }

.tc-doc section { scroll-margin-top: 80px; margin-bottom: 26px; }
.tc-doc h2 { font-size: 18px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 10px; padding-bottom: 8px; border-bottom: 1px solid var(--border, #eef2f6); }
.tc-doc p { font-size: 14.5px; color: var(--text-secondary, #334155); line-height: 1.7; margin: 0 0 10px; }
.tc-doc ul { margin: 0 0 10px; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 8px; }
.tc-doc li { font-size: 14.5px; color: var(--text-secondary, #334155); line-height: 1.65; }
.tc-doc b { color: var(--text, #0f172a); }
.tc-callout { background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; padding: 12px 15px; color: var(--warning-ink, #92400e); }
.tc-note { color: var(--text-muted, #64748b); font-size: 13.5px; }
.tc-note a { color: var(--brand-ink, var(--brand)); font-weight: 700; }

@media (max-width: 760px) {
  .tc h1 { font-size: 22px; }
  .tc-sub { font-size: 14px; }
  .tc-doc h2 { font-size: 16.5px; }
  .tc-doc p, .tc-doc li { font-size: 14px; }
  .tc-toc { gap: 6px; margin: 16px 0 20px; }
  .tc-toc a { padding: 8px 12px; font-size: 12px; }
}
</style>
