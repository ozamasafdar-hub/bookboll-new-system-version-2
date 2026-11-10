import { ref } from 'vue'
export const perspective = ref('platform')
export const currentUser = ref({role:'super_admin'})
export const useCurrentUser = () => ({currentUser,can:()=>true})
export const usePremium = () => ({requirePremium:()=>true})
export const adminBrandVars = {}
export async function fetchPaymentsDashboard(query) {
  return (await fetch('/fixture-api?'+new URLSearchParams({...query,perspective:perspective.value}))).json()
}
