<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { loadAnalytics } from '../lib/analytics.js'
import { periods, periodDates, dateError } from '../lib/bookingActivity.js'
import FilterBar from './FilterBar.vue'
import MultiSelect from './MultiSelect.vue'
import TrendChart from './viz/TrendChart.vue'
import HBarChart from './viz/HBarChart.vue'
const data=ref(null), loading=ref(true), error=ref(''), search=ref('')
const period=ref('all'), from=ref(''), to=ref(''), countries=ref([]), cities=ref([]), venues=ref([]), programs=ref([]), sports=ref([])
let sequence=0
async function load() {
  const id=++sequence; loading.value=true; error.value=''
  const result=await loadAnalytics({programsOnly:true})
  if(id !== sequence) return
  loading.value=false
  if(result.error) { error.value=result.error; data.value=null; return }
  data.value=result
}
onMounted(load); onBeforeUnmount(()=>++sequence)
const invalid=computed(()=>dateError(from.value,to.value))
const allPrograms=computed(()=>data.value?.programs || []), locations=computed(()=>data.value?.locations || [])
const programOptions=computed(()=>allPrograms.value.map(p=>({value:p.id,label:p.name})))
const venueOptions=computed(()=>locations.value.map(v=>({value:v.id,label:v.name})))
const sportOptions=computed(()=>[...new Set((data.value?.games || []).map(g=>g.sport).filter(Boolean))].map(value=>({value,label:value})))
const geoCountries=computed(()=>{
  const ids=new Set([...locations.value.map(v=>String(v.country_id)),...(data.value?.games || []).map(g=>String(g.country_id))])
  return (data.value?.countries || []).filter(c=>ids.has(String(c.id))).map(c=>({...c,id:String(c.id)}))
})
const geoCities=computed(()=>(data.value?.cities || []).map(c=>({...c,id:String(c.id),country_id:String(c.country_id)})))
const includes=(selection,value)=>!selection.length || selection.includes(String(value))
const inDate=value=>{ const day=String(value || '').slice(0,10); return (!from.value || day>=from.value) && (!to.value || (day && day<=to.value)) }
const matchesText=value=>!search.value.trim() || String(value || '').toLowerCase().includes(search.value.trim().toLowerCase())
const members=computed(()=>(data.value?.subscriptions || []).filter(s=>{
  const p=allPrograms.value.find(p=>p.id===s.program_id), v=locations.value.find(v=>v.id===p?.location_id)
  return includes(programs.value,s.program_id) && includes(venues.value,p?.location_id) && includes(countries.value,v?.country_id) && includes(cities.value,v?.city_id) && inDate(s.started_at) && matchesText(p?.name)
}))
const games=computed(()=>(data.value?.games || []).filter(g=>includes(countries.value,g.country_id) && includes(cities.value,g.city_id) && includes(sports.value,g.sport) && inDate(g.game_date || g.created_at) && matchesText(g.sport)))
function groups(rows,key) { const acc=new Map(); for(const row of rows) { const label=row[key] || 'Not recorded'; acc.set(label,(acc.get(label)||0)+1) } return [...acc].map(([label,value])=>({label,value})) }
function timeline(rows,key) { return groups(rows.map(r=>({month:String(r[key] || '').slice(0,7)})).filter(r=>r.month),'month').sort((a,b)=>a.label.localeCompare(b.label)) }
const memberTrend=computed(()=>timeline(members.value,'started_at'))
const eventTrend=computed(()=>timeline(games.value.map(g=>({...g,date:g.game_date || g.created_at})),'date'))
const memberStates=computed(()=>groups(members.value,'status'))
const eventStates=computed(()=>groups(games.value,'status'))
const fills=computed(()=>{
  const accepted=new Map(); for(const j of data.value?.gameJoins || []) if(j.status==='accepted') accepted.set(j.game_id,(accepted.get(j.game_id)||0)+1)
  const acc=new Map(); for(const g of games.value) if(g.status!=='cancelled') { const v=acc.get(g.sport)||{seats:0,total:0}; v.seats+=accepted.get(g.id)||0; v.total+=Number(g.max_players)||0; acc.set(g.sport,v) }
  return [...acc].map(([label,v])=>({label:label || 'Other',value:v.total ? Math.round(v.seats/v.total*100) : 0}))
})
function setPeriod(){ if(period.value!=='custom') [from.value,to.value]=periodDates(period.value) }
function reset(){ period.value='all'; from.value=''; to.value=''; countries.value=[]; cities.value=[]; venues.value=[]; programs.value=[]; sports.value=[]; search.value='' }
</script>
<template>
  <section class="program-activity">
    <div class="pr-period"><label>Period<select v-model="period" aria-label="Membership and event period" @change="setPeriod"><option v-for="[value,label] in periods" :key="value" :value="value">{{ label }}</option></select></label><template v-if="period==='custom'"><label>From<input v-model="from" type="date" aria-label="Activity start date" /></label><label>To<input v-model="to" type="date" aria-label="Activity end date" /></label></template><button class="btn btn-secondary" :disabled="loading" @click="load">Refresh</button></div>
    <FilterBar v-model:search="search" v-model:country="countries" v-model:city="cities" :countries="geoCountries" :cities="geoCities" :extra-count="venues.length+programs.length+sports.length" search-placeholder="Search program or event sport…" @clear="reset"><div class="pr-filters"><div><label>Membership venue</label><MultiSelect v-model="venues" :options="venueOptions" placeholder="All venues" /></div><div><label>Membership program</label><MultiSelect v-model="programs" :options="programOptions" placeholder="All programs" /></div><div><label>Event sport</label><MultiSelect v-model="sports" :options="sportOptions" placeholder="All sports" /></div></div></FilterBar>
    <p v-if="loading" role="status">Loading membership and event activity…</p><p v-else-if="error || invalid" role="alert">{{ error || invalid }}</p>
    <template v-else>
      <h2>Membership activity</h2><p>Dates use membership start dates. Venue and program filters apply here.</p>
      <div class="pr-grid"><section class="card"><h3>Membership sign-ups</h3><p>{{ members.length.toLocaleString() }} memberships in this view</p><TrendChart :points="memberTrend" color="#4c8dff" mode="bars" /></section><section class="card"><h3>Members by status</h3><p>Current status of memberships in this view</p><HBarChart :rows="memberStates" color="#8e9bff" /></section></div>
      <template v-if="data?.games?.length"><h2>Events</h2><p>Dates use event dates. Sport and location filters apply here.</p><div class="pr-grid"><section class="card"><h3>Events hosted</h3><p>{{ games.length.toLocaleString() }} events in this view</p><TrendChart :points="eventTrend" color="#4c8dff" mode="bars" /></section><section class="card"><h3>Event status</h3><HBarChart :rows="eventStates" color="#8e9bff" /></section><section class="card"><h3>Seat fill rate by sport</h3><p>Accepted players ÷ capacity · cancelled events excluded</p><HBarChart :rows="fills" suffix="%" color="#4c8dff" /></section></div></template>
    </template>
  </section>
</template>
<style scoped>
.pr-period,.pr-filters{display:flex;gap:16px;flex-wrap:wrap;align-items:end;margin-bottom:20px}.pr-period label,.pr-filters>div{display:grid;gap:7px;font-size:12px;min-width:150px}.pr-period select,.pr-period input{padding:9px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);font:inherit}.pr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.pr-grid .card{padding:22px}.program-activity h2{font-size:18px;margin:26px 0 6px}.program-activity h3{font-size:15px;margin:0 0 8px}.program-activity p{font-size:12px;color:var(--muted);line-height:1.6;margin:0 0 18px}@media(max-width:800px){.pr-grid{grid-template-columns:1fr}}
</style>
