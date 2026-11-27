import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

/**
 * WHICH portals load third-party tags, and which deliberately do not.
 *
 * All three apps carry an identical lib/tracking.js — an artifact of splitting
 * the original single-app mockup, where the only call site lived in the
 * customer shell. So "the file is present" says nothing; what matters is
 * whether something CALLS it. These tests pin that decision per portal, so it
 * can only change on purpose.
 */

const read = (p) => readFileSync(new URL('../../' + p, import.meta.url), 'utf8')

test('the customer storefront loads tags, from the customer shell', () => {
  const layout = read('web/src/customer/CustomerLayout.vue')
  assert.match(layout, /useStorefrontTracking\(\)/)
})

test('the B2B site loads tags: a public, indexed funnel ending in /signup', () => {
  const app = read('business/src/App.vue')
  assert.match(app, /import \{ useStorefrontTracking \} from '\.\/lib\/tracking\.js'/)
  assert.match(app, /useStorefrontTracking\(\)/)

  // Without the IDs nothing injects, so they must be fetched on mount.
  assert.match(app, /loadPlatformTracking\(\)/)

  // Both symbols lib/tracking.js imports have to exist here, or the build breaks.
  const brand = read('business/src/lib/brand.js')
  for (const symbol of ['loadPlatformTracking', 'isBrandPreview', 'platformBrand', 'activeBrand', 'isWhiteLabel']) {
    assert.match(brand, new RegExp(`export (async )?(function|const) ${symbol}\\b`), `business brand.js must export ${symbol}`)
  }

  // It reads the platform's IDs, and must not overwrite the theme adminBrand.js owns.
  assert.match(brand, /marketplace\/platform-brand/)
  assert.match(brand, /tracking_ga4/)
})

test('the admin dashboard never loads tags', () => {
  // Authenticated internal tooling: page paths carry booking refs and customer
  // context, so no ad pixel belongs here, and no campaign traffic lands here.
  // lib/tracking.js exists in this app but nothing may call it.
  const app = read('admin/src/App.vue')
  assert.ok(!app.includes('useStorefrontTracking'), 'admin App.vue must not mount storefront tracking')

  const callers = ['admin/src/App.vue', 'admin/src/main.js']
  for (const file of callers) {
    for (const call of ['useStorefrontTracking(', 'injectTracking(', 'trackPageView(']) {
      assert.ok(!read(file).includes(call), `${file} must not call ${call}`)
    }
  }
})

test('every portal shares one tracking implementation', () => {
  // The three copies must stay identical: a fix to the injector has to reach
  // every site that uses it, and divergence here is invisible until a tag
  // silently stops firing on one portal.
  const web = read('web/src/lib/tracking.js')
  for (const app of ['business', 'admin']) {
    assert.equal(read(app + '/src/lib/tracking.js'), web, `${app}/src/lib/tracking.js has drifted from web's`)
  }
})
