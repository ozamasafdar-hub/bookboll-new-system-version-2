import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const config = { ga4: 'G-ABC123', gtm: 'GTM-ABC123', metaPixel: '123456789', head: '' }

const receipt = (event = 'Purchase', extra = {}) => ({
  event_id: `bb_${event === 'Purchase' ? 'purchase' : 'checkout'}_${'a'.repeat(64)}`,
  value: 15, currency: 'QAR', surface: 'marketplace', business_id: 7,
  content_ids: ['9'], num_items: 2, ...extra,
})
const memoryStorage = () => {
  const values = new Map()
  return { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) }
}

test('page views target only the current pixel after a provider switch, without repeated init', () => {
  const env = environment('web'); env.mount()
  env.activeBrand.value = {businessId:7,tracking:{...config,metaPixel:'7777777',ga4:'G-OWNER7'}}; env.reapply(); env.navigate('/listings/9')
  env.activeBrand.value = null; env.reapply(); env.navigate('/listings/10')
  assert.deepEqual(env.meta().filter(e=>e[2]==='PageView').map(e=>e[1]), ['123456789','7777777','7777777','123456789','123456789'])
  assert.equal(env.meta().filter(e=>e[0]==='init'&&e[1]==='123456789').length,1)
  assert.deepEqual(env.google().filter(e=>e[1]==='page_view').map(e=>e[2].send_to),['G-OWNER7','G-ABC123'])
})

test('listing interactions match catalog IDs and reject invalid values, previews and payment guesses', () => {
  const env = environment('web'); env.mount()
  const item = {kind:'listing',id:9,value:100,currency:'QAR',quantity:2,email:'private@example.com',phone:'1234'}
  assert.equal(env.api.trackStorefrontEvent('ViewContent', item),true)
  assert.equal(env.api.trackStorefrontEvent('AddToCart', item),true)
  assert.equal(env.api.trackStorefrontEvent('Purchase', item),false)
  assert.equal(env.api.trackStorefrontEvent('AddPaymentInfo', item),false)
  assert.equal(env.api.trackStorefrontEvent('AddToCart', {...item, value:NaN}),false)
  const p=env.meta().find(e=>e[2]==='AddToCart')[3]
  assert.deepEqual(JSON.parse(JSON.stringify(p.contents)),[{id:'9',quantity:2}])
  assert.equal(p.email,undefined); assert.equal(p.phone,undefined)
  env.setPreview(true); assert.equal(env.api.trackStorefrontEvent('Contact',{method:'phone'}),false)
})

test('membership enquiries carry no catalog IDs or payment values and auth pages stay untracked', () => {
  const env = environment('web')
  assert.equal(env.api.trackStorefrontEvent('CompleteRegistration'),false)
  env.mount()
  env.api.trackStorefrontEvent('Contact',{kind:'membership',id:9,value:500,currency:'PKR',method:'phone'})
  const p=env.meta().find(e=>e[2]==='Contact')[3]
  assert.equal(p.content_ids,undefined); assert.equal(p.value,undefined); assert.equal(p.contact_method,'phone')
  env.unmounted.forEach(cb=>cb())
  assert.equal(env.api.trackStorefrontEvent('CompleteRegistration'),false)
})

test('confirmed cash/free bookings emit one Schedule without pretending revenue was received', async () => {
  const env = environment('web',memoryStorage()); env.mount()
  const event={...receipt(),event_id:`bb_schedule_${'a'.repeat(64)}`,value:undefined,currency:undefined,contents:[{id:'9',quantity:2}]}
  assert.equal(await env.api.trackBookingConversion('Schedule',event),true)
  assert.equal(await env.api.trackBookingConversion('Schedule',event),false)
  const p=env.meta().find(e=>e[2]==='Schedule')[3]
  assert.equal(p.value,undefined); assert.equal(p.currency,undefined)
})

