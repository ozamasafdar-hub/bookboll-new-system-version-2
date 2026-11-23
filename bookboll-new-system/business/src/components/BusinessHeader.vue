<script setup>
/**
 * BusinessHeader.vue — the ONE top navigation for every business.bookboll.com page
 * (landing, pricing, blog, blog post, provider terms).
 *
 * Before this component each page carried its own copy (biz-nav / fbp-nav / fbb-nav /
 * pt-nav): the landing had the current light header while the others still used the
 * previous dark translucent bar on a white page, and the link sets disagreed — the
 * landing had no Blog link, pricing had no Pricing link, blog had neither, terms had
 * only a Back link. The markup and styles now live here once, so the header can never
 * drift per page again.
 *
 * Design follows the landing page (light surface, 42px brand mark, square-ish teal CTA).
 * Links are identical everywhere except `howItWorks`, an in-page anchor to the landing's
 * #tools section that only the landing page can scroll to.
 */
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import LanguageSwitcher from './LanguageSwitcher.vue'
import BrandMark from './BrandMark.vue'

defineProps({ howItWorks: { type: Boolean, default: false } })
</script>

<template>
  <header class="bh">
    <div class="bh-inner">
      <router-link to="/" class="bh-brand">
        <span class="bh-mark" data-no-translate><BrandMark /></span>
        <span data-no-translate>Bookboll<small>for Business</small></span>
      </router-link>
      <nav aria-label="Business navigation">
        <a v-if="howItWorks" href="#tools" class="bh-anchor">How it works</a>
        <router-link to="/pricing">Pricing</router-link>
        <router-link to="/booking-fees">Country &amp; city fees</router-link>
        <router-link to="/blog">Blog</router-link>
        <LanguageSwitcher compact />
        <a v-if="authUser" :href="adminDashboardUrl()" class="bh-cta">Open dashboard</a>
        <template v-else>
          <a :href="adminLoginUrl()">Sign in</a>
          <router-link to="/signup" class="bh-cta">Get started free</router-link>
        </template>
      </nav>
    </div>
  </header>
</template>

<style scoped>
.bh { --bh-ink: #12332f; --bh-green: #075e54; --bh-muted: #526762; background: #fff; border-bottom: 1px solid #dfe8e5; color: var(--bh-ink); line-height: 1.55; }
.bh *, .bh :deep(*) { box-sizing: border-box; }
.bh a { text-decoration: none; -webkit-tap-highlight-color: transparent; }
.bh :is(a, button):focus-visible { outline: 3px solid #1a6afd; outline-offset: 4px; }
.bh-inner { max-width: 1280px; margin: auto; padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; gap: 24px; }
.bh-brand { display: flex; align-items: center; gap: 10px; color: var(--bh-ink); font-size: 22px; font-weight: 800; line-height: 1.2; }
.bh-brand small { display: block; font-weight: 500; font-size: 12px; color: var(--bh-muted); margin-top: 4px; }
.bh-mark { width: 42px; height: 42px; flex-shrink: 0; background: #0fa59b; border-radius: 12px; display: grid; place-items: center; color: #fff; }
.bh nav { display: flex; align-items: center; gap: 24px; }
.bh nav > a { font-size: 14px; font-weight: 600; color: var(--bh-ink); }
.bh-cta { display: inline-flex; align-items: center; justify-content: center; gap: 10px; background: var(--bh-green); color: #fff !important; padding: 11px 17px; border-radius: 9px; font-size: 15px; font-weight: 700; min-height: 48px; white-space: nowrap; }
.bh-cta:hover { background: #044b43; }

@media (max-width: 1000px) {
  .bh nav { gap: 14px; }
  /* Out-specifies the `display: inline-flex` the narrow-screen block gives nav links,
     so the in-page anchor stays hidden instead of wrapping onto its own row. */
  .bh nav > .bh-anchor { display: none; }
}
@media (max-width: 760px) {
  .bh-inner { flex-direction: column; align-items: stretch; gap: 14px; padding: 15px 20px; }
  .bh-brand { align-self: flex-start; font-size: 21px; }
  .bh-brand small { font-size: 11px; }
  .bh nav { width: 100%; justify-content: space-between; flex-wrap: wrap; gap: 9px; }
  .bh nav > a { font-size: 12px; white-space: nowrap; min-height: 42px; display: inline-flex; align-items: center; }
  .bh-cta { padding: 11px 14px; min-height: 40px; font-size: 12px; margin-left: auto; }
}
@media (max-width: 360px) {
  .bh-inner { padding-inline: 16px; }
  .bh nav { column-gap: 8px; }
  .bh-cta { font-size: 11px; padding-inline: 10px; }
}
</style>
