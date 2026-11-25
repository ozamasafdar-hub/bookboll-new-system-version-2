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
    {id:1,rates:{facility:15,site:10},sources:{site:'global'}},
    {id:2,rates:{facility:19,site:0},sources:{facility:'country',site:'city'}},
  ]}})
  const view=comparison(props)
  assert.equal(view.percent('site'),'10%')
  props.modelValue='2'
  assert.equal(view.percent('site'),'0%')
  assert.equal(view.percent('facility'),'19%')
  assert.equal(view.venueShare('site'),'100.00')
  assert.equal(view.source('facility'),'Country rate')
  assert.equal(view.source('site'),'City rate')
})
test('business without venues uses its country defaults, missing data never invents a fee', () => {
  const props=reactive({data:{venues:[],base:{rates:{site:13},sources:{site:'country'}}}})
  const view=comparison(props)
  assert.equal(view.percent('site'),'13%')
  assert.equal(view.venueShare('site'),'87.00')
  props.data=null
  assert.equal(view.percent('site'),'—')
  assert.equal(view.venueShare('site'),'—')
})
