import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse } from '@vue/compiler-sfc'
import { computed, reactive } from 'vue'

const source = parse(readFileSync(new URL('../src/components/BookingFeeComparison.vue', import.meta.url), 'utf8')).descriptor.scriptSetup.content
function comparison(props) {
  const run = new Function('computed', 'defineProps', 'defineEmits', source.replace(/^import .*$/gm, '') + '\nreturn {selected,percent,source,venueShare}')
  return run(computed, () => props, () => () => {})
}
test('comparison follows venue selection, retains zero and shows each rate source', () => {
  const props = reactive({modelValue:'1', data:{venues:[
    {id:1,rates:{facility:15,site_free:15,site_premium:10},sources:{site_free:'global',site_premium:'global'}},
    {id:2,rates:{facility:19,site_free:12.5,site_premium:0},sources:{site_free:'country',site_premium:'city'}},
  ]}})
  const view=comparison(props)
  assert.equal(view.percent('site_free'),'15%')
  props.modelValue='2'
  assert.equal(view.percent('site_premium'),'0%')
  assert.equal(view.percent('facility'),'19%')
  assert.equal(view.venueShare('site_free'),'87.50')
  assert.equal(view.venueShare('site_premium'),'100.00')
  assert.equal(view.source('site_free'),'Country rate')
  assert.equal(view.source('site_premium'),'City rate')
})
test('business without venues uses its country defaults, missing data never invents a fee', () => {
  const props=reactive({data:{venues:[],base:{rates:{site_free:13,site_premium:5},sources:{site_free:'country'}}}})
  const view=comparison(props)
  assert.equal(view.percent('site_free'),'13%')
  assert.equal(view.venueShare('site_premium'),'95.00')
  props.data=null
  assert.equal(view.percent('site_free'),'—')
  assert.equal(view.venueShare('site_premium'),'—')
})
