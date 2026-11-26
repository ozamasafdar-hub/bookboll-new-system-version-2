<script setup>
/**
 * Privacy.vue — Customer Privacy Policy (public storefront page, /privacy-policy).
 *
 * This is the URL submitted to the Google Play / App Store listings, so the path
 * MUST stay /privacy-policy — it is what the store console points at, and a
 * broken policy URL is itself grounds for rejection. Also linked from the
 * sign-up consent row (AuthGate) and the storefront footer.
 *
 * Content is the company policy ported from the previous frontend
 * (bookboll-FRONTEND src/components/PrivacyPolicyPage.vue), kept intact and
 * extended to meet store review requirements: the device permissions the app
 * actually asks for (location, photo uploads), data retention, an explicit
 * account/data DELETION route, and children's data. Only disclose what the
 * platform really does — geolocation (lib/customerSession.js, LocationGate),
 * image uploads (Account/PublicProfile/communities), email + SMS/WhatsApp OTP.
 * There is no push-notification pipeline, so none is claimed here.
 *
 * Companion page: Terms & Conditions at /terms.
 */
import { useSeo } from '../../lib/seo.js'
import { ShieldCheck } from 'lucide-vue-next'

useSeo({
  exactTitle: 'Privacy Policy | Bookboll',
  description: 'How Bookboll collects, uses, stores and protects your information when you browse, book or pay for sports facility services — including your data rights and how to delete your account.',
  path: '/privacy-policy',
})

const UPDATED = '13 December 2025'

const SECTIONS = [
  { id: 'scope', n: 1, title: 'Who this policy covers' },
  { id: 'collect', n: 2, title: 'Information we collect' },
  { id: 'use', n: 3, title: 'How we use your information' },
  { id: 'payments', n: 4, title: 'Payment information' },
  { id: 'sharing', n: 5, title: 'Sharing of information' },
  { id: 'permissions', n: 6, title: 'Device permissions' },
  { id: 'security', n: 7, title: 'Data protection' },
  { id: 'rights', n: 8, title: 'Your rights & account deletion' },
  { id: 'retention', n: 9, title: 'How long we keep data' },
  { id: 'cookies', n: 10, title: 'Cookies & tracking' },
  { id: 'children', n: 11, title: "Children's data" },
  { id: 'changes', n: 12, title: 'Updates & contact' },
]
</script>

