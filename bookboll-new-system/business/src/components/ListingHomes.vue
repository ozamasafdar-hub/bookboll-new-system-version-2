<script setup>
import { ArrowDown, ArrowRight, CalendarCheck, Globe, Search, UsersRound } from 'lucide-vue-next'
import { authUser } from '../lib/auth.js'
import { adminDashboardUrl } from '../lib/crossApp.js'

const homes = [
  { key: 'marketplace', audience: 'Bookboll’s customers', journey: 'Looking for a place to play', title: 'Bookboll marketplace', address: 'bookboll.com', description: 'Get discovered alongside other venues.' },
  { key: 'website', audience: 'Your customers', journey: 'Following the link you share', title: 'Your own website', address: 'yourclub.bookboll.com', description: 'Your name. Your logo. Your colours.' },
]
</script>

<template>
  <section id="two-homes" class="listing-homes" aria-labelledby="listing-homes-title">
    <div class="homes-inner">
      <header class="homes-heading">
        <h2 id="listing-homes-title">One listing. Two ways to get booked.</h2>
        <p>Get discovered on Bookboll. Bring your own customers to your website.</p>
      </header>

      <div class="homes-grid">
        <article v-for="home in homes" :key="home.key" class="home" :class="'home-'+home.key" :aria-labelledby="'audience-'+home.key">
          <div class="home-audience">
            <span class="audience-icon"><UsersRound :size="25"/></span>
            <div><h3 :id="'audience-'+home.key">{{ home.audience }}</h3><p>{{ home.journey }}</p></div>
          </div>
          <ArrowDown class="journey-arrow" :size="24" aria-hidden="true"/>

          <figure class="home-preview" :aria-labelledby="'destination-'+home.key">
            <figcaption class="preview-header">
              <component :is="home.key==='marketplace' ? Search : Globe" :size="20"/>
              <div><h4 :id="'destination-'+home.key">{{ home.title }}</h4><span>{{ home.address }}</span></div>
              <span v-if="home.key==='website'" class="club-logo" aria-hidden="true">HC</span>
            </figcaption>
            <div class="preview-content">
              <div v-if="home.key==='marketplace'" class="nearby-venues" aria-hidden="true"><span></span><i></i><i></i></div>
              <div v-else class="club-welcome">Welcome to Harbour Club</div>
              <div class="preview-listing">
                <div class="court-picture" aria-hidden="true">
                  <svg viewBox="0 0 300 170" preserveAspectRatio="xMidYMid slice" focusable="false">
                    <rect width="300" height="170" fill="#bad9c8"/>
                    <g transform="translate(150 85) rotate(-12)">
                      <rect x="-111" y="-62" width="222" height="124" rx="2" fill="#205c50"/>
                      <rect x="-102" y="-53" width="204" height="106" fill="#73ae97" stroke="#eff8ee" stroke-width="2"/>
                      <path d="M-51-53V53M51-53V53M-102 0H102" fill="none" stroke="#eff8ee" stroke-width="2"/>
                      <path d="M0-64V64" stroke="#183f38" stroke-width="5"/>
                      <path d="M-2-64V64" stroke="#fff" stroke-width="1.5"/>
                      <circle cx="-65" cy="-24" r="6" fill="#fff0aa"/><circle cx="64" cy="20" r="6" fill="#fff"/>
                    </g>
                  </svg>
                </div>
                <div class="listing-caption"><strong>Your listing</strong><span>Harbour Club · Padel court</span><span class="booking-label">Book a court <ArrowRight :size="13"/></span></div>
              </div>
              <div v-if="home.key==='marketplace'" class="nearby-venues" aria-hidden="true"><span></span><i></i><i></i></div>
              <div v-else class="club-footer">Powered by Bookboll</div>
            </div>
          </figure>
          <p class="home-description">{{ home.description }}</p>
        </article>
      </div>

      <div class="booking-connector" aria-hidden="true"><span></span><ArrowDown :size="22"/></div>
      <div class="shared-dashboard">
        <CalendarCheck :size="31"/>
        <div><h3>One dashboard. One shared calendar.</h3><p>Bookings and payment records from both, together.</p></div>
      </div>
      <div class="homes-footer">
        <p>Example: the same court in both places.<br>Marketplace approval applies. <router-link to="/booking-fees">Check local booking fees</router-link></p>
        <a v-if="authUser" :href="adminDashboardUrl()" class="homes-cta">Open your dashboard <ArrowRight :size="16"/></a>
        <router-link v-else to="/signup" class="homes-cta">Create your free business account <ArrowRight :size="16"/></router-link>
      </div>
    </div>
  </section>
