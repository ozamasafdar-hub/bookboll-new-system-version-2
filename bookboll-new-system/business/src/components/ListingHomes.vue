<script setup>
import { ArrowRight, CalendarCheck, Check, Globe, LayoutDashboard, Search } from 'lucide-vue-next'
import { authUser } from '../lib/auth.js'
import { adminDashboardUrl } from '../lib/crossApp.js'

const homes = [
  {
    key: 'marketplace', icon: Search, title: 'On the Bookboll marketplace',
    audience: 'Reach Bookboll’s customers',
    description: 'People looking for a place to play can discover your listing alongside other venues on Bookboll.',
    points: ['Be found by customers browsing nearby', 'Let new customers view your listing and book'],
    address: 'bookboll.com', brand: 'Bookboll', navigation: 'Venues near you',
    action: 'Get listed on Bookboll', destination: '/listings',
  },
  {
    key: 'website', icon: Globe, title: 'On your own branded website',
    audience: 'Welcome your own customers',
    description: 'Give your customers a home that looks like your business, with your name, logo and colours.',
    points: ['Share your link on WhatsApp, Instagram or anywhere', 'Let your customers book directly on your website'],
    address: 'harbourclub.bookboll.com', brand: 'Harbour Club', navigation: 'Our courts',
    action: 'Create your own website', destination: '/brand',
  },
]
</script>

<template>
  <section id="two-homes" class="listing-homes" aria-labelledby="listing-homes-title">
    <div class="homes-inner">
      <header class="homes-heading">
        <h2 id="listing-homes-title">Your listing.<br>Two homes.</h2>
        <p>A place on Bookboll for customers to discover you.<br class="desktop-break"> A website of your own for the customers you bring.</p>
      </header>

      <div class="homes-grid">
        <article v-for="home in homes" :key="home.key" class="home" :class="`home-${home.key}`" :aria-labelledby="`home-${home.key}-title`">
          <div class="home-label"><component :is="home.icon" :size="18"/><span>{{ home.audience }}</span></div>
          <h3 :id="`home-${home.key}-title`">{{ home.title }}</h3>
          <p class="home-description">{{ home.description }}</p>

          <figure class="home-preview" :aria-label="`Example of the same Harbour Club padel court on ${home.key === 'marketplace' ? 'Bookboll’s marketplace' : 'the club’s branded website'}`">
            <div class="preview-address"><Globe :size="12"/><span>{{ home.address }}</span><small>Example</small></div>
            <div class="preview-brand"><strong><span class="preview-logo">{{ home.key === 'marketplace' ? 'B' : 'H' }}</span>{{ home.brand }}</strong><span>{{ home.navigation }}</span></div>
            <div class="preview-listing">
              <div class="court-picture" aria-hidden="true">
                <svg viewBox="0 0 300 170" preserveAspectRatio="xMidYMid slice" focusable="false">
                  <rect width="300" height="170" fill="#b9d6c5"/>
                  <path d="M0 145 300 65v105H0z" fill="#a5c8b4"/>
                  <g transform="translate(150 85) rotate(-12)">
                    <rect x="-111" y="-62" width="222" height="124" rx="2" fill="#205c50"/>
                    <rect x="-102" y="-53" width="204" height="106" fill="#73ae97" stroke="#eff8ee" stroke-width="2"/>
                    <path d="M-51-53V53M51-53V53M-102 0H102" fill="none" stroke="#eff8ee" stroke-width="2"/>
                    <path d="M0-64V64" stroke="#183f38" stroke-width="5"/>
                    <path d="M-2-64V64" stroke="#fff" stroke-width="1.5"/>
                    <circle cx="-65" cy="-24" r="6" fill="#fff0aa"/><circle cx="64" cy="20" r="6" fill="#fff"/>
                  </g>
                </svg>
                <span>Padel</span>
              </div>
              <div class="preview-caption"><span>Harbour Club</span><strong>Padel court</strong><div><CalendarCheck :size="13"/> View available times <ArrowRight :size="13"/></div></div>
            </div>
          </figure>

          <ul><li v-for="point in home.points" :key="point"><Check :size="16"/><span>{{ point }}</span></li></ul>
          <a v-if="authUser" :href="adminDashboardUrl(home.destination)" class="home-link">{{ home.key === 'marketplace' ? 'Manage your listings' : 'Manage your website' }} <ArrowRight :size="16"/></a>
          <router-link v-else to="/signup" class="home-link">{{ home.action }} <ArrowRight :size="16"/></router-link>
        </article>
      </div>

      <div class="shared-dashboard">
        <LayoutDashboard :size="27"/>
        <div><h3>Both bring bookings to one dashboard</h3><p>Manage your listings, calendar and payment records together, whichever home your customer books from.</p></div>
        <span>Your business stays in control</span>
      </div>
      <p class="homes-note">Marketplace listings are reviewed before going live. Booking fees vary by country and city. <router-link to="/booking-fees">Check local fees</router-link></p>
    </div>
  </section>
</template>

