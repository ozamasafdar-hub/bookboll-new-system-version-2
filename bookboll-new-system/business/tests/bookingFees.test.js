import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {parse} from '@vue/compiler-sfc'
import {ref,computed,nextTick} from 'vue'
const script=parse(readFileSync(new URL('../src/pages/BookingFees.vue',import.meta.url),'utf8')).descriptor.scriptSetup.content.replace(/^import .*$/gm,'')
function page(apiFetch){return new Function('ref','computed','nextTick','onMounted','useSeo','apiFetch',script+';return {countries,filtered,visibleCountries,countryLimit,search,customCountries,selected,citySearch,customCities,cities,cityError,cityLoading,pagination,loadCities,loadCountries,error,global}') (ref,computed,nextTick,()=>{},()=>{},apiFetch)}
test('country list stays short while search still includes countries beyond the visible rows',()=>{
 const p=page(()=>{}),sources={facility:'global',site:'global'}
 p.countries.value=Array.from({length:45},(_,i)=>({id:i,name:`Country ${i}`,code:`C${i}`,sources,custom_city_count:0}))
 assert.equal(p.visibleCountries.value.length,20)
 p.countryLimit.value+=20;assert.equal(p.visibleCountries.value.length,40)
 p.search.value='Country 44';assert.deepEqual(p.visibleCountries.value.map(c=>c.id),[44])
})
test('country filter includes countries with only city overrides and preserves search',()=>{
 const p=page(()=>{}),sources={facility:'global',site:'global'}
 p.countries.value=[{id:1,name:'Qatar',code:'QA',sources,custom_city_count:0},{id:2,name:'Pakistan',code:'PK',sources,custom_city_count:1},{id:3,name:'Test',code:'TT',sources:{...sources,site:'country'},custom_city_count:0}]
 p.customCountries.value=true
 assert.deepEqual(p.filtered.value.map(c=>c.id),[2,3])
 p.search.value='pk'
 assert.deepEqual(p.filtered.value.map(c=>c.id),[2])
})
test('late city results cannot overwrite a newly selected country',async()=>{
 const pending=[],p=page((path,options)=>new Promise(resolve=>pending.push({path,options,resolve})))
 p.selected.value={id:1};const first=p.loadCities()
 p.selected.value={id:2};p.customCities.value=true;p.citySearch.value='A city';const second=p.loadCities(2)
 assert.equal(pending[1].path,'/business/booking-fees/countries/2/cities')
 assert.deepEqual(pending[1].options.query,{search:'A city',custom_only:1,page:2})
 assert.equal(pending[1].options.token,'')
 pending[1].resolve({success:true,data:{cities:[{id:22}],pagination:{total:51,current_page:2,last_page:2}}});await second
 pending[0].resolve({success:true,data:{cities:[{id:11}],pagination:{total:1}}});await first
 assert.deepEqual(p.cities.value,[{id:22}]);assert.equal(p.cityLoading.value,false);assert.equal(p.pagination.value.current_page,2)
})
test('failed city requests clear stale fees and provide a retry without guessing defaults',async()=>{
 let succeeds=false;const p=page(async()=>succeeds?{success:true,data:{cities:[{id:3}],pagination:{total:1}}}:{success:false})
 p.selected.value={id:1};p.cities.value=[{id:2}]
 await p.loadCities();assert.deepEqual(p.cities.value,[]);assert.ok(p.cityError.value);assert.equal(p.cityLoading.value,false)
 succeeds=true;await p.loadCities();assert.equal(p.cityError.value,'');assert.deepEqual(p.cities.value,[{id:3}])
})