<template>
  <div class="pp">
    <header class="pp-hero">
      <div class="pp-badge"><ShieldCheck :size="14" /> Privacy</div>
      <h1>Privacy Policy</h1>
      <p class="pp-sub">How Bookboll collects, uses, stores and protects your information when you browse, book or pay for sports facility services.</p>
      <div class="pp-updated">Effective date: {{ UPDATED }} · Applies to the Bookboll end-client booking platform (website &amp; mobile app)</div>
    </header>

    <nav class="pp-toc" aria-label="Contents">
      <a v-for="s in SECTIONS" :key="s.id" :href="'#' + s.id">{{ s.n }}. {{ s.title }}</a>
    </nav>

    <article class="pp-doc">
      <section id="scope">
        <h2>1. Who this policy covers</h2>
        <p>This Privacy Policy explains how Bookboll.com (“we”, “us”, “our”) collects, uses, stores and protects your information when you use our platform to browse, book or pay for sports facility services — on the Bookboll marketplace, in the Bookboll mobile app, and on the provider booking websites Bookboll powers.</p>
        <p>When you book a venue, join a membership or sign up for an event, you also enter a direct relationship with that <b>provider</b> (the business running the venue or program). They deliver the service; Bookboll runs the booking platform. The rules for bookings and money are in our <router-link to="/terms">Terms &amp; Conditions</router-link>.</p>
      </section>

      <section id="collect">
        <h2>2. Information we collect</h2>
        <p>We may collect the following types of data:</p>
        <ul>
          <li>Personal information (name, email, phone number)</li>
          <li>Account credentials</li>
          <li>Booking history and preferences</li>
          <li>Payment information (processed via secure third-party payment gateways)</li>
          <li>Device information, IP address, browser type</li>
          <li>Location data (if permitted)</li>
          <li>Any communication made via our platform</li>
        </ul>
        <p>On the social side of the platform, we also store what you choose to publish or send: your public profile and bio, photos you upload, posts and comments in communities, event and tournament activity, and direct messages between you and other members or hosts.</p>
        <p>If you sign in with Google, we receive your name, email address and profile picture from Google to create or match your account. We never receive your Google password.</p>
      </section>

      <section id="use">
        <h2>3. How we use your information</h2>
        <p>Your information may be used for:</p>
        <ul>
          <li>Managing bookings and payments</li>
          <li>Communicating confirmations, reminders, updates and alerts</li>
          <li>Resolving disputes, cancellations or support cases</li>
          <li>Improving platform performance and features</li>
          <li>Fraud prevention and system security</li>
          <li>Compliance with legal and regulatory obligations</li>
        </ul>
        <p>Service messages (booking confirmations, entry passes, reminders, membership billing notices) are part of the service and are sent by email, and by SMS or WhatsApp where a phone number is needed — including the one-time code used to verify your number. Marketing messages are only sent with your consent, and every one of them can be unsubscribed from.</p>
      </section>

      <section id="payments">
        <h2>4. Payment information</h2>
        <ul>
          <li>We do not store credit/debit card numbers.</li>
          <li>All payments are processed through secure payment processors following PCI-DSS compliance.</li>
        </ul>
        <p>We keep only what is needed to show you a receipt and reconcile a booking — amount, currency, date, status and the last digits or token the processor returns.</p>
      </section>

      <section id="sharing">
        <h2>5. Sharing of information</h2>
        <p>We may share information only with:</p>
        <ul>
          <li>Partner facilities for booking and verification</li>
          <li>Payment gateway providers</li>
          <li>Regulatory entities when required by law</li>
          <li>Internal staff for support and security purposes</li>
        </ul>
        <p>A provider or event host receives only what they need to serve you — your name, contact details, booking and entry-pass details, and any answers you gave on their signup form. They may use it to serve you, and for marketing only with your consent.</p>
        <p class="pp-callout"><b>We never sell or rent your data to third parties.</b></p>
      </section>

      <section id="permissions">
        <h2>6. Device permissions</h2>
        <p>The app and website ask for a permission only when a feature needs it, and every one of them is optional — you can refuse, or revoke it later in your browser or device settings, and keep using Bookboll.</p>
        <ul>
          <li><b>Location</b> — used once, when you allow it, to detect your city so we can show venues, classes and games near you. You can always set your city manually instead. We use it to sort and filter listings by distance; we do not track your location in the background.</li>
          <li><b>Photos &amp; files</b> — used only to upload an image you pick yourself: your profile photo, a community or event image, or an attachment in a message. We access the file you choose, not your photo library.</li>
        </ul>
      </section>

      <section id="security">
        <h2>7. Data protection</h2>
        <p>We apply industry-standard security measures, including:</p>
        <ul>
          <li>Encryption</li>
          <li>Secure servers</li>
          <li>Access-restricted systems</li>
          <li>Regular security audits</li>
        </ul>
        <p>Traffic between your device and Bookboll is encrypted in transit (HTTPS), and staff access to customer data is limited to the people who need it for support and security.</p>
      </section>

      <section id="rights">
        <h2>8. Your rights &amp; account deletion</h2>
        <p>You may request:</p>
        <ul>
          <li>Access to your personal data</li>
          <li>Data correction</li>
          <li>Account deletion</li>
          <li>A copy of your data, or that we stop using it for marketing</li>
        </ul>
        <p>You can update your name, contact details, profile and communication preferences yourself at any time in <router-link to="/account">Account settings</router-link>.</p>
        <p class="pp-callout"><b>To delete your account and personal data</b>, email <a href="mailto:support@bookboll.com">support@bookboll.com</a> from the address on your account, or ask from Account settings. We action deletion requests within 30 days and confirm by email. Deleting your account removes your profile, photos, posts and messages from the platform and forfeits any remaining loyalty points. Records we are legally required to keep — booking and payment records for tax, accounting and dispute purposes — are retained as described below, and are not used to contact you again.</p>
        <p>Requests can be emailed to: <a href="mailto:support@bookboll.com">support@bookboll.com</a>.</p>
      </section>

      <section id="retention">
        <h2>9. How long we keep data</h2>
        <ul>
          <li><b>Account and profile data</b> — while your account is open, and until you ask us to delete it.</li>
          <li><b>Booking, membership and payment records</b> — kept after deletion only as long as tax, accounting and dispute-resolution law requires.</li>
          <li><b>Support messages</b> — kept while needed to resolve your case and for a reasonable period afterwards.</li>
          <li><b>Analytics and device logs</b> — kept in aggregated or shortened form for platform performance and security.</li>
        </ul>
      </section>

      <section id="cookies">
        <h2>10. Cookies &amp; tracking</h2>
        <p>We use cookies to:</p>
        <ul>
          <li>Improve user experience</li>
          <li>Enable login sessions</li>
          <li>Identify returning users</li>
          <li>Track analytics for performance</li>
        </ul>
        <p>Cookies needed to sign you in, hold your slots during checkout and keep the site secure support the service. Configured analytics and advertising tools, including Google Analytics, Google Tag Manager and Meta Pixel, load when you visit the relevant storefront. Bookboll does not display a cookie-choice banner. You can manage cookies through your browser settings.</p>
      </section>

      <section id="children">
        <h2>11. Children's data</h2>
        <p>Bookboll accounts are for adults. We do not knowingly collect data from children, and a child should not create an account — a parent or guardian books on their behalf and is responsible for that booking. If you believe a child has given us personal data, email <a href="mailto:support@bookboll.com">support@bookboll.com</a> and we will delete it.</p>
      </section>

      <section id="changes">
        <h2>12. Updates &amp; contact</h2>
        <p>This Privacy Policy may be updated occasionally. Continued use of the platform implies acceptance of the updated policy; material changes are announced on the site.</p>
        <p>Questions, data requests or privacy concerns: <a href="mailto:support@bookboll.com">support@bookboll.com</a>.</p>
        <p class="pp-note">See also our <router-link to="/terms">Terms &amp; Conditions</router-link>.</p>
      </section>
    </article>
  </div>
