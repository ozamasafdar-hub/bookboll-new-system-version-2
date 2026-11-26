import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const config = { ga4: 'G-ABC123', gtm: 'GTM-ABC123', metaPixel: '123456789', head: '' }

// Execute the real generated snippets in an isolated browser substitute. External
// scripts are recorded, never fetched: these tests send no analytics traffic.
function environment(app) {
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
    document, activeBrand, platformBrand, isWhiteLabel,
    isBrandPreview: () => preview, useRoute: () => route,
    watch: (source, callback) => watchers.push({ source, callback }),
    onMounted: cb => mounted.push(cb), onBeforeUnmount: cb => unmounted.push(cb),
  })
  context.window = context
  Object.defineProperty(context, 'localStorage', { get() { storageReads++; throw Error('Storage unavailable') } })
  const source = readFileSync(new URL(`../../${app}/src/lib/tracking.js`, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace(/export /g, '')
  vm.runInContext(source + '\nthis.api = {injectTracking, clearTracking, trackPageView, useStorefrontTracking, activeTrackingConfig}', context)
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
  test(`${app}: configured Google and Meta tags load without reading stored cookie choices`, () => {
    const env = environment(app)
    env.mount()
    assert.equal(env.storageReads, 0)
    assert.ok(env.urls().includes('https://www.googletagmanager.com/gtm.js?id=GTM-ABC123'))
    assert.ok(env.urls().includes('https://www.googletagmanager.com/gtag/js?id=G-ABC123'))
    assert.ok(env.urls().includes('https://connect.facebook.net/en_US/fbevents.js'))
    assert.ok(env.google().some(e => e[0] === 'config' && e[1] === config.ga4))
    assert.deepEqual(env.meta(), [['init', config.metaPixel], ['track', 'PageView']])
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
    assert.equal(env.meta().filter(e => e[0] === 'track').length, 3)
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
    assert.equal(env.meta().filter(e => e[0] === 'track').length, 1)
    assert.equal(env.nodes.filter(n => 'data-bb-trk' in n.attrs).length, 0)
    env.unmounted.forEach(cb => cb())
  })
}
