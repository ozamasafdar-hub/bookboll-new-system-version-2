import { readFileSync, existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const policy = JSON.parse(readFileSync(resolve(root, 'docs/payments/rules-v1.json'), 'utf8'))
const ids = policy.rules.map(rule => rule.id)
if (ids.length < 26 || !ids.includes('P17_SHARED_ONLINE_PAYMENTS') || !ids.includes('P18_CHECKOUT_BALANCES') || !ids.includes('P19_PAYMENT_REVIEW_ACTIONS') || !ids.includes('P20_SUPERADMIN_BULK_ACTIONS') || !ids.includes('P21_PAYMENT_ANALYTICS_PARITY') || !ids.includes('P22_BOOKING_ACTIVITY_PARITY') || !ids.includes('P24_MANUAL_PROVIDER_MEMBERS') || !ids.includes('P23_MEMBERSHIP_CONTACT_LISTINGS') || !ids.includes('P25_PROMOTION_FUNDING') || !ids.includes('P26_FIRST_VISIT_COMEBACK') || new Set(ids).size !== ids.length) throw new Error('Payment policy rules are missing or duplicated, including payment analytics parity.')
for (const rule of policy.rules) {
  if (!rule.rule || !rule.production_acceptance || !rule.implementation?.length || !rule.tests?.length) throw new Error(`Incomplete rule: ${rule.id}`)
  for (const file of [...rule.implementation, ...rule.tests]) {
    if (!existsSync(resolve(root, file))) throw new Error(`${rule.id} references a missing implementation or test: ${file}`)
  }
}
console.log(`Verified ${ids.length} payment rules and their implementation/test references.`)
