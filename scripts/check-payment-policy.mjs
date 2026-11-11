import { readFileSync, existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const policy = JSON.parse(readFileSync(resolve(root, 'docs/payments/rules-v1.json'), 'utf8'))
const ids = policy.rules.map(rule => rule.id)
if (ids.length < 19 || !ids.includes('P17_SHARED_ONLINE_PAYMENTS') || !ids.includes('P18_CHECKOUT_BALANCES') || !ids.includes('P19_PAYMENT_REVIEW_ACTIONS') || new Set(ids).size !== ids.length) throw new Error('Payment policy rules are missing or duplicated, including audited payment review actions.')
for (const rule of policy.rules) {
  if (!rule.rule || !rule.production_acceptance || !rule.implementation?.length || !rule.tests?.length) throw new Error(`Incomplete rule: ${rule.id}`)
  for (const file of [...rule.implementation, ...rule.tests]) {
    if (!existsSync(resolve(root, file))) throw new Error(`${rule.id} references a missing implementation or test: ${file}`)
  }
}
console.log(`Verified ${ids.length} payment rules and their implementation/test references.`)
