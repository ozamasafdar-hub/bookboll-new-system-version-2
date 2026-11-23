<script setup>
import {computed,nextTick,onMounted,ref} from 'vue'
import BusinessHeader from '../components/BusinessHeader.vue'
import BookingRateCells from '../components/BookingRateCells.vue'
import {apiFetch} from '../lib/api.js'
import {useSeo} from '../lib/seo.js'
import {ArrowRight,MapPin,Search} from 'lucide-vue-next'
useSeo({exactTitle:'Booking fees by country & city | Bookboll for Business',description:'Compare Bookboll marketplace, Free website and Premium website booking fees. Find country rates, city overrides and current global defaults.',path:'/booking-fees'})
const global=ref(null), countries=ref([]), loading=ref(true), error=ref('')
const search=ref(''), customCountries=ref(false), selected=ref(null)
const citySearch=ref(''), customCities=ref(false), cities=ref([]), cityLoading=ref(false), cityError=ref(''), pagination=ref(null), cityPanel=ref(null)
let cityRequest=0
const filtered=computed(()=>countries.value.filter(c=>(!customCountries.value || c.custom_city_count>0 || Object.values(c.sources).some(s=>s!=='global')) && `${c.name} ${c.code}`.toLowerCase().includes(search.value.trim().toLowerCase())))
async function loadCountries(){
  loading.value=true;error.value=''
  try{const r=await apiFetch('/business/booking-fees/countries',{token:''});if(!r.success)throw new Error();countries.value=r.data.countries;global.value=r.data.global}
  catch{error.value='We could not load current rates. Please try again.'}
  finally{loading.value=false}
}
async function loadCities(page=1){
  if(!selected.value)return
  const request=++cityRequest; cityLoading.value=true;cityError.value='';cities.value=[];pagination.value=null
  try{
    const r=await apiFetch(`/business/booking-fees/countries/${selected.value.id}/cities`,{token:'',query:{search:citySearch.value.trim(),custom_only:customCities.value?1:0,page}})
    if(request!==cityRequest)return
    if(!r.success)throw new Error()
    cities.value=r.data.cities;pagination.value=r.data.pagination
  }catch{if(request===cityRequest)cityError.value='We could not load these city rates. Please try again.'}
  finally{if(request===cityRequest)cityLoading.value=false}
}
async function selectCountry(country){selected.value=country;citySearch.value='';customCities.value=false;loadCities();await nextTick();cityPanel.value?.scrollIntoView({block:'start',behavior:'auto'})}
onMounted(loadCountries)
</script>
<template>
  <div class="local-fees">
    <BusinessHeader />
    <main class="rates-main">
      <header class="rates-intro">
        <router-link to="/pricing">Pricing &amp; plans <ArrowRight :size="15" /></router-link>
        <h1>Booking fees,<br>where you do business.</h1>
        <p>Find the rate for your venue’s country and city. These fees are included in the booking price, separate from your Premium subscription.</p>
      </header>
      <p v-if="loading" role="status" class="rates-notice">Loading current booking fees…</p>
      <div v-else-if="error" role="alert" class="rates-notice">{{error}} <button @click="loadCountries">Retry rates</button></div>
      <template v-else-if="global">
        <section class="global-rates" aria-labelledby="global-rates-title">
          <div><h2 id="global-rates-title">Global defaults</h2><p>Used unless a country or city has its own rate.</p></div>
          <dl><div><dt>Marketplace · both plans</dt><dd>{{global.rates.facility}}%</dd></div><div><dt>Your website · Free</dt><dd>{{global.rates.site_free}}%</dd></div><div><dt>Your website · Premium</dt><dd>{{global.rates.site_premium}}%</dd></div></dl>
        </section>
        <div class="rate-order"><strong>Which rate applies?</strong><span>City rate <ArrowRight :size="14" /> Country rate <ArrowRight :size="14" /> Global default</span><p>Use the first rate available for each booking channel. A city can change one fee and inherit the others.</p></div>
        <section aria-labelledby="country-heading" class="directory">
          <div class="section-heading"><div><h2 id="country-heading">Countries &amp; their rates</h2><p>Open a country to see its city rates. Only enabled locations are listed.</p></div><span>{{filtered.length}} {{filtered.length===1?'country':'countries'}}</span></div>
          <div class="rate-filters"><label class="rate-search"><span>Find a country</span><div><Search :size="17"/><input v-model="search" type="search" placeholder="Country name or code" /></div></label><label class="check-filter"><input v-model="customCountries" type="checkbox"> With country or city overrides only</label></div>
          <div class="rates-table-wrap"><table><caption class="sr-only">Current country booking fees</caption><thead><tr><th scope="col">Country</th><th scope="col">Marketplace<br><small>Both plans</small></th><th scope="col">Website<br><small>Free</small></th><th scope="col">Website<br><small>Premium</small></th></tr></thead><tbody>
            <tr v-for="country in filtered" :key="country.id" :class="{chosen:selected?.id===country.id}"><th scope="row"><button class="country-button" :aria-label="`View cities in ${country.name}`" :aria-expanded="selected?.id===country.id" aria-controls="city-rates" @click="selectCountry(country)">{{country.name}} <ArrowRight :size="15"/></button><small>{{country.custom_city_count ? `${country.custom_city_count} cities with custom rates` : 'View city rates'}}</small></th><BookingRateCells :row="country"/></tr>
          </tbody></table></div>
          <div v-if="!filtered.length" class="rates-notice">{{customCountries?'No countries match this filter. Locations without overrides use the global defaults above.':'No countries match your search.'}} <button @click="search='';customCountries=false">Show all countries</button></div>
        </section>
        <section v-if="selected" id="city-rates" ref="cityPanel" class="directory city-directory" aria-labelledby="city-heading">
          <div class="section-heading"><div><h2 id="city-heading"><MapPin :size="23"/> {{selected.name}} · city rates</h2><p>Each fee shows whether it comes from the city, country or global default.</p></div></div>
          <form class="rate-filters" @submit.prevent="loadCities(1)"><label class="rate-search"><span>Find a city in {{selected.name}}</span><div><Search :size="17"/><input v-model="citySearch" type="search" maxlength="100" placeholder="City name" /><button type="submit">Search</button></div></label><label class="check-filter"><input v-model="customCities" type="checkbox" @change="loadCities(1)"> Custom city rates only</label></form>
          <p v-if="cityLoading" role="status" class="rates-notice">Loading city rates…</p>
          <div v-else-if="cityError" role="alert" class="rates-notice">{{cityError}} <button @click="loadCities(1)">Retry city rates</button></div>
          <template v-else>
            <div v-if="cities.length" class="rates-table-wrap"><table><caption class="sr-only">Current {{selected.name}} city booking fees</caption><thead><tr><th scope="col">City</th><th scope="col">Marketplace<br><small>Both plans</small></th><th scope="col">Website<br><small>Free</small></th><th scope="col">Website<br><small>Premium</small></th></tr></thead><tbody><tr v-for="city in cities" :key="city.id"><th scope="row">{{city.name}}</th><BookingRateCells :row="city"/></tr></tbody></table></div>
            <p v-else class="rates-notice">{{customCities?'No custom city rates match. Other enabled cities follow the country rates shown above.':'No enabled cities match your search.'}}</p>
            <nav v-if="pagination?.total" class="city-pagination" aria-label="City rates pages"><span>{{pagination.total}} cities · Page {{pagination.current_page}} of {{pagination.last_page}}</span><div><button :disabled="pagination.current_page<=1" @click="loadCities(pagination.current_page-1)">Previous</button><button :disabled="pagination.current_page>=pagination.last_page" @click="loadCities(pagination.current_page+1)">Next</button></div></nav>
          </template>
        </section>
        <aside class="rates-explainer"><h2>What these fees mean for your business</h2><ul><li><strong>Your website:</strong> the applicable Free or eligible Premium rate is used for deposit and full online bookings.</li><li><strong>Bookboll marketplace:</strong> the geographic rate is the same on both plans. A listing-specific rate or fixed deposit can take priority; your dashboard shows those details.</li><li><strong>Staff-added bookings:</strong> no platform booking fee. Your venue keeps that revenue.</li></ul><p>Rates shown are current settings for new bookings. Existing booking fee records stay unchanged. Payment processing charges and membership listing subscriptions are separate.</p><router-link to="/pricing">Compare Free and Premium <ArrowRight :size="16"/></router-link></aside>
      </template>
    </main>
    <footer><router-link to="/">Bookboll for Business</router-link><router-link to="/pricing">Pricing</router-link><router-link to="/terms">Provider terms</router-link></footer>
  </div>
