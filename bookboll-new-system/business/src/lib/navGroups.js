/**
 * navGroups.js — the admin dashboard's navigation IA, shared by:
 *   • App.vue           — icon rail + hover flyouts + mobile drawer + breadcrumb
 *   • pages/GroupNav.vue — the group landing page (/nav/:group) opened by
 *                          CLICKING a grouped rail button (hover still flies out)
 *
 * Nav IA (redesigned 2026-07-05): task-based groups, ONE canonical home per page —
 * no page appears in two menus. The mental model:
 *   run the day    → Calendar · Bookings
 *   what you sell  → Listings (listings + special days & closures)
 *   who buys       → Customers (customer list + membership subscribers)
 *   your money     → Payments & Wallets (payments + both payout wallets)
 *   your presence  → My Website
 *   growth         → Marketing (promo codes + outbound channels)
 *   configuration  → My Team · Settings
 *   platform staff → Admin (approvals/moderation/countries/audit; capability-gated)
 * "Analytics" (the charts page) is top-level so it stops hiding inside a junk drawer.
 *
 * `desc` is the one-liner on the group landing page cards.
 */
import { LayoutDashboard, Calendar, BookOpen, Users, Wallet, ArrowLeftRight, Building2, CalendarDays, Globe as Globe2, SquareCheck as CheckSquare, ScrollText, Settings, Bell, UsersRound, Store, Flag, Home, Megaphone, ShieldCheck, Repeat, Newspaper, LineChart, Images, Code2, Banknote, Link2, MailCheck, QrCode, Ticket } from 'lucide-vue-next'
import WhatsappIcon from '../components/WhatsappIcon.vue'

export const NAV_GROUPS = [
  { key: 'home', label: 'Home', icon: Home, to: '/admin' },
  { key: 'analytics', label: 'Analytics', icon: LayoutDashboard, to: '/dashboard' },
  { key: 'calendar', label: 'Calendar', icon: Calendar, to: '/calendar' },
  { key: 'bookings', label: 'Bookings', icon: BookOpen, to: '/bookings' },
  { key: 'listings', label: 'Listings', icon: Building2,
    blurb: 'What you sell — your bookable courts, spaces and activities, plus one-off exceptions to their schedules like holidays and special hours.',
    tips: [
      'Each listing carries its own weekly schedule, slot length, pricing and payment options.',
      'Special days override the weekly schedule for one date — close a holiday, or run different hours & prices.',
    ],
    items: [
    { path: '/listings', label: 'All listings', icon: Building2, desc: 'Courts, spaces, pricing & schedules' },
    { path: '/special-days', label: 'Special days & closures', icon: CalendarDays, desc: 'Holiday hours, closures & special pricing' },
  ]},
  { key: 'customers', label: 'Customers', icon: Users,
    blurb: 'The people who book with you and join your programs — profiles, contact details and booking history.',
    tips: [
      'A customer’s profile keeps their full booking and payment history.',
    ],
    items: [
    { path: '/customers', label: 'All customers', icon: Users, desc: 'Profiles, history & contact details' },
  ]},
  { key: 'money', label: 'Payments & Wallets', icon: Wallet,
    blurb: 'Your money, end to end — every payment recorded, and a payout wallet for venue bookings for venue bookings.',
    tips: [
      'Connect a payout method in each wallet to unlock online payments for that side of your business.',
      'Wallets are per country, so earnings always stay in their own currency.',
    ],
    items: [
    { path: '/payments', label: 'Payments', icon: ArrowLeftRight, desc: 'Every payment, in and out' },
    { path: '/wallet/bookings', label: 'Bookings wallet', icon: Banknote, desc: 'Venue earnings & payouts' },
  ]},
  { key: 'website', label: 'My Website', icon: Globe2,
    blurb: 'Create, edit and publish your own booking website with your own branding — customers book you directly, no coding needed.',
    tips: [
      'Start in Site: add your logo, colors and pages, then hit Publish — your site goes live on your bookboll.com address.',
      'Ready to look fully independent? Connect a custom domain and add extra hero slides in Home banners.',
      'Embed & Widgets drops a booking button into any website you already have.',
    ],
    items: [
    { path: '/brand', label: 'Site', icon: Store, desc: 'Branding, pages & publishing' },
    { path: '/website-domain', label: 'Custom domain', icon: Link2, desc: 'Use your own web address' },
    { path: '/banners', label: 'Home banners', icon: Images, desc: 'Hero slides on your homepage' },
    { path: '/website-blog', label: 'Blog', icon: Newspaper, desc: 'Posts on your own site' },
    { path: '/website-embed', label: 'Embed & Widgets', icon: Code2, desc: 'Booking widgets for any website' },
    { path: '/tracking', label: 'Tracking', icon: LineChart, desc: 'Analytics & ad pixels' },
  ]},
  { key: 'marketing', label: 'Marketing', icon: Megaphone,
    blurb: 'Bring customers in and keep them coming back — discounts, and messages sent from your own channels.',
    tips: [
      'Promo codes work at online checkout AND at your front desk when collecting payment.',
      'Connect WhatsApp and your own email sender so messages reach customers from YOUR business, not a generic address.',
    ],
    items: [
    { path: '/promocodes', label: 'Promo codes', icon: Ticket, desc: 'Discounts & campaigns' },
    { path: '/whatsapp', label: 'WhatsApp', icon: WhatsappIcon, desc: 'Your business messaging channel' },
    { path: '/email-sender', label: 'Email sender', icon: MailCheck, desc: 'Send from your own email address' },
    { path: '/blog-posts', label: 'Blogs', icon: Newspaper, desc: 'Marketplace & For Business posts' },
  ]},
  { key: 'team', label: 'My Team', icon: UsersRound, to: '/team' },
  { key: 'admin', label: 'Admin', icon: ShieldCheck,
    blurb: 'Platform controls for the Bookboll team — what goes live, where we operate, and who did what.',
    tips: [
      'Approvals is the daily queue: new venues, programs and payout requests wait there.',
      'Audit Logs answers "who changed this?" for every important action.',
    ],
    items: [
    { path: '/approvals', label: 'Approvals', icon: CheckSquare, desc: 'Venues, programs & payout requests' },
    { path: '/moderation', label: 'Event Moderation', icon: Flag, desc: 'Review community games & events' },
    { path: '/countries', label: 'Countries & Cities', icon: Globe2, desc: 'Where Bookboll operates' },
    { path: '/audit-logs', label: 'Audit Logs', icon: ScrollText, desc: 'Who changed what, and when' },
  ]},
  { key: 'settings', label: 'Settings', icon: Settings,
    blurb: 'Your account preferences, and the automatic messages customers receive.',
    tips: [
      'Automated notifications are service messages — booking confirmations, payment receipts, reminders — sent without you lifting a finger.',
    ],
    items: [
    { path: '/settings', label: 'General', icon: Settings, desc: 'Account & preferences' },
    { path: '/notifications', label: 'Automated notifications', icon: Bell, desc: 'Booking & payment service messages' },
  ]},
]