test('an unresolved provider URL cannot fall back to the marketplace pixel', () => {
  const env=environment('web'); env.context.detectBrandSlug=()=> 'unknown'
  env.mount(); env.navigate('/listings/9')
  assert.deepEqual(env.meta(),[])
})

test('deposit checkout and purchase send only QAR 15 once for two slots; refresh cannot repeat', async () => {
  const storage = memoryStorage()
  const env = environment('web', storage)
  env.mount()
  assert.equal(await env.api.trackBookingConversion('InitiateCheckout', receipt('InitiateCheckout')), true)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), true)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), false)
  const calls = env.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView')
  assert.deepEqual(calls.map(e => [e[1], e[2], e[3].value, e[3].currency, e[3].num_items]), [
    ['123456789', 'InitiateCheckout', 15, 'QAR', 2], ['123456789', 'Purchase', 15, 'QAR', 2],
  ])
  assert.equal(calls[1][4].eventID, receipt().event_id)
  const google = env.google().filter(e => e[1] === 'purchase')
  assert.equal(google.length, 1)
  assert.equal(google[0][2].value, 15)
  assert.equal(google[0][2].transaction_id, receipt().event_id)
  const refreshed = environment('web', storage)
  refreshed.mount()
  assert.equal(await refreshed.api.trackBookingConversion('Purchase', receipt()), false)
  assert.equal(refreshed.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView').length, 0)
})

test('full payment uses the verified amount and currency, not the selected country', async () => {
  const env = environment('web')
  env.mount()
  await env.api.trackBookingConversion('Purchase', receipt('Purchase', { value: 1234.56, currency: 'PKR' }))
  const event = env.meta().find(e => e[0] === 'trackSingle' && e[2] !== 'PageView')
  assert.equal(event[3].value, 1234.56)
  assert.equal(event[3].currency, 'PKR')
  // Even when storage is unavailable, rerendering cannot send a second event.
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), false)
})

test('conversions target only the matching provider pixel and never the marketplace', async () => {
  const env = environment('web')
  env.mount()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase', { surface: 'site' })), false)
  env.activeBrand.value = { businessId: 7, tracking: { ...config, metaPixel: '987654321' } }
  env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), false)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase', { surface: 'site', business_id: 8 })), false)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase', { surface: 'site' })), true)
  const events = env.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView')
  assert.equal(events.length, 1)
  assert.equal(events[0][1], '987654321')
})

test('preview, invalid payloads and absent configuration cannot generate purchases', async () => {
  const env = environment('web')
  env.mount(); env.setPreview(true)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), false)
  env.setPreview(false)
  for (const change of [{value:0}, {value:-1}, {value:NaN}, {value:'15'}, {currency:''}, {currency:'qar'}, {event_id:'url-only'}, {num_items:0}, {content_ids:[]}]) {
    assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase', change)), false)
  }
  assert.equal(await env.api.trackBookingConversion('Purchase', null), false)
  env.platformBrand.value = { tracking: {} }; env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), false)
  // Config arriving later should still deliver; no false dedup marker was stored.
  env.platformBrand.value = { tracking: config }; env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt()), true)
})

test('two tabs serialize delivery for the same verified payment', async () => {
  const storage = memoryStorage()
  let pending = Promise.resolve()
  const locks = { request: (_, cb) => { const result = pending.then(cb); pending = result; return result } }
  const a = environment('web', storage), b = environment('web', storage)
  a.context.navigator = b.context.navigator = { locks }
  a.mount(); b.mount()
  const sent = await Promise.all([a.api.trackBookingConversion('Purchase', receipt()), b.api.trackBookingConversion('Purchase', receipt())])
  assert.deepEqual(sent, [true, false])
})

