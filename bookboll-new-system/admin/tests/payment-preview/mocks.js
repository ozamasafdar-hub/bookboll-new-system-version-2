import { ref } from 'vue'
export const perspective = ref('platform')
export const currentUser = ref({role:'super_admin'})
export const useCurrentUser = () => ({currentUser,can:()=>true})
export const usePremium = () => ({requirePremium:()=>true})
export const adminBrandVars = {}
export async function fetchPaymentsDashboard(query) {
  const response = await (await fetch('/fixture-api?'+new URLSearchParams({...query,perspective:perspective.value}))).json()
  for (const row of response.data?.payments?.data || []) {
    row.can_request_refund = perspective.value === 'platform' && row.method === 'online' && row.status === 'paid'
    if (row.method === 'online') row.stripe_receipt_url = 'https://pay.stripe.com/receipts/qa-fixture-not-a-real-receipt'
  }
  return response
}
export async function fetchPaymentDetails(id, rowId) {
  const result = await fetchPaymentsDashboard({records_per_page:250})
  const rows = result.data.payments.data
  const payment = rows.find(row=>String(row.id) === String(rowId))
  return {success:true,data:{payment,connected_transactions:rows.filter(row=>row.booking_id === payment.booking_id && row.id !== payment.id).map(row=>({...row,relationship:'Same booking'})),
    refund_source_payment_id:id,can_request_refund:payment.can_request_refund,
    refund_disabled_reason:'Local visual QA only. No refund can be submitted.'}}
}
export const previewPaymentRefund = () => { throw new Error('Local QA never contacts Stripe.') }
export const requestPaymentRefund = previewPaymentRefund
export const refreshPaymentRefund = previewPaymentRefund
export const previewCheckoutCollection = previewPaymentRefund
export const collectCheckoutPayment = previewPaymentRefund
export const previewPaymentReview = previewPaymentRefund
export const savePaymentReview = previewPaymentRefund
export const apiFetch = async () => ({success:true,data:{mode:'workspace',production_ready:false,refunds_ready:false,checks:[]}})
