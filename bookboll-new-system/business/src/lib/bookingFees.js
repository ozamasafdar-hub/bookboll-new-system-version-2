import { ref, onMounted } from 'vue'
import { apiFetch } from './api.js'

// Published prices come from the same settings resolver as booking checkout.
export function useBookingFees() {
  const feeRates = ref(null), feeLoading = ref(false), feeError = ref('')
  const rateLabel = key => feeRates.value?.[key] == null ? '—' : Number(feeRates.value[key]) + '%'
  const shareLabel = key => feeRates.value?.[key] == null ? '—' : (100 - Number(feeRates.value[key])).toFixed(2)
  async function loadFees() {
    feeLoading.value = true; feeError.value = ''; feeRates.value = null
    try {
      const response = await apiFetch('/business/booking-fees', { token: '', timeout: 15000 })
      if (!response.success || !response.data?.rates) throw new Error('Missing rates')
      feeRates.value = response.data.rates
    } catch { feeError.value = 'Current booking fees could not be loaded.' }
    finally { feeLoading.value = false }
  }
  onMounted(loadFees)
  return { feeRates, feeLoading, feeError, loadFees, rateLabel, shareLabel }
}