test('different listings and a repeat booking each emit once with their own value, currency and item id', async () => {
  const storage = memoryStorage()
  const env = environment('web', storage)
  env.mount()
  const purchases = [
    receipt('Purchase', {event_id:`bb_purchase_${'a'.repeat(64)}`,content_ids:['8'],value:22,num_items:2}),
    receipt('Purchase', {event_id:`bb_purchase_${'b'.repeat(64)}`,content_ids:['16'],value:125,num_items:1}),
    receipt('Purchase', {event_id:`bb_purchase_${'c'.repeat(64)}`,content_ids:['53'],value:344.85,currency:'PKR',num_items:1}),
    receipt('Purchase', {event_id:`bb_purchase_${'d'.repeat(64)}`,content_ids:['8'],value:22,num_items:2}),
  ]
  for (const purchase of purchases) assert.equal(await env.api.trackBookingConversion('Purchase', purchase), true)
  const events = env.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView')
  assert.deepEqual(events.map(e => [e[3].content_ids[0], e[3].value, e[3].currency, e[3].num_items]),
    purchases.map(p => [p.content_ids[0],p.value,p.currency,p.num_items]))
  const google = env.google().filter(e => e[1] === 'purchase')
  assert.deepEqual(google.map(e => [e[2].items[0].item_id,e[2].value,e[2].currency]),
    purchases.map(p => [p.content_ids[0],p.value,p.currency]))
  const refreshed = environment('web', storage)
  refreshed.mount()
  for (const purchase of [...purchases].reverse()) assert.equal(await refreshed.api.trackBookingConversion('Purchase', purchase), false)
  assert.equal(refreshed.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView').length, 0)
})

test('switching between businesses cannot attribute a different listing to the wrong business pixel', async () => {
  const env = environment('web', memoryStorage())
  env.mount()
  const first = receipt('Purchase', {surface:'site',business_id:7,content_ids:['8']})
  const second = receipt('Purchase', {surface:'site',business_id:8,content_ids:['16'],event_id:`bb_purchase_${'b'.repeat(64)}`,value:125})
  env.activeBrand.value = { businessId:7, tracking:{...config,metaPixel:'7777777',ga4:'G-FIRST123'} }
  env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', second), false)
  assert.equal(await env.api.trackBookingConversion('Purchase', first), true)
  env.activeBrand.value = { businessId:8, tracking:{...config,metaPixel:'8888888',ga4:'G-SECOND123'} }
  env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', first), false)
  assert.equal(await env.api.trackBookingConversion('Purchase', second), true)
  const events = env.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView')
  assert.deepEqual(events.map(e => [e[1],e[3].content_ids[0]]), [['7777777','8'],['8888888','16']])
  assert.deepEqual(env.google().filter(e => e[1] === 'purchase').map(e => e[2].send_to), ['G-FIRST123','G-SECOND123'])
})

test('a listing without tracking does not consume the next listing payment or reuse its previous configuration', async () => {
  const env = environment('web', memoryStorage())
  env.activeBrand.value = { businessId:7, tracking:{} }
  env.mount()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase',{surface:'site'})), false)
  env.activeBrand.value = { businessId:8, tracking:{...config,metaPixel:'8888888'} }
  env.reapply()
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase',{surface:'site'})), false)
  assert.equal(await env.api.trackBookingConversion('Purchase', receipt('Purchase',{surface:'site',business_id:8,content_ids:['16'],event_id:`bb_purchase_${'b'.repeat(64)}`})), true)
  assert.equal(env.meta().filter(e => e[0] === 'trackSingle' && e[2] !== 'PageView').length, 1)
})

// Execute the real generated snippets in an isolated browser substitute. External
// scripts are recorded, never fetched: these tests send no analytics traffic.
function environment(app, storage = null) {
  const nodes = [], watchers = [], mounted = [], unmounted = []
  let preview = false, storageReads = 0, context
  const makeNode = tag => ({
    tagName: tag.toUpperCase(), attrs: {}, textContent: '', parentNode: null,
    setAttribute(k, v) { this.attrs[k] = v },
    remove() { const i = nodes.indexOf(this); if (i >= 0) nodes.splice(i, 1) },
  })
  const head = {
    appendChild(node) {
      nodes.push(node); node.parentNode = head
      if (node.tagName === 'SCRIPT' && node.textContent) vm.runInContext(node.textContent, context)
      return node
    },
    insertBefore(node) { return head.appendChild(node) },
  }
  const document = {
    head, body: { insertBefore: node => head.appendChild(node) },
    createElement: makeNode,
    getElementsByTagName: tag => nodes.filter(n => n.tagName === tag.toUpperCase()),
    querySelectorAll: () => nodes.filter(n => 'data-bb-trk' in n.attrs),
  }
  // The existing application bundle is the anchor used by GTM and Meta loaders.
  const bundle = makeNode('script'); bundle.parentNode = head; nodes.push(bundle)
  const activeBrand = { value: null }, platformBrand = { value: { tracking: config } }
  const isWhiteLabel = { get value() { return !!activeBrand.value } }
  const route = { path: '/' }
  context = vm.createContext({
    document, activeBrand, platformBrand, isWhiteLabel, detectBrandSlug: () => '', runtimeTrackingConfig: cfg => cfg,
    isBrandPreview: () => preview, useRoute: () => route,
    watch: (source, callback) => watchers.push({ source, callback }),
    onMounted: cb => mounted.push(cb), onBeforeUnmount: cb => unmounted.push(cb),
  })
  context.window = context
  Object.defineProperty(context, 'localStorage', { get() { storageReads++; if (storage) return storage; throw Error('Storage unavailable') } })
  const source = readFileSync(new URL(`../../${app}/src/lib/tracking.js`, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace(/export /g, '')
  vm.runInContext(source + '\nthis.api = {injectTracking, clearTracking, trackPageView, useStorefrontTracking, activeTrackingConfig}', context)
  if (app === 'web') {
    const conversions = readFileSync(new URL('../src/lib/bookingConversions.js', import.meta.url), 'utf8')
      .replace(/^import .*$/gm, '').replace(/export /g, '')
    vm.runInContext(conversions + '\nthis.api.trackBookingConversion = trackBookingConversion', context)
    const funnel = readFileSync(new URL('../src/lib/storefrontEvents.js', import.meta.url), 'utf8').replace(/^import .*$/gm, '').replace(/export /g, '')
    vm.runInContext(funnel + '\nthis.api.trackStorefrontEvent = trackStorefrontEvent', context)
  }
  return {
    api: context.api, context, nodes, activeBrand, platformBrand, watchers, unmounted,
    get storageReads() { return storageReads },
    setPreview: value => { preview = value },
    mount: () => { context.api.useStorefrontTracking(); mounted.forEach(cb => cb()) },
    reapply: () => watchers.filter(w => Array.isArray(w.source)).forEach(w => w.callback()),
    navigate: path => { route.path = path; watchers.filter(w => typeof w.source === 'function').forEach(w => w.callback(path)) },
    google: () => Array.from(context.dataLayer || [], args => Array.from(args)),
    meta: () => Array.from(context.fbq?.queue || [], args => Array.from(args)),
    urls: () => nodes.map(n => n.attrs.src || n.src || '').filter(Boolean),
  }
}

for (const app of ['web', 'admin', 'business']) {
  test(`${app}: router startup does not repeat the initial deep-link page view`, () => {
    const env = environment(app)
    env.context.location = { pathname: '/thank-you' }
    env.mount()
    env.navigate('/thank-you')
    assert.equal(env.meta().filter(e => e[0] === 'trackSingle' && e[2] === 'PageView').length, 1)
    assert.equal(env.google().filter(e => e[0] === 'event').length, 0)
    env.navigate('/venues'); env.navigate('/thank-you')
    assert.equal(env.meta().filter(e => e[0] === 'trackSingle' && e[2] === 'PageView').length, 3)
    assert.equal(env.google().filter(e => e[0] === 'event').length, 2)
  })

  test(`${app}: configured Google and Meta tags load without reading stored cookie choices`, () => {
    const env = environment(app)
    env.mount()
    assert.equal(env.storageReads, 0)
    assert.ok(env.urls().includes('https://www.googletagmanager.com/gtm.js?id=GTM-ABC123'))
    assert.ok(env.urls().includes('https://www.googletagmanager.com/gtag/js?id=G-ABC123'))
    assert.ok(env.urls().includes('https://connect.facebook.net/en_US/fbevents.js'))
    assert.ok(env.google().some(e => e[0] === 'config' && e[1] === config.ga4))
    assert.deepEqual(env.meta(), [['set', 'autoConfig', false, config.metaPixel], ['init', config.metaPixel], ['trackSingle', config.metaPixel, 'PageView']])
    assert.equal(env.context.fbq.disablePushState, true, 'SDK history PageViews must be off when Vue sends explicit scoped views')
    assert.equal(env.google().filter(e => e[0] === 'consent').length, 0)
    const count = env.nodes.length
    env.reapply()
    assert.equal(env.nodes.length, count)
    assert.equal(env.meta().filter(e => e[0] === 'init').length, 1)
  })

  test(`${app}: the first and subsequent SPA navigations each send a page view`, () => {
    const env = environment(app)
    env.mount()
    env.navigate('/venues'); env.navigate('/memberships')
    assert.deepEqual(env.google().filter(e => e[0] === 'event').map(e => e[2].page_path), ['/venues', '/memberships'])
    assert.equal(env.meta().filter(e => e[0] === 'trackSingle' && e[2] === 'PageView').length, 3)
  })

  test(`${app}: asynchronous settings load starts tracking once`, () => {
    const env = environment(app)
    env.platformBrand.value = null
    env.mount()
    assert.deepEqual(env.urls(), [])
    env.platformBrand.value = { tracking: config }
    env.reapply(); env.reapply()
    assert.equal(env.google().filter(e => e[0] === 'config').length, 1)
    assert.equal(env.meta().filter(e => e[0] === 'init').length, 1)
  })

  test(`${app}: provider storefront uses only its own settings without platform fallback`, () => {
    const env = environment(app)
    env.activeBrand.value = { tracking: { ga4: 'G-PROVIDER', metaPixel: '987654321' } }
    env.mount()
    assert.deepEqual(env.google().filter(e => e[0] === 'config').map(e => e[1]), ['G-PROVIDER'])
    assert.deepEqual(env.meta().filter(e => e[0] === 'init').map(e => e[1]), ['987654321'])
    assert.deepEqual(env.meta().slice(0, 2), [['set', 'autoConfig', false, '987654321'], ['init', '987654321']])
    assert.equal(env.urls().some(url => url.includes('gtm.js')), false)
    const emptyProvider = environment(app)
    emptyProvider.activeBrand.value = { tracking: {} }
    emptyProvider.mount()
    assert.deepEqual(emptyProvider.urls(), [])
  })

  test(`${app}: preview, missing IDs and malformed IDs do not inject trackers`, () => {
    const preview = environment(app)
    preview.setPreview(true); preview.mount(); preview.navigate('/venues'); preview.reapply()
    assert.deepEqual(preview.urls(), [])
    const invalid = environment(app)
    invalid.platformBrand.value = { tracking: { ga4: "G-x';bad()", gtm: 'invalid', metaPixel: 'not-a-pixel' } }
    invalid.mount(); invalid.navigate('/venues')
    assert.deepEqual(invalid.urls(), [])
    assert.deepEqual(invalid.google(), [])
    assert.deepEqual(invalid.meta(), [])
  })

  test(`${app}: clearing configured IDs stops further page views`, () => {
    const env = environment(app)
    env.mount()
    env.platformBrand.value = { tracking: {} }; env.reapply()
    env.navigate('/venues')
    assert.equal(env.google().filter(e => e[0] === 'event').length, 0)
    assert.equal(env.meta().filter(e => e[0] === 'trackSingle' && e[2] === 'PageView').length, 1)
    assert.equal(env.nodes.filter(n => 'data-bb-trk' in n.attrs).length, 0)
    env.unmounted.forEach(cb => cb())
  })
}
