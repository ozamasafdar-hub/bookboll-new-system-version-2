export function normalizeBusinessPhone(value) {
  const raw = String(value ?? '').trim()
  if (!/^(?:\+|00)[1-9][0-9\s().-]*$/.test(raw)) return raw
  return raw.replace(/[\s().-]/g, '').replace(/^00/, '+')
}
export function businessPhoneError(value, required = true) {
  const phone = normalizeBusinessPhone(value)
  if (!phone) return required ? 'Enter the business phone number, including its country calling code.' : ''
  return /^\+[1-9][0-9]{7,14}$/.test(phone) ? '' : 'Use an international number with country code, for example +974 5550 0001.'
}
export function businessContactLinks(value) {
  const phone = normalizeBusinessPhone(value)
  if (!phone || businessPhoneError(phone)) return null
  return { phone, call: `tel:${phone}`, whatsapp: `https://wa.me/${phone.slice(1)}` }
}
