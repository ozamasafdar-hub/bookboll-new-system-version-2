import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import { ref, computed, effectScope } from 'vue'
import { paymentExample, hasPaidWebsiteDiscount, websiteCommissionRate } from '../src/lib/listingPayments.js'
import { missingPricingSetup, pricingSetupGate } from '../src/lib/pricingSetup.js'
import { LISTING_STEPS } from '../src/lib/creationFlow.js'

test('website fee previews use resolved Free and Premium rates including zero independently of marketplace', () => {
  const rates = {site_free: 8, site_premium: 3}
  const free = paymentExample(200, 20, false, rates)
  const paid = paymentExample(200, 20, true, rates)
  assert.equal(free.siteDep, 16)
  assert.equal(paid.siteDep, 6)
  assert.equal(free.fee, 40)
  assert.equal(paid.siteRest, 194)
  assert.equal(free.premiumSitePct, 3)
  assert.equal(paymentExample(200, 20, true, {site_premium: 0}).siteDep, 0)
  assert.equal(websiteCommissionRate(24, true, {site_premium: null}), 12)
})

test('marketplace and website examples identify the correct fee and balance', () => {
  const example = paymentExample(450,20,false)
  assert.equal(example.fee,90)
  assert.equal(example.share,360)
  assert.equal(example.siteDep,90)
  assert.equal(example.siteRest,360)
  assert.equal(example.siteDep + example.siteRest,450)
  const paid = paymentExample(450,20,true)
  assert.equal(paid.siteDep,45)
  assert.equal(paid.siteRest,405)
  assert.equal(paid.fee,90)
  assert.equal(paymentExample(100,15,true).siteDep,7.5)
  assert.equal(paymentExample(125,20).fee,25)
  assert.equal(paymentExample(100,0,true).siteDep,0)
  assert.equal(paymentExample('',20).isReal,false)
})
test('website discount follows the merchant paid subscription, never platform exemptions', () => {
  assert.equal(hasPaidWebsiteDiscount({is_premium:true,status:'active'}),true)
  assert.equal(hasPaidWebsiteDiscount({is_premium:true,status:'active',cancel_at_period_end:true}),true)
  for (const status of ['trialing','past_due','canceled',null]) assert.equal(hasPaidWebsiteDiscount({is_premium:true,status}),false)
  assert.equal(hasPaidWebsiteDiscount({is_premium:true,status:'active',is_platform:true}),false)
  assert.equal(hasPaidWebsiteDiscount({is_premium:false,status:'active'}),false)
  assert.equal(hasPaidWebsiteDiscount(undefined),false)
  assert.equal(hasPaidWebsiteDiscount({is_premium:true,status:'active',own_site_commission_discount_eligible:false}),false)
  assert.equal(hasPaidWebsiteDiscount({own_site_commission_discount_eligible:true}),true)
  assert.equal(websiteCommissionRate(15,true),7.5)
  assert.equal(websiteCommissionRate(20,false),20)
})
// Exercise the listing form's actual validation and save boundary with an API
// spy. No real account, consent or payment settings are changed by these tests.
const script = parse(readFileSync(new URL('../src/pages/Listings.vue',import.meta.url),'utf8')).descriptor.scriptSetup.content
const ast = parseJs(script,{sourceType:'module'}).program.body
const payErrorNode = ast.flatMap(node => node.declarations || []).find(node => node.id?.name === 'payError')
const saveNode = ast.find(node => node.type === 'FunctionDeclaration' && node.id.name === 'save')
test('listing save payload retires own-site cash and uses the plan fee with or without payouts', () => {
  const body = saveNode.body.body.flatMap(node => node.declarations || []).find(node => node.id?.name === 'body')
  const payment = body.init.properties.find(prop => prop.key?.name === 'payment').value
  const payload = new Function('pay','payExample','onlineAllowed','depPct','return '+script.slice(payment.start,payment.end))
  for (const paid of [false,true]) for (const connected of [false,true]) {
    const result = payload(ref({deposit_enabled:true,site_deposit_enabled:true,site_deposit_percentage:99,site_cash_enabled:true,site_online_enabled:true}),ref(paymentExample(100,15,paid)),ref(connected),15)
    assert.equal(result.site_cash_enabled,false)
    assert.equal(result.site_deposit_percentage,paid ? 7.5 : 15)
    assert.equal(result.deposit_percentage,15)
    assert.equal(result.site_online_enabled,connected)
  }
})
function actualFunction(name, bindings = {}) {
  const node = ast.find(node => node.type === 'FunctionDeclaration' && node.id.name === name)
  return new Function(...Object.keys(bindings),script.slice(node.start,node.end)+'; return '+name)(...Object.values(bindings))
}
function actualComputed(name, bindings) {
  const node = ast.flatMap(node => node.declarations || []).find(node => node.id?.name === name)
  return new Function(...Object.keys(bindings),'return '+script.slice(node.init.start,node.init.end))(...Object.values(bindings))
}

