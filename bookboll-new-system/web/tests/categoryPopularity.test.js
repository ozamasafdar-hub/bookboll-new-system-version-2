import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { toRaw, reactive } from 'vue'

function setup(apiFetch) {
  const source = readFileSync(new URL('../src/customer/categoryPopularity.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace(/export /g, '')
  return new Function('toRaw', 'apiFetch', source + '\nreturn {attachCategoryPopularity, categoryClickCounts, recordCategoryClick}')(toRaw, apiFetch)
}
const rows = () => [{ category: 'sports', sport: 'Padel' }]
const payload = { category_popularity: [{ category: 'sports', type: 'Padel', click_count: 42 }], category_click_tracking_enabled: true }

test('different city and country popularity changes ordering without mixing scoped totals', async () => {
  const {catalogCategories} = await import('../src/lib/browseCatalog.js')
  const {attachCategoryPopularity:attach,categoryClickCounts:counts,recordCategoryClick:record} = setup(async (_path,{body}) => {
    assert.equal(body.country_id,179); assert.equal(body.city_id,1); return {success:true}
  })
  const listings = [{category:'sports',sport:'Football'},{category:'sports',sport:'Padel'}]
  const qa = attach([...listings],{city_id:1,country_id:179},{...payload,category_popularity:[{category:'sports',type:'Padel',click_count:42,city_id:1,country_id:179},{category:'sports',type:'Football',click_count:500,country_id:167}]})
  const pk = attach([...listings],{city_id:2,country_id:167},{category_popularity:[{category:'sports',type:'Football',click_count:20,country_id:167}]})
  assert.equal(catalogCategories(qa,counts(qa))[0].type,'Padel')
  assert.equal(catalogCategories(pk,counts(pk))[0].type,'Football')
  const wrong = attach([...listings],{city_id:1,country_id:179},{...payload,category_popularity_scope:{city_id:2,country_id:167}})
  assert.equal(Object.keys(counts(wrong)).length,0)
  assert.equal(await record(qa,{category:'sports',type:'Padel'},'home'),true)
})

test('server popularity stays attached to its catalog across Vue proxies and isolated across city/provider responses', () => {
  const { attachCategoryPopularity: attach, categoryClickCounts: counts } = setup(() => {})
  const a = attach(rows(), { city: 'Doha' }, payload)
  const b = attach(rows(), { city: 'Doha', brand_slug: 'another-provider' }, { category_popularity: [{ category: 'sports', type: 'Padel', click_count: 2 }] })
  assert.equal(counts(reactive(a))['sports:Padel'], 42)
  assert.equal(counts(b)['sports:Padel'], 2)
  assert.deepEqual(counts(rows()), {})
})

test('only enabled explicit selections are recorded, with scope and duplicate suppression across surfaces', async () => {
  const calls = []
  const { attachCategoryPopularity: attach, recordCategoryClick: record } = setup(async (...args) => { calls.push(args); return {success:true} })
  const a = attach(rows(), { city: 'Doha', country_iso: 'QA', brand_slug: 'club' }, payload)
  const c = { category: 'sports', type: 'Padel' }
  assert.equal(await record(a, null, 'home'), false)
  assert.equal(await record(a, {key:'all'}, 'home'), false)
  assert.equal(await record(a, c, 'catalog', {preview:true}), false)
  assert.equal(await record(a, {category:'sports',type:'Football'}, 'catalog'), false)
  assert.equal(await record(a, c, 'home'), true)
  assert.equal(await record(reactive(a), c, 'catalog'), false)
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/marketplace/category-clicks')
  assert.deepEqual(calls[0][1].body, { city:'Doha', country_iso:'QA', brand_slug:'club', category:'sports', type:'Padel', source:'home' })
})

test('old APIs and failed tracking never prevent category navigation or invent local popularity', async () => {
  let calls = 0
  const { attachCategoryPopularity: attach, categoryClickCounts: counts, recordCategoryClick: record } = setup(async () => { calls++; return {success:false,status:404} })
  const c = {category:'sports',type:'Padel'}
  assert.equal(await record(attach(rows(), {}, {}), c, 'catalog'), false)
  assert.equal(calls, 0)
  const a = attach(rows(), {}, payload)
  assert.equal(await record(a, c, 'home'), false)
  assert.equal(calls, 1)
  assert.equal(counts(a)['sports:Padel'], 42)
})


test('category popularity stays readable and clicks follow the server tracking switch', async () => {
  let calls = 0
  const { attachCategoryPopularity: attach, categoryClickCounts: counts, recordCategoryClick: record } = setup(async () => { calls++; return {success:true} })
  const catalog = attach(rows(), {}, {...payload, category_click_tracking_enabled:false})
  assert.equal(counts(catalog)['sports:Padel'], 42)
  assert.equal(await record(catalog, {category:'sports',type:'Padel'}, 'home'), false)
  assert.equal(calls, 0)
})

/* The storefront verifies the scope block against its own request before it
 * trusts any count, so these use the EXACT shape the live API returns. An
 * internal id in that block that the client never sent would read as a
 * different scope and silently throw every count away — the catalogue would
 * fall back to alphabetical ordering with nothing visibly broken. */
const serverScope = over => ({ city_id: null, country_iso: null, brand_slug: null, fallback: null, window_days: 30, ...over })

test('the live API scope block is accepted and reorders the catalogue', async () => {
  const { catalogCategories } = await import('../src/lib/browseCatalog.js')
  const { attachCategoryPopularity: attach, categoryClickCounts: counts } = setup(() => {})
  const listings = [{ category: 'sports', sport: 'Football' }, { category: 'sports', sport: 'Cricket' }]

  // A city with its own ranking: /marketplace/listings?city_id=12&city=Lahore&country_iso=PK
  const city = attach([...listings], { city_id: 12, city: 'Lahore', country_iso: 'PK' }, {
    category_popularity: [{ category: 'sports', type: 'Cricket', click_count: 7 }],
    category_popularity_scope: serverScope({ city_id: 12, country_iso: 'PK' }),
    category_click_tracking_enabled: true,
  })
  assert.equal(counts(city)['sports:Cricket'], 7)
  assert.equal(catalogCategories(city, counts(city))[0].type, 'Cricket')

  // A one-market country answers with city_id null — that is a real scope and
  // must not be mistaken for a mismatch.
  const country = attach([...listings], { city_id: 5, city: 'Doha', country_iso: 'QA' }, {
    category_popularity: [{ category: 'sports', type: 'Football', click_count: 3 }],
    category_popularity_scope: serverScope({ city_id: null, country_iso: 'QA' }),
    category_click_tracking_enabled: true,
  })
  assert.equal(counts(country)['sports:Football'], 3)

  // A city borrowing its country's ranking still counts; the fallback marker is
  // for display, not a reason to discard.
  const borrowed = attach([...listings], { city_id: 13, city: 'Karachi', country_iso: 'PK' }, {
    category_popularity: [{ category: 'sports', type: 'Cricket', click_count: 7 }],
    category_popularity_scope: serverScope({ city_id: 13, country_iso: 'PK', fallback: 'country' }),
    category_click_tracking_enabled: true,
  })
  assert.equal(counts(borrowed)['sports:Cricket'], 7)
})

test('a provider storefront accepts its own brand scope and refuses another brand or city', async () => {
  const { attachCategoryPopularity: attach, categoryClickCounts: counts } = setup(() => {})
  const rowsIn = [{ category: 'fitness', sport: '' }]
  const body = over => ({
    category_popularity: [{ category: 'fitness', type: '', click_count: 9 }],
    category_popularity_scope: serverScope(over),
    category_click_tracking_enabled: true,
  })

  const mine = attach([...rowsIn], { city_id: 12, country_iso: 'PK', brand_slug: 'north-club' }, body({ city_id: 12, country_iso: 'PK', brand_slug: 'north-club' }))
  assert.equal(counts(mine)['fitness:'], 9)

  const otherBrand = attach([...rowsIn], { city_id: 12, country_iso: 'PK', brand_slug: 'north-club' }, body({ city_id: 12, country_iso: 'PK', brand_slug: 'south-club' }))
  assert.equal(Object.keys(counts(otherBrand)).length, 0)

  const otherCity = attach([...rowsIn], { city_id: 12, country_iso: 'PK' }, body({ city_id: 99, country_iso: 'PK' }))
  assert.equal(Object.keys(counts(otherCity)).length, 0)

  const otherCountry = attach([...rowsIn], { city_id: 12, country_iso: 'PK' }, body({ city_id: 12, country_iso: 'QA' }))
  assert.equal(Object.keys(counts(otherCountry)).length, 0)
})
