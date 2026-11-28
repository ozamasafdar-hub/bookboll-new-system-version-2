import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { trackingForEnvironment, isTrackingTestHost } from '../src/lib/trackingEnvironment.js'

const live = { metaPixel:'1556219351378271', ga4:'G-LIVE123', gtm:'GTM-LIVE123', head:'<script>live()</script>' }
test('Railway and local environments suppress copied production tags by default', () => {
  for (const host of ['localhost','academy.localhost','127.0.0.1','192.168.1.10','[::1]','bookboll-marketplace-preview.up.railway.app']) {
    assert.deepEqual(trackingForEnvironment(live,{host}),{metaPixel:'',ga4:'',gtm:'',head:''})
  }
  assert.equal(isTrackingTestHost('staging.example.com','', 'staging.example.com'), true)
})
test('only a separately configured test pixel can receive staging events', () => {
  assert.deepEqual(trackingForEnvironment(live,{host:'localhost',testPixel:'1174577295029069'}), {metaPixel:'1174577295029069',ga4:'',gtm:'',head:''})
  assert.equal(trackingForEnvironment(live,{host:'localhost',testPixel:live.metaPixel}).metaPixel,'')
  assert.equal(trackingForEnvironment(live,{host:'localhost',mode:'off',testPixel:'1174577295029069'}).metaPixel,'')
})
test('marketplace, subdomains and custom production domains retain their own configuration', () => {
  for (const host of ['bookboll.com','academy.bookboll.com','academy.example.com']) assert.equal(trackingForEnvironment(live,{host}),live)
  assert.equal(trackingForEnvironment(live,{host:'production.up.railway.app',mode:'production'}),live)
})

test('local IP addresses use marketplace tracking while explicit provider routes retain their scope', () => {
  for (const path of ['../src/lib/brand.js', '../../admin/src/lib/brand.js']) {
   const source = readFileSync(new URL(path, import.meta.url), 'utf8')
   const local = source.match(/function isLocalHost\(host\) \{[\s\S]*?\n\}/)[0]
   const detect = source.match(/export function detectBrandSlug\(\) \{[\s\S]*?\n\}/)[0].replace('export ', '')
   const context = vm.createContext({ URLSearchParams, window:{ location:{} }, RESERVED_SUBS:new Set(['www','admin','app','api','business','staging','dev']), isDevTunnel:()=>false, isPreviewHost:()=>false })
   vm.runInContext(`${local}\nfunction isPreviewEnvironment(host) { return isLocalHost(host) }\n${detect}`, context)
   for (const [hostname,search,expected] of [
     ['127.0.0.1','',''], ['192.168.1.10','',''], ['[::1]','',''],
     ['127.0.0.1','?b=academy','academy'], ['academy.localhost','','academy'],
     ['bookboll.com','?b=academy',''], ['academy.bookboll.com','','academy'],
   ]) {
     context.window.location = {hostname,search}
     assert.equal(vm.runInContext('detectBrandSlug()',context),expected,`${path}: ${hostname}${search}`)
   }
  }
})