test('listing step navigation asks before skipping pricing and remembers an explicit skip', () => {
  const bindings = {
    listingSaving:ref(false),listingStep:ref('pricing'),LISTING_STEPS,validateListingSteps:()=>true,
    dynamicPricing:ref(false),cells:ref([]),groups:ref([]),form:ref({base_price:100,extension_pricing:'none',extension_value:0,show_on_marketplace:true}),
    missingPricingSetup,pricingGate:pricingSetupGate(),pricingReminder:ref(null),openSection:ref('pricing'),payChannel:ref('marketplace'),locationIsBookbollOwned:ref(false),
  }
  const select=actualFunction('selectListingStep',bindings)
  select('payments')
  assert.equal(bindings.listingStep.value,'pricing')
  assert.deepEqual(bindings.pricingReminder.value,{features:['dynamic','extension'],step:'payments'})
  const skip=actualFunction('skipPricingSetup',{...bindings,closePricingReminder:()=>bindings.pricingReminder.value=null,selectListingStep:select})
  skip()
  assert.equal(bindings.listingStep.value,'payments')
  assert.equal(bindings.pricingReminder.value,null)
  select('pricing'); select('payments')
  assert.equal(bindings.pricingReminder.value,null)
  bindings.pricingGate.reset(); select('details'); select('review')
  assert.equal(bindings.listingStep.value,'pricing')
  assert.equal(bindings.pricingReminder.value.step,'review')
})

test('pricing setup focuses the visible extension field, not its hidden availability duplicate', async () => {
  let focused='',dirty=false,selected=''
  const field=name=>({getClientRects:()=>name==='visible'?[{}]:[],scrollIntoView(){},focus(){focused=name}})
  const bindings={closePricingReminder(){},selectListingStep:step=>selected=step,dynamicPricing:ref(false),toggleExtension(){},markDirty:()=>dirty=true,nextTick:async()=>{},listingBodyEl:ref({querySelectorAll:()=>[field('hidden'),field('visible')]})}
  await actualFunction('setupSuggestedPricing',bindings)('extension')
  assert.equal(selected,'pricing'); assert.equal(focused,'visible'); assert.equal(dirty,true)
})
function formValidation(t, isNew = false) {
  const scope = effectScope()
  t.after(() => scope.stop())
  const defaultPay = actualFunction('defaultPay',{userRole:ref('provider')})
  const bindings = {
    computed, locationIsBookbollOwned:ref(false), form:ref({show_on_marketplace:true,show_on_provider_site:true}),
    payoutStatus:ref('loaded'), userRole:ref('provider'), pay:ref(isNew ? defaultPay({forNewListing:true}) : {...defaultPay(),site_deposit_enabled:true}),
    onlineAllowed:ref(false),
  }
  bindings.siteNoMethod = actualComputed('siteNoMethod',bindings)
  const error = new Function(...Object.keys(bindings),'return '+script.slice(payErrorNode.init.start,payErrorNode.init.end))(...Object.values(bindings))
  let writes = 0
  const saveBindings = { editingExisting:ref(!isNew), listingDraftKey:()=> isNew ? 'listing_new' : 'listing_59', validateListingSteps:()=> !error.value, createListing:()=>{writes++}, updateListing:()=>{writes++} }
  const save = new Function(...Object.keys(saveBindings),script.slice(saveNode.start,saveNode.end)+'; return save')(...Object.values(saveBindings))
  const toggles = {...bindings,markDirty:()=>{},payoutModalSource:ref('marketplace'),payoutModal:ref(false),toast:{error:()=>{}}}
  for (const name of ['onProviderOnlineToggle','onSiteOnlineToggle','onAdminOnlineToggle']) toggles[name] = actualFunction(name,toggles)
  const change = actualFunction('updatePaymentMethod',toggles)
  return {bindings,error,save,change,writes:()=>writes}
}
test('payout lookup failures and disconnected full-online settings block saving', async t => {
  const { bindings,error,save,writes } = formValidation(t)
  bindings.payoutStatus.value = 'error'
  assert.match(error.value,/could not check/)
  await save()
  bindings.payoutStatus.value = 'loading'
  assert.match(error.value,/Wait/)
  await save()
  bindings.payoutStatus.value = 'loaded'
  bindings.pay.value.site_online_enabled = true
  assert.match(error.value,/Connect payouts/)
  await save()
  assert.equal(writes(),0)
})

