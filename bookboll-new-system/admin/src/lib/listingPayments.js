// Display/validation helpers only. Laravel must resolve and charge this policy.
export function hasPaidWebsiteDiscount(plan) {
  if (typeof plan?.own_site_commission_discount_eligible === 'boolean') return plan.own_site_commission_discount_eligible
  return plan?.is_premium === true && plan?.status === 'active' && !plan?.is_platform
}
export function websiteCommissionRate(commission, paidPlan = false, websiteFees = {}) {
  const rate = Math.max(0, Math.min(100, Number(commission) || 0))
  const configured = websiteFees[paidPlan ? 'site_premium' : 'site_free']
  return configured != null ? Number(configured) : rate * (paidPlan ? 0.5 : 1)
}
export function paymentExample(price, commission, paidPlan = false, websiteFees = {}) {
  const isReal = Number.isFinite(Number(price)) && Number(price) > 0
  const total = isReal ? Number(price) : 100
  const feePct = Math.max(0, Math.min(100, Number(commission) || 0))
  const sitePct = websiteCommissionRate(feePct, paidPlan, websiteFees)
  const fee = +(total * feePct / 100).toFixed(2)
  const siteDep = +(total * sitePct / 100).toFixed(2)
  return { total, isReal, feePct, fee, share: +(total - fee).toFixed(2), sitePct, siteDep, siteRest: +(total - siteDep).toFixed(2), premiumSitePct: websiteCommissionRate(feePct, true, websiteFees) }
}
