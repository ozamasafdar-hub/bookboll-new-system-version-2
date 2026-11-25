// Display/validation helpers only. Laravel must resolve and charge this policy.
export function websiteCommissionRate(commission, websiteFees = {}) {
  const configured = websiteFees.site
  return configured != null ? Number(configured) : 10
}
export function paymentExample(price, commission, websiteFees = {}) {
  const isReal = Number.isFinite(Number(price)) && Number(price) > 0
  const total = isReal ? Number(price) : 100
  const feePct = Math.max(0, Math.min(100, Number(commission) || 0))
  const sitePct = websiteCommissionRate(feePct, websiteFees)
  const fee = +(total * feePct / 100).toFixed(2)
  const siteDep = +(total * sitePct / 100).toFixed(2)
  return { total, isReal, feePct, fee, share: +(total - fee).toFixed(2), sitePct, siteDep, siteRest: +(total - siteDep).toFixed(2) }
}