</template>
<style scoped>
.local-fees{background:#fff;color:#12332f;min-height:100vh;font-size:15px;line-height:1.6}.local-fees *{box-sizing:border-box}.rates-main{max-width:1100px;margin:auto;padding:56px 32px 70px}.rates-intro{max-width:730px;margin-bottom:36px}.rates-intro>a,.rates-explainer>a{display:inline-flex;gap:8px;align-items:center;color:#075e54;font-weight:650;text-decoration:none}.rates-intro h1{font-size:46px;line-height:1.12;letter-spacing:-1.5px;margin:18px 0}.rates-intro p{font-size:17px;color:#526762;max-width:640px;margin:0}.global-rates{padding:26px 28px;background:#075e54;color:white;border-radius:14px}.global-rates h2{font-size:20px;margin:0}.global-rates p{color:#d2e9e1;margin:3px 0 0;font-size:13px}.global-rates dl{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:24px 0 0}.global-rates dl>div+div{border-left:1px solid #ffffff35;padding-left:24px}.global-rates dt{font-size:13px;color:#d2e9e1}.global-rates dd{font-size:34px;font-weight:700;margin:4px 0 0;font-variant-numeric:tabular-nums}.rate-order{padding:22px 0 28px;color:#526762;font-size:13px}.rate-order strong{color:#12332f;margin-right:22px}.rate-order span{display:inline-flex;align-items:center;gap:12px;color:#075e54;font-weight:650}.rate-order p{margin:5px 0 0}.directory{margin-top:24px;border-top:1px solid #dfe8e5;padding-top:28px}.section-heading{display:flex;justify-content:space-between;gap:20px;align-items:baseline}.section-heading h2{font-size:25px;line-height:1.3;margin:0;display:flex;align-items:center;gap:9px}.section-heading p{font-size:13px;color:#526762;margin:7px 0 0}.section-heading>span{color:#526762;font-size:12px;white-space:nowrap}.rate-filters{display:flex;gap:22px;align-items:flex-end;justify-content:space-between;margin:25px 0 20px}.rate-search{display:block;flex:1;max-width:440px}.rate-search>span{display:block;font-size:12px;font-weight:650;margin-bottom:6px}.rate-search>div{display:flex;align-items:center;gap:10px;border:1px solid #b9cec5;border-radius:8px;padding:0 12px;background:#fff}.rate-search input{min-width:0;flex:1;border:0;background:transparent;padding:12px 0;color:#12332f;font:inherit;outline:none}.rate-search>div:focus-within{outline:2px solid #075e54;outline-offset:2px}.check-filter{display:flex;align-items:center;gap:8px;font-size:12px;padding-bottom:11px}.check-filter input{accent-color:#075e54;width:17px;height:17px}.rates-table-wrap{overflow-x:auto}.rates-table-wrap table{border-collapse:collapse;width:100%;table-layout:fixed}.rates-table-wrap th{padding:16px 12px;border-bottom:1px solid #dfe8e5;text-align:right;font-weight:600;font-size:13px}.rates-table-wrap th:first-child{width:35%;text-align:left;padding-left:0}.rates-table-wrap thead{background:#f4f8f6;color:#526762}.rates-table-wrap thead th:first-child{padding-left:12px}.rates-table-wrap small{display:block;font-weight:400;font-size:11px;color:#526762}.rates-table-wrap tbody th{color:#12332f}.country-button{display:inline-flex;align-items:center;gap:8px;background:none;border:0;padding:5px 0;text-align:left;font:inherit;font-size:15px;color:#075e54;font-weight:700;cursor:pointer}.country-button:hover{text-decoration:underline}.chosen{background:#f4f8f6}.city-directory{scroll-margin-top:24px}.city-pagination{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:18px;font-size:12px;color:#526762}.city-pagination>div{display:flex;gap:8px}.local-fees button:not(.country-button){cursor:pointer;border:1px solid #b9cec5;color:#075e54;background:#fff;border-radius:6px;padding:7px 12px;font:inherit;font-size:12px;font-weight:650}.local-fees button:disabled{opacity:.4;cursor:default}.local-fees :is(a,button,input):focus-visible{outline:3px solid #0fa59b;outline-offset:3px}.rates-notice{padding:22px 0;color:#526762}.rates-notice button{margin-left:8px}.rates-explainer{margin-top:42px;padding:26px 28px;background:#f4f8f6;border-radius:12px}.rates-explainer h2{font-size:20px;margin:0 0 15px}.rates-explainer ul{padding-left:20px;margin:0;display:grid;gap:12px;font-size:13px}.rates-explainer p{color:#526762;font-size:12px;max-width:780px;margin:18px 0}.rates-explainer>a{font-size:14px}footer{display:flex;justify-content:center;gap:24px;border-top:1px solid #dfe8e5;padding:25px;font-size:12px}footer a{color:#526762}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:600px){.rates-main{padding:32px 18px 42px}.rates-intro h1{font-size:35px;letter-spacing:-.8px}.rates-intro p{font-size:15px}.global-rates{padding:23px 18px}.global-rates dl{gap:10px}.global-rates dl>div+div{padding-left:10px}.global-rates dd{font-size:28px}.global-rates dt{font-size:11px;line-height:1.5}.rate-order strong{display:block;margin-bottom:8px}.rate-order span{gap:7px;font-size:12px}.rate-filters{display:block}.rate-search{max-width:none}.rate-search input{font-size:16px}.check-filter{padding:14px 0 0}.section-heading h2{font-size:22px}.section-heading>span{display:none}.rates-table-wrap th{font-size:11px;padding:14px 5px}.rates-table-wrap th:first-child{width:34%}.rates-table-wrap small{font-size:10px}.country-button{font-size:13px;gap:3px}.country-button svg{flex-shrink:0}.rates-explainer{padding:22px 18px}.city-pagination{flex-wrap:wrap}footer{gap:16px}}
</style>