</template>

<style scoped>
.listing-homes{background:#edf7f2;padding:34px 0;scroll-margin-top:20px;color:#143e33}
.homes-inner{max-width:1140px;padding:0 26px;margin:auto}
.homes-heading{margin-bottom:26px;text-align:center}
.homes-heading h2{font-size:36px;line-height:1.18;letter-spacing:-1px;color:#143e33;margin:0}
.homes-heading p{font-size:16px;line-height:1.6;color:#526e60;margin:12px 0 0}
.homes-grid{display:grid;grid-template-columns:1fr 1fr;gap:56px}
.home{--accent:#245fce;--pale:#edf3ff;min-width:0;text-align:center}
.home-website{--accent:#075e54;--pale:#e7f3ed}
.home-audience{display:flex;justify-content:center;align-items:center;gap:12px;min-height:58px}
.audience-icon{width:46px;height:46px;display:grid;place-items:center;color:var(--accent);background:#fff;border-radius:50%;flex-shrink:0}
.home-audience>div{text-align:left}.home-audience h3{font-size:26px;line-height:1.2;letter-spacing:-.5px;margin:0;color:var(--accent)}
.home-audience p{font-size:12px;line-height:1.5;color:#526e60;margin:6px 0 0}
.journey-arrow{display:block;margin:10px auto;color:var(--accent)}
.home-preview{margin:0;background:white;border:1px solid #c6d9ce;border-radius:10px;overflow:hidden;text-align:left}
.preview-header{display:flex;align-items:center;gap:10px;padding:12px 19px;color:var(--accent);background:var(--pale);border-bottom:1px solid #d5e3d9;min-height:65px}
.preview-header>svg{flex-shrink:0}.preview-header h4{font-size:19px;line-height:1.3;margin:0;color:var(--accent)}
.preview-header>div>span{font-size:11px;line-height:1.5;display:block;margin-top:3px;color:#526e60}
.club-logo{display:grid;place-items:center;margin-left:auto;border-radius:50%;width:33px;height:33px;background:#075e54;color:white;font-size:12px;font-weight:750;flex-shrink:0}
.preview-content{padding:8px 18px;background:#fff}
.nearby-venues{display:flex;align-items:center;gap:9px;height:14px;opacity:.65}
.nearby-venues>span{height:15px;width:26px;background:#dbe4e9;border-radius:3px}.nearby-venues>i{height:4px;width:20%;background:#dbe4e9;border-radius:2px}.nearby-venues>i:last-child{width:12%;margin-left:auto}
.club-welcome,.club-footer{height:14px;display:flex;align-items:center;font-size:11px;color:#526e60}.club-welcome{font-weight:600;color:#075e54}.club-footer{justify-content:flex-end;font-size:9px}
.preview-listing{display:grid;grid-template-columns:126px 1fr;align-items:stretch;border:2px solid var(--accent);background:var(--pale);border-radius:7px;overflow:hidden;margin:5px 0}
.court-picture{height:84px;overflow:hidden}.court-picture svg{display:block;height:100%;width:100%}
.listing-caption{display:flex;flex-direction:column;justify-content:center;gap:5px;padding:11px 15px;min-width:0}
.listing-caption>strong{font-size:20px;line-height:1.2;color:var(--accent)}.listing-caption>span{font-size:11px;line-height:1.4;color:#526e60}
.listing-caption .booking-label{display:flex;align-items:center;gap:6px;color:var(--accent);font-size:10px;margin-top:7px}
.home-description{font-size:13px;line-height:1.5;color:#526e60;margin:13px 0 0}
.booking-connector{position:relative;height:38px;color:#52876d}
.booking-connector>span{position:absolute;left:25%;right:25%;height:17px;border:2px solid #9bc1ad;border-top:0;border-radius:0 0 8px 8px}
.booking-connector>svg{position:absolute;left:calc(50% - 11px);top:16px}
.shared-dashboard{display:flex;align-items:center;justify-content:center;gap:17px;padding:18px 26px;background:#073f36;color:white;border-radius:10px}
.shared-dashboard>svg{flex-shrink:0;color:#d8ed8c}.shared-dashboard h3{font-size:23px;line-height:1.3;color:white;margin:0;letter-spacing:-.3px}
.shared-dashboard p{font-size:13px;line-height:1.5;color:#c9dfd4;margin:6px 0 0}
.homes-footer{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-top:22px}
.homes-footer>p{font-size:11px;line-height:1.8;color:#526e60;margin:0}.homes-footer>p a{color:#075e54;text-underline-offset:3px}
.homes-cta{display:inline-flex;align-items:center;justify-content:center;gap:10px;color:#075e54;font-size:13px;font-weight:700;text-decoration:underline;text-underline-offset:4px}
.listing-homes a:focus-visible{outline:2px solid currentColor;outline-offset:4px}
@media(max-width:760px){
  .listing-homes{padding:34px 0}.homes-inner{padding:0 20px}.homes-heading{margin-bottom:23px}.homes-heading h2{font-size:29px;letter-spacing:-.6px}.homes-heading p{font-size:14px}
  .homes-grid{gap:14px}.home-audience{flex-direction:column;gap:9px;min-height:92px;justify-content:flex-start}.audience-icon{width:35px;height:35px}.audience-icon svg{width:21px}
  .home-audience>div{text-align:center}.home-audience h3{font-size:18px;line-height:1.25;letter-spacing:-.2px;max-width:160px;min-height:45px}.home-audience p{display:none}.journey-arrow{margin:7px auto}
  .preview-header{padding:13px 9px;min-height:79px;display:block}.preview-header>svg,.club-logo{display:none}.preview-header h4{font-size:15px;min-height:39px}.preview-header>div>span{font-size:9px;overflow-wrap:anywhere}
  .preview-content{padding:8px}.nearby-venues,.club-welcome,.club-footer{display:none}.preview-listing{grid-template-columns:1fr;margin:0;border-width:1px}.court-picture{height:76px}.listing-caption{padding:10px 8px;gap:4px}.listing-caption>strong{font-size:16px}.listing-caption>span{font-size:10px}.listing-caption .booking-label{display:none}
  .home-description{font-size:11px;line-height:1.6;margin-top:10px}.booking-connector{height:32px}.booking-connector>span{height:12px}.booking-connector>svg{top:10px}
  .shared-dashboard{padding:19px 16px;gap:12px;align-items:flex-start}.shared-dashboard>svg{width:25px}.shared-dashboard h3{font-size:19px;line-height:1.3}.shared-dashboard p{font-size:12px}
  .homes-footer{flex-direction:column-reverse;gap:16px;text-align:center}.homes-cta{min-height:44px;font-size:13px}.homes-footer>p{font-size:10px}
}
@media(max-width:359px){.homes-inner{padding:0 14px}.homes-grid{gap:10px}.homes-heading h2{font-size:27px}.home-audience h3{font-size:16px}.preview-header h4{font-size:14px}.preview-header>div>span{font-size:8px}.listing-caption>strong{font-size:14px}}
</style>
