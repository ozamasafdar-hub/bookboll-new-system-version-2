// Stripe-hosted invoice/receipt for an online payment. The link comes from the
// server row (`stripe_receipt_url`, falling back to `stripe_invoice_url`); the
// frontend never builds one from an ID. Only https links on stripe.com open,
// so a tampered row cannot send staff to another site.
export function isStripeOnlinePayment(p) {
  return !!p && p.method === 'online' && ['paid','refunded'].includes(p.status)
}
export function safeStripeUrl(url) {
  if (typeof url !== 'string') return ''
  try {
    const u = new URL(url)
    if (u.protocol === 'https:' && !u.username && !u.password && (u.hostname === 'stripe.com' || u.hostname.endsWith('.stripe.com'))) return u.href
  } catch { /* invalid URL */ }
  return ''
}
export function stripeInvoiceUrl(p) {
  if (!isStripeOnlinePayment(p)) return ''
  for (const url of p.status === 'refunded' ? [p.stripe_refund_receipt_url, p.stripe_receipt_url] : [p.stripe_receipt_url, p.stripe_invoice_url]) {
    const safe = safeStripeUrl(url)
    if (safe) return safe
  }
  return ''
}
export function stripeInvoiceTitle(p) {
  if (!isStripeOnlinePayment(p)) return ''
  return stripeInvoiceUrl(p) ? 'Open Stripe invoice to print or download' : 'Stripe invoice link not available for this payment yet'
}