</template>

<style scoped>
.pp { max-width: 760px; margin: 0 auto; }
.pp-hero { padding: 6px 0 4px; }
.pp-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 12%, var(--surface, white)); padding: 5px 12px; border-radius: 999px; }
.pp h1 { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); margin: 12px 0 8px; }
.pp-sub { font-size: 15px; color: var(--text-secondary, #475569); line-height: 1.6; margin: 0; }
.pp-updated { margin-top: 10px; font-size: 12.5px; font-weight: 700; color: var(--text-light, #94a3b8); }

.pp-toc { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0 26px; }
.pp-toc a { font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #475569); text-decoration: none; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 999px; padding: 7px 12px; }
.pp-toc a:hover { color: var(--brand-ink, var(--brand)); border-color: var(--brand-accent); }

.pp-doc section { scroll-margin-top: 80px; margin-bottom: 26px; }
.pp-doc h2 { font-size: 18px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 10px; padding-bottom: 8px; border-bottom: 1px solid var(--border, #eef2f6); }
.pp-doc p { font-size: 14.5px; color: var(--text-secondary, #334155); line-height: 1.7; margin: 0 0 10px; }
.pp-doc ul { margin: 0 0 10px; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 8px; }
.pp-doc li { font-size: 14.5px; color: var(--text-secondary, #334155); line-height: 1.65; }
.pp-doc b { color: var(--text, #0f172a); }
.pp-doc a { color: var(--brand-ink, var(--brand)); font-weight: 700; }
.pp-callout { background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; padding: 12px 15px; color: var(--warning-ink, #92400e); }
.pp-callout a { color: inherit; text-decoration: underline; }
.pp-note { color: var(--text-muted, #64748b); font-size: 13.5px; }

@media (max-width: 760px) {
  .pp h1 { font-size: 22px; }
  .pp-sub { font-size: 14px; }
  .pp-doc h2 { font-size: 16.5px; }
  .pp-doc p, .pp-doc li { font-size: 14px; }
  .pp-toc { gap: 6px; margin: 16px 0 20px; }
  .pp-toc a { padding: 8px 12px; font-size: 12px; }
}
</style>
