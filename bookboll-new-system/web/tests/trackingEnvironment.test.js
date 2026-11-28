import test from 'node:test'
import assert from 'node:assert/strict'
import { trackingForEnvironment, isTrackingTestHost } from '../src/lib/trackingEnvironment.js'

const live = { metaPixel:'1556219351378271', ga4:'G-LIVE123', gtm:'GTM-LIVE123', head:'<script>live()</script>' }
test('Railway and local environments suppress copied production tags by default', () => {
  for (const host of ['localhost','127.0.0.1','bookboll-marketplace-preview.up.railway.app']) {
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