<style scoped>
.listing-homes{background:#edf7f2;padding:60px 0;scroll-margin-top:24px;color:#143e33}
.homes-inner{max-width:1200px;padding:0 26px;margin:auto}
.homes-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:35px;margin-bottom:30px}
.homes-heading h2{font-size:36px;line-height:1.12;letter-spacing:-1px;margin:0;color:#143e33}
.homes-heading>p{max-width:470px;font-size:16px;line-height:1.65;color:#526e60;margin:0}
.homes-grid{display:grid;grid-template-columns:1fr 1fr;gap:26px}
.home{--home-accent:#245fce;min-width:0;padding:28px;border:1px solid #ccdfd5;border-top:3px solid var(--home-accent);border-radius:12px;background:#fff;display:flex;flex-direction:column}
.home-website{--home-accent:#075e54}
.home-label{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:650;color:var(--home-accent);margin-bottom:14px}
.home h3{font-size:22px;line-height:1.3;letter-spacing:-.35px;margin:0;color:#143e33}
.home-description{font-size:14px;line-height:1.7;color:#526e60;margin:12px 0 22px;max-width:430px}
.home-preview{margin:0;border:1px solid #d9e4dd;border-radius:9px;overflow:hidden;background:#f4f7f5}
.preview-address{display:flex;align-items:center;gap:6px;padding:9px 12px;border-bottom:1px solid #d9e4dd;font-size:10px;color:#526e60;background:#fff}
.preview-address>span{overflow-wrap:anywhere;min-width:0}.preview-address svg{flex-shrink:0}
.preview-address small{font-size:9px;margin-left:auto;color:#64766d;padding-left:6px}
.preview-brand{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;background:#fff}
.preview-brand>strong{display:flex;align-items:center;gap:7px;font-size:14px;color:#173c32}
.preview-logo{display:grid;place-items:center;width:25px;height:25px;background:var(--home-accent);border-radius:7px;color:white;font-size:14px}
.home-website .preview-logo{border-radius:50%}
.preview-brand>span{font-size:10px;color:#526e60}
.preview-listing{display:grid;grid-template-columns:1.05fr 1fr;align-items:stretch;background:#fff;border:1px solid #dce7e0;border-radius:7px;overflow:hidden;margin:0 16px 16px}
.court-picture{position:relative;min-height:150px;overflow:hidden}.court-picture svg{width:100%;height:100%;position:absolute;inset:0}
.court-picture>span{position:absolute;left:10px;top:10px;font-size:9px;border-radius:4px;padding:3px 6px;background:#143e33;color:#fff}
.preview-listing .preview-caption{display:flex;flex-direction:column;justify-content:center;gap:5px;padding:17px 14px;min-width:0}
.preview-listing .preview-caption>span{font-size:10px;color:#526e60}.preview-listing .preview-caption>strong{font-size:17px;line-height:1.3;color:#143e33}
.preview-listing .preview-caption>div{display:flex;align-items:center;gap:5px;font-size:10px;line-height:1.5;color:var(--home-accent);margin-top:12px}.preview-listing .preview-caption svg{flex-shrink:0}
.home ul{list-style:none;display:grid;gap:10px;margin:22px 0;padding:0;font-size:13px;line-height:1.6}
.home li{display:flex;gap:8px;align-items:flex-start;color:#36594a}.home li svg{flex-shrink:0;color:var(--home-accent);margin-top:3px}
.home-link{display:inline-flex;gap:8px;align-items:center;align-self:flex-start;margin-top:auto;color:var(--home-accent);font-size:13px;font-weight:700;text-decoration:underline;text-underline-offset:4px}
.shared-dashboard{position:relative;display:flex;align-items:center;gap:17px;background:#fff;border:1px solid #ccdfd5;border-radius:9px;padding:24px;margin-top:30px}
.shared-dashboard:before{content:'';position:absolute;left:25%;right:25%;top:-31px;height:16px;border:1px solid #93bba7;border-top:0;border-radius:0 0 8px 8px}
.shared-dashboard:after{content:'';position:absolute;left:50%;top:-16px;height:15px;width:1px;background:#93bba7}
.shared-dashboard>svg{flex-shrink:0;color:#075e54}.shared-dashboard>div{flex:1}
.shared-dashboard h3{font-size:19px;line-height:1.4;margin:0;color:#143e33}.shared-dashboard p{font-size:13px;line-height:1.65;color:#526e60;margin:5px 0 0;max-width:620px}
.shared-dashboard>span{font-size:11px;color:#526e60;max-width:105px;text-align:right}
.homes-note{font-size:11px;line-height:1.7;color:#526e60;margin:17px 0 0}.homes-note a{color:#075e54;text-underline-offset:3px;white-space:nowrap}
.listing-homes a:focus-visible{outline:2px solid currentColor;outline-offset:4px}
@media(max-width:760px){
  .listing-homes{padding:38px 0}.homes-inner{padding:0 20px}
  .homes-heading{display:block;margin-bottom:24px}.homes-heading h2{font-size:32px}.homes-heading>p{font-size:14px;margin-top:18px}.desktop-break{display:none}
  .homes-grid{grid-template-columns:1fr;gap:20px}.home{padding:22px}.home h3{font-size:21px}.home-description{font-size:13px}
  .preview-brand{padding:12px}.preview-brand>strong{font-size:12px}.preview-brand>span{font-size:9px}.preview-logo{width:23px;height:23px}
  .preview-listing{margin:0 12px 12px;grid-template-columns:1fr 1fr}.court-picture{min-height:130px}.preview-listing .preview-caption{padding:13px 10px}.preview-listing .preview-caption>strong{font-size:15px}.preview-listing .preview-caption>div{font-size:9px;flex-wrap:wrap}
  .shared-dashboard{padding:20px;align-items:flex-start;gap:12px;margin-top:22px}.shared-dashboard h3{font-size:17px}.shared-dashboard p{font-size:12px}.shared-dashboard>span,.shared-dashboard:before{display:none}.shared-dashboard:after{top:-23px;height:22px}
}
@media(max-width:359px){.homes-inner{padding:0 16px}.home{padding:17px}.preview-brand>span{display:none}.home h3{font-size:19px}}
</style>
