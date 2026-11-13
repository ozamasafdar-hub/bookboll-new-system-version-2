export const FINANCE_VERSION = 1
// New accounting endpoints must identify their contract. A legacy 200/HTML page
// or an empty success response must never be mistaken for financial data.
export function financeResponse(res) {
  if (!res.success || res.data?.contract_version !== FINANCE_VERSION) {
    const detail = Object.values(res.errors || {}).flat().find(v => typeof v === 'string')
    return { ok: false, message: detail || (res.status === 403 ? 'You do not have access to this financial action.' : res.message && !res.success && res.status !== 404 ? res.message : 'The finance backend is not connected yet. No financial records have been changed.') }
  }
  return { ok: true, data: res.data }
}

export function money(value, currency) {
  if (!['number','string'].includes(typeof value) || String(value).trim() === '' || !Number.isFinite(Number(value)) || !/^[A-Z]{3}$/.test(currency || '')) return '—'
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency, currencyDisplay: 'code' }).format(Number(value)) }
  catch { return '—' }
}
export function count(value) { return !['number','string'].includes(typeof value) || String(value).trim() === '' || !Number.isInteger(Number(value)) || Number(value)<0 ? '—' : Number(value).toLocaleString() }
export function isBookbollVenue(venue) {
  if (!venue) return false
  if (venue.operator_type) return venue.operator_type === 'bookboll' && !venue.business_id
  return !venue.owner_user_id && !venue.business_id
}
export function validDate(value) {
  return /^\d{4}-\d{2}-\d{2}$/.test(value || '') && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0,10) === value
}
export function dateRangeError(from, to) {
  if (!validDate(from) || !validDate(to)) return 'Choose a valid start and end date.'
  return from > to ? 'The end date must be on or after the start date.' : ''
}
export function agreementError(form) {
  if (!['fixed_rent','revenue_share','direct_operation'].includes(form.deal_type)) return 'Choose an operating model.'
  if (!validDate(form.effective_from)) return 'Choose when this agreement starts.'
  if (form.effective_to && dateRangeError(form.effective_from, form.effective_to)) return 'The agreement end date must follow its start date.'
  if (!/^[A-Z]{3}$/.test(form.currency || '')) return 'Select the agreement currency.'
  if (form.deal_type !== 'direct_operation' && !form.landlord_name?.trim()) return 'Enter the landlord or counterparty name.'
  if (form.deal_type === 'fixed_rent' && (form.monthly_rent === '' || !Number.isFinite(Number(form.monthly_rent)) || Number(form.monthly_rent) <= 0)) return 'Enter a monthly rent greater than zero.'
  if (form.deal_type === 'revenue_share' && (form.bookboll_share_percent === '' || !Number.isFinite(Number(form.bookboll_share_percent)) || Number(form.bookboll_share_percent) < 0 || Number(form.bookboll_share_percent) > 100)) return 'Bookboll’s share must be between 0% and 100%.'
  return ''
}
export function agreementPayload(form) {
  return { deal_type: form.deal_type, effective_from: form.effective_from, effective_to: form.effective_to || null, currency: form.currency, landlord_name: form.deal_type === 'direct_operation' ? null : form.landlord_name.trim(), monthly_rent: form.deal_type === 'fixed_rent' ? String(form.monthly_rent) : null, bookboll_share_percent: form.deal_type === 'revenue_share' ? String(form.bookboll_share_percent) : null, revenue_basis: 'on_arrival_after_platform_fees', rent_proration: 'calendar_days', notes: form.notes?.trim() || null }
}
export function nextAgreementDate(date) {
  if (!validDate(date)) return ''
  const day = new Date(`${date}T00:00:00Z`); day.setUTCDate(day.getUTCDate()+1)
  return day.toISOString().slice(0,10)
}
export function agreementRevisionError(form, previous) {
  const error = agreementError(form)
  if (error) return error
  if (previous?.id) {
    if (!['renewal','amendment'].includes(form.change_type)) return 'Choose renewal or changed terms.'
    if (form.effective_from <= previous.effective_from) return 'New terms must start after the previous agreement starts. Historical corrections need a reviewed adjustment.'
    if (!form.change_reason?.trim()) return 'Add a reason for the new agreement.'
  }
  return ''
}
export function previewReady(preview, now = Date.now()) {
  return !!preview?.preview_token && !!preview?.expires_at && Number.isFinite(Date.parse(preview.expires_at)) && Date.parse(preview.expires_at) > now && Array.isArray(preview.blockers) && preview.blockers.length === 0
}
export function transferPreviewValid(data, form) {
  return (!form.arrival_policy || data?.arrival_policy === form.arrival_policy) && !!data?.preview_token && Array.isArray(data.blockers) && Array.isArray(data.warnings) &&
    ['eligible_count','pending_arrival_count','excluded_count','landlord_obligation_count'].every(k => Number.isInteger(data[k]) && data[k] >= 0) &&
    ['business_id','effective_from','history_mode'].every(k => String(data[k]) === String(form[k])) &&
    Array.isArray(data.totals) && data.totals.every(row => /^[A-Z]{3}$/.test(row.currency) && ['deposit_to_fee','provider_cash','earnings_delta'].every(k => row[k] != null && row[k] !== '' && Number.isFinite(Number(row[k]))))
}
export const newRequestKey = () => globalThis.crypto.randomUUID()