test('new listing deposits work without acknowledgement or a payout connection', async t => {
  const {bindings,error,save,change,writes} = formValidation(t,true)
  assert.equal(bindings.pay.value.deposit_enabled,true)
  assert.equal(bindings.pay.value.site_deposit_enabled,true)
  assert.equal(error.value,'')
  change('site_deposit_enabled',false)
  assert.match(error.value,/Enable at least one payment method for your own website/)
  await save()
  assert.equal(writes(),0)
  change('site_cash_enabled',true)
  assert.equal(bindings.pay.value.site_cash_enabled,false)
  bindings.onlineAllowed.value = true
  change('site_online_enabled',true)
  assert.equal(error.value,'')
  change('site_deposit_enabled',true)
  assert.equal(error.value,'')
})

test('legacy own-site cash flags cannot satisfy form validation', async t => {
  const {bindings,error,save,writes} = formValidation(t)
  bindings.pay.value.site_deposit_enabled = false
  bindings.pay.value.site_cash_enabled = true
  assert.match(error.value,/Enable at least one payment method for your own website/)
  await save()
  assert.equal(writes(),0)
})

test('marketplace switches stay off until the owner chooses a method', async t => {
  const {bindings,error,save,gate,change,writes} = formValidation(t,true)
  change('deposit_enabled',false)
  assert.equal(bindings.pay.value.deposit_enabled,false)
  assert.match(error.value,/Keep at least one Bookboll marketplace/)
  await save()
  assert.equal(writes(),0)
  bindings.onlineAllowed.value = true
  change('online_payment_enabled',true)
  assert.equal(error.value,'')
  change('online_payment_enabled',false)
  assert.equal(bindings.pay.value.deposit_enabled,false)
  assert.match(error.value,/Keep at least one Bookboll marketplace/)
})

test('opening a new listing checks its sole or preset venue and leaves multiple venues for selection', async () => {
  for (const [venues,preset,expected] of [[[21],null,21],[[21,22],22,22],[[21,22],null,'']]) {
    const checks = []
    const bindings = {
      hasUsableLocation:ref(true),needLocationModal:ref(false),
      pricingGate:{reset:()=>{}},closePricingReminder:()=>{},
      listingFormLocations:ref(venues.map(id=>({id}))),listingStep:ref(''),editing:ref({id:59}),editingExisting:ref(true),
      contentUnderReview:ref(false),form:ref({}),prevLocationId:ref(''),hours:ref([]),groups:ref([]),cells:ref([]),
      pattern:ref(''),dynamicPricing:ref(false),pay:ref({}),connectedAddons:ref([]),connectedResources:ref([]),
      locationHours:ref([]),openSection:ref(''),payChannel:ref(''),listingTriedSave:ref(false),listingSaveError:ref(''),view:ref('list'),
      defaultForm:actualFunction('defaultForm'),defaultPay:actualFunction('defaultPay',{userRole:ref('provider')}),
      defaultHours:()=>[],hoursFromLocation:()=>[],syncFixedFromTimes:()=>{},syncSlotModeFromForm:()=>{},loadLocationHours:async()=>{},
      checkProviderOnline:async id=>checks.push(id),clearDirty:()=>{},offerListingDraft:async()=>{},registerDraftSaver:()=>{},
    }
    await actualFunction('openNewListingForm',bindings)(preset)
    assert.equal(bindings.form.value.location_id,expected)
    assert.deepEqual(checks,[expected])
    assert.equal(bindings.pay.value.site_deposit_enabled,true)
    assert.equal(bindings.view.value,'editor')
  }
  // The creation default must not silently opt existing listings into own-site deposits.
  assert.equal(actualFunction('defaultPay',{userRole:ref('provider')})().site_deposit_enabled,false)
})

test('changing venue starts its payout check immediately, even while hours are loading', async () => {
  let resolveHours
  const checked = []
  const bindings = {
    form:ref({location_id:22}),editingExisting:ref(false),hours:ref([]),hoursFromLocation:()=>[],cid:String,
    loadLocationHours:()=>new Promise(resolve=>{resolveHours=resolve}),checkProviderOnline:async id=>checked.push(id),
  }
  const changing = actualFunction('onLocationChange',bindings)()
  assert.deepEqual(checked,[22])
  resolveHours()
  await changing
})
